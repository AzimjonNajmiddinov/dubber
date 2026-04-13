import logging
import os
import time
from collections import Counter, defaultdict, deque
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import PlainTextResponse
from pydantic import BaseModel, Field

try:
    from .config import DEFAULT_PROFILE, DEFAULT_SOURCE_LANGUAGE, SUPPORTED_CONTEXTS, SUPPORTED_SOURCE_LANGUAGES
    from .service import TranslationService
except ImportError:
    from config import DEFAULT_PROFILE, DEFAULT_SOURCE_LANGUAGE, SUPPORTED_CONTEXTS, SUPPORTED_SOURCE_LANGUAGES
    from service import TranslationService


logging.basicConfig(level=os.getenv("UZBEKDUBLYAJ_LOG_LEVEL", "INFO"))
logger = logging.getLogger("uzbekdublyaj.api")


class TranslateRequest(BaseModel):
    text: str = Field(..., min_length=1, description="Source text to translate into Uzbek.")
    source_language: str = Field(
        default=DEFAULT_SOURCE_LANGUAGE,
        description="Source language code such as 'en' or 'ru'.",
    )
    context: str = Field(
        default="work",
        description="Tone hint. Used directly by chat-style profiles and validated for all profiles.",
    )


class TranslateResponse(BaseModel):
    final_answer: str
    profile: str


class HealthResponse(BaseModel):
    status: str
    profile: str
    device: str


class DubSegmentInput(BaseModel):
    text: str = Field(..., description="Source text for this segment.")
    speaker: str = Field(default="M1", description="Speaker tag: M1, F1, C1, etc.")
    duration: float = Field(..., gt=0, description="Available time slot in seconds.")


class TranslateDubRequest(BaseModel):
    segments: list[DubSegmentInput] = Field(..., min_length=1, max_length=20)
    source_language: str = Field(default=DEFAULT_SOURCE_LANGUAGE)
    scene_context: str = Field(default="", description="Surrounding dialogue for scene coherence.")


class DubSegmentOutput(BaseModel):
    speaker: str
    text: str


class TranslateDubResponse(BaseModel):
    translations: list[DubSegmentOutput]
    profile: str


PROFILE_NAME = os.getenv("UZBEKDUBLYAJ_PROFILE", DEFAULT_PROFILE)
API_KEY = os.getenv("UZBEKDUBLYAJ_API_KEY", "").strip()
RATE_LIMIT_REQUESTS = int(os.getenv("UZBEKDUBLYAJ_RATE_LIMIT_REQUESTS", "60"))
RATE_LIMIT_WINDOW_SECONDS = int(os.getenv("UZBEKDUBLYAJ_RATE_LIMIT_WINDOW_SECONDS", "60"))

translation_service = TranslationService(PROFILE_NAME)
request_windows: dict[str, deque[float]] = defaultdict(deque)
metrics = Counter()


def metrics_text() -> str:
    lines = [
        "# HELP uzbekdublyaj_requests_total Total HTTP requests handled.",
        "# TYPE uzbekdublyaj_requests_total counter",
        f"uzbekdublyaj_requests_total {metrics['requests_total']}",
        "# HELP uzbekdublyaj_translate_requests_total Total translation requests handled.",
        "# TYPE uzbekdublyaj_translate_requests_total counter",
        f"uzbekdublyaj_translate_requests_total {metrics['translate_requests_total']}",
        "# HELP uzbekdublyaj_errors_total Total API errors.",
        "# TYPE uzbekdublyaj_errors_total counter",
        f"uzbekdublyaj_errors_total {metrics['errors_total']}",
        "# HELP uzbekdublyaj_rate_limited_total Total rate-limited requests.",
        "# TYPE uzbekdublyaj_rate_limited_total counter",
        f"uzbekdublyaj_rate_limited_total {metrics['rate_limited_total']}",
        "# HELP uzbekdublyaj_auth_failures_total Total failed auth checks.",
        "# TYPE uzbekdublyaj_auth_failures_total counter",
        f"uzbekdublyaj_auth_failures_total {metrics['auth_failures_total']}",
    ]
    return "\n".join(lines) + "\n"


