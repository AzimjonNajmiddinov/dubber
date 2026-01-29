#!/usr/bin/env python3
"""
XTTS Voice Cloning Service for Professional Dubbing

This service provides:
- Voice cloning from audio samples
- Emotional TTS synthesis
- Multi-language support with natural expression
"""

import os
import io
import uuid
import hashlib
import logging
from pathlib import Path
from typing import Optional
from contextlib import asynccontextmanager

import torch
import torchaudio
from fastapi import FastAPI, HTTPException, UploadFile, File, Form
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Storage paths
STORAGE_PATH = Path(os.getenv("STORAGE_PATH", "/var/www/storage/app"))
VOICES_PATH = Path(os.getenv("VOICES_PATH", "/app/voices"))
CACHE_PATH = Path(os.getenv("CACHE_PATH", "/app/cache"))

# Ensure directories exist
VOICES_PATH.mkdir(parents=True, exist_ok=True)
CACHE_PATH.mkdir(parents=True, exist_ok=True)

# Global model instance
xtts_model = None


class SynthesizeRequest(BaseModel):
    text: str
    voice_id: str
    language: str = "uz"
    emotion: str = "neutral"
    speed: float = 1.0
    output_path: str  # Relative to STORAGE_PATH


class CloneVoiceRequest(BaseModel):
    name: str
    description: Optional[str] = None


class VoiceInfo(BaseModel):
    voice_id: str
    name: str
    language: str
    sample_path: str