def check_api_key(request: Request):
    if not API_KEY:
        return
    supplied = request.headers.get("x-api-key", "")
    if supplied != API_KEY:
        metrics["auth_failures_total"] += 1
        raise HTTPException(status_code=401, detail="Invalid or missing API key.")


def enforce_rate_limit(request: Request):
    identifier = request.headers.get("x-api-key") or request.client.host or "unknown"
    now = time.time()
    window = request_windows[identifier]
    while window and now - window[0] > RATE_LIMIT_WINDOW_SECONDS:
        window.popleft()
    if len(window) >= RATE_LIMIT_REQUESTS:
        metrics["rate_limited_total"] += 1
        raise HTTPException(status_code=429, detail="Rate limit exceeded.")
    window.append(now)


@asynccontextmanager
async def lifespan(app: FastAPI):
    translation_service.load()
    logger.info(
        "service_started profile=%s device=%s",
        translation_service.profile_name,
        translation_service.device,
    )
    yield


app = FastAPI(
    title="UzbekDublyaj API",
    version="1.1.0",
    lifespan=lifespan,
)


@app.middleware("http")
async def request_metrics_middleware(request: Request, call_next):
    started = time.time()
    metrics["requests_total"] += 1
    try:
        response = await call_next(request)
    except Exception:
        metrics["errors_total"] += 1
        logger.exception("request_failed method=%s path=%s", request.method, request.url.path)
        raise
    duration_ms = (time.time() - started) * 1000
    logger.info(
        "request_complete method=%s path=%s status=%s duration_ms=%.2f",
        request.method,
        request.url.path,
        response.status_code,
        duration_ms,
    )
    return response


@app.get("/health", response_model=HealthResponse)
def health():
    return HealthResponse(
        status="ok",
        profile=translation_service.profile_name,
        device=translation_service.device,
    )


@app.get("/metrics", response_class=PlainTextResponse)
def get_metrics(request: Request):
    check_api_key(request)
    return PlainTextResponse(metrics_text())


@app.post("/translate_dub", response_model=TranslateDubResponse)
def translate_dub(request: TranslateDubRequest, raw_request: Request):
    try:
        check_api_key(raw_request)
        enforce_rate_limit(raw_request)
        if request.source_language not in SUPPORTED_SOURCE_LANGUAGES:
            supported = ", ".join(SUPPORTED_SOURCE_LANGUAGES)
            raise ValueError(
                f"Unsupported source_language '{request.source_language}'. Expected one of: {supported}"
            )
        metrics["translate_requests_total"] += 1
        translations = translation_service.translate_dub(
            segments=request.segments,
            source_language=request.source_language,
            scene_context=request.scene_context,
        )
        return TranslateDubResponse(translations=translations, profile=translation_service.profile_name)
    except HTTPException:
        raise
    except ValueError as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except FileNotFoundError as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=500, detail=f"Dubbing translation failed: {exc}") from exc


@app.post("/translate", response_model=TranslateResponse)
def translate(request: TranslateRequest, raw_request: Request):
    try:
        check_api_key(raw_request)
        enforce_rate_limit(raw_request)
        if request.context not in SUPPORTED_CONTEXTS:
            supported = ", ".join(SUPPORTED_CONTEXTS)
            raise ValueError(f"Unsupported context '{request.context}'. Expected one of: {supported}")
        if request.source_language not in SUPPORTED_SOURCE_LANGUAGES:
            supported = ", ".join(SUPPORTED_SOURCE_LANGUAGES)
            raise ValueError(
                f"Unsupported source_language '{request.source_language}'. Expected one of: {supported}"
            )
        metrics["translate_requests_total"] += 1
        final_answer = translation_service.translate(
            request.text,
            request.context,
            request.source_language,
        )
        return TranslateResponse(
            final_answer=final_answer,
            profile=translation_service.profile_name,
        )
    except HTTPException:
        raise
    except ValueError as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except FileNotFoundError as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except Exception as exc:
        metrics["errors_total"] += 1
        raise HTTPException(status_code=500, detail=f"Translation failed: {exc}") from exc