def load_xtts_model():
    """Load XTTS model with lazy initialization."""
    global xtts_model

    if xtts_model is not None:
        return xtts_model

    logger.info("Loading XTTS model...")

    try:
        from TTS.api import TTS

        # Use XTTS v2 for best quality
        xtts_model = TTS("tts_models/multilingual/multi-dataset/xtts_v2")

        # Move to GPU if available
        if torch.cuda.is_available():
            xtts_model = xtts_model.to("cuda")
            logger.info("XTTS model loaded on GPU")
        else:
            logger.info("XTTS model loaded on CPU")

        return xtts_model

    except Exception as e:
        logger.error(f"Failed to load XTTS model: {e}")
        raise


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Warm up the model on startup."""
    logger.info("XTTS Service starting...")
    yield
    logger.info("XTTS Service shutting down...")


app = FastAPI(
    title="XTTS Voice Cloning Service",
    description="Professional voice cloning and emotional TTS for movie dubbing",
    version="1.0.0",
    lifespan=lifespan,
)


@app.get("/health")
async def health():
    """Health check endpoint."""
    return {
        "status": "healthy",
        "torch_version": torch.__version__,
        "cuda_available": torch.cuda.is_available(),
        "cuda_device": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
    }


@app.get("/ready")
async def ready():
    """Readiness check - ensures model is loaded."""
    try:
        model = load_xtts_model()
        return {
            "status": "ready",
            "model": "xtts_v2",
            "device": "cuda" if torch.cuda.is_available() else "cpu",
        }
    except Exception as e:
        raise HTTPException(status_code=503, detail=f"Model not ready: {e}")


@app.get("/voices")
async def list_voices():
    """List all available cloned voices."""
    voices = []

    for voice_dir in VOICES_PATH.iterdir():
        if voice_dir.is_dir():
            meta_file = voice_dir / "meta.json"
            sample_file = voice_dir / "sample.wav"

            if sample_file.exists():
                import json
                meta = {}
                if meta_file.exists():
                    with open(meta_file) as f:
                        meta = json.load(f)

                voices.append({
                    "voice_id": voice_dir.name,
                    "name": meta.get("name", voice_dir.name),
                    "description": meta.get("description", ""),
                    "language": meta.get("language", "multi"),
                    "created_at": meta.get("created_at", ""),
                })

    return {"voices": voices}


@app.post("/clone")
async def clone_voice(
    audio: UploadFile = File(...),
    name: str = Form(...),
    description: str = Form(""),
    language: str = Form("uz"),
):
    """Clone a voice from an audio sample."""
    try:
        # Generate unique voice ID
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        # Save the audio sample
        sample_path = voice_dir / "sample.wav"

        # Read and process the uploaded audio
        audio_bytes = await audio.read()

        # Convert to proper format (16kHz mono WAV)
        audio_tensor, sr = torchaudio.load(io.BytesIO(audio_bytes))

        # Resample to 22050Hz (XTTS native)
        if sr != 22050:
            resampler = torchaudio.transforms.Resample(sr, 22050)
            audio_tensor = resampler(audio_tensor)

        # Convert to mono if stereo
        if audio_tensor.shape[0] > 1:
            audio_tensor = torch.mean(audio_tensor, dim=0, keepdim=True)

        # Save processed sample
        torchaudio.save(str(sample_path), audio_tensor, 22050)

        # Save metadata
        import json
        from datetime import datetime

        meta = {
            "name": name,
            "description": description,
            "language": language,
            "created_at": datetime.now().isoformat(),
            "sample_duration": audio_tensor.shape[1] / 22050,
        }

        with open(voice_dir / "meta.json", "w") as f:
            json.dump(meta, f, indent=2)

        logger.info(f"Voice cloned: {voice_id} ({name})")

        return {
            "ok": True,
            "voice_id": voice_id,
            "name": name,
            "message": "Voice cloned successfully",
        }

    except Exception as e:
        logger.error(f"Voice cloning failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """Synthesize speech using a cloned voice."""
    try:
        model = load_xtts_model()

        # Get voice sample
        voice_dir = VOICES_PATH / request.voice_id
        sample_path = voice_dir / "sample.wav"

        if not sample_path.exists():
            raise HTTPException(status_code=404, detail=f"Voice not found: {request.voice_id}")

        # Output path
        output_path = STORAGE_PATH / request.output_path
        output_path.parent.mkdir(parents=True, exist_ok=True)

        # Map language codes
        lang_map = {
            "uz": "tr",  # Use Turkish as closest for Uzbek
            "ru": "ru",
            "en": "en",
            "tr": "tr",
            "ar": "ar",
            "zh": "zh-cn",
            "ja": "ja",
            "ko": "ko",
            "es": "es",
            "fr": "fr",
            "de": "de",
            "it": "it",
            "pt": "pt",
            "pl": "pl",
            "hi": "hi",
        }

        language = lang_map.get(request.language, "en")

        # Apply emotion modifiers to text (XTTS responds to punctuation and context)
        text = apply_emotion_markers(request.text, request.emotion)

        logger.info(f"Synthesizing: voice={request.voice_id}, lang={language}, emotion={request.emotion}")

        # Generate speech
        model.tts_to_file(
            text=text,
            speaker_wav=str(sample_path),
            language=language,
            file_path=str(output_path),
            speed=request.speed,
        )

        if not output_path.exists() or output_path.stat().st_size < 1000:
            raise HTTPException(status_code=500, detail="Synthesis produced invalid output")

        logger.info(f"Synthesis complete: {output_path}")

        return {
            "ok": True,
            "output_path": request.output_path,
            "size": output_path.stat().st_size,
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Synthesis failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    """Delete a cloned voice."""
    voice_dir = VOICES_PATH / voice_id

    if not voice_dir.exists():
        raise HTTPException(status_code=404, detail="Voice not found")

    import shutil
    shutil.rmtree(voice_dir)

    logger.info(f"Voice deleted: {voice_id}")

    return {"ok": True, "message": "Voice deleted"}


def apply_emotion_markers(text: str, emotion: str) -> str:
    """
    Apply subtle emotion markers to guide XTTS synthesis.
    XTTS naturally responds to punctuation and sentence structure.
    """
    emotion = emotion.lower()

    if emotion in ("happy", "excited"):
        # Add energy through punctuation
        if not text.endswith(("!", "?", "...")):
            text = text.rstrip(".") + "!"

    elif emotion == "sad":
        # Slower, trailing delivery
        if not text.endswith("..."):
            text = text.rstrip(".!") + "..."

    elif emotion == "angry":
        # Forceful ending
        if not text.endswith("!"):
            text = text.rstrip(".") + "!"

    elif emotion == "surprise":
        # Questioning/exclamatory
        if not text.endswith(("!", "?")):
            text = text.rstrip(".") + "?!"

    elif emotion == "fear":
        # Hesitant, trailing
        if not text.endswith("..."):
            text = text.rstrip(".!") + "..."

    return text


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
