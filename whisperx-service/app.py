from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel
import tempfile
import shutil
import logging

import os

# Configure logging to show in uvicorn
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)
import traceback
import threading
from typing import Optional, Dict, Any, Tuple

import numpy as np
import soundfile as sf
import librosa
import torch

# Heavy imports are kept, but model construction is lazy.
from faster_whisper import WhisperModel
import whisperx
from whisperx.diarize import DiarizationPipeline
from transformers import AutoFeatureExtractor, Wav2Vec2ForSequenceClassification
from speechbrain.inference.classifiers import EncoderClassifier

# ---------------- CONFIG ----------------
DEVICE = os.environ.get("DEVICE", "cpu")
BASE_DIR = "/var/www/storage/app"
HF_TOKEN = os.environ.get("HF_TOKEN")
if not HF_TOKEN:
    logger.warning("HF_TOKEN not set - speaker diarization will be disabled")

# Login to HuggingFace Hub so all downloads use the token automatically
# (avoids deprecated use_auth_token parameter)
if HF_TOKEN:
    try:
        from huggingface_hub import login as hf_login
        hf_login(token=HF_TOKEN, add_to_git_credential=False)
        logger.info("HuggingFace Hub login successful")
    except Exception as e:
        logger.warning(f"HuggingFace Hub login failed: {e}")

# You can override these via env if you want
WHISPER_MODEL_NAME = os.environ.get("WHISPER_MODEL", "base")
WHISPER_COMPUTE_TYPE = os.environ.get("WHISPER_COMPUTE_TYPE", "int8")
DIARIZATION_MODEL = os.environ.get("DIARIZATION_MODEL", "pyannote/speaker-diarization-3.1")
GENDER_MODEL_ID = os.environ.get(
    "GENDER_MODEL_ID",
    "alefiury/wav2vec2-large-xlsr-53-gender-recognition-librispeech",
)
EMOTION_MODEL_ID = os.environ.get(
    "EMOTION_MODEL_ID",
    "speechbrain/emotion-recognition-wav2vec2-IEMOCAP",
)

# Optional toggles (to reduce load if needed)
ENABLE_DIARIZATION = os.environ.get("ENABLE_DIARIZATION", "1") == "1" and bool(HF_TOKEN)
ENABLE_GENDER = os.environ.get("ENABLE_GENDER", "1") == "1"
ENABLE_EMOTION = os.environ.get("ENABLE_EMOTION", "1") == "1"

app = FastAPI()

# ---------------- STATE (lazy singletons) ----------------
_state_lock = threading.Lock()

_whisper_model: Optional[WhisperModel] = None
_diarize_pipeline: Optional[DiarizationPipeline] = None
_align_model: Optional[Any] = None
_align_metadata: Optional[Dict] = None
_align_language: Optional[str] = None

_gender_ok: bool = False
_gender_extractor: Optional[Any] = None
_gender_model: Optional[Any] = None

_emotion_ok: bool = False
_emotion_clf: Optional[Any] = None


# ---------------- SCHEMA ----------------
class AnalyzeRequest(BaseModel):
    audio_path: str
    min_speakers: Optional[int] = None
    max_speakers: Optional[int] = None
    lite: Optional[int] = None


# ---------------- HELPERS ----------------
def resolve_audio_path(p: str) -> str:
    if not p:
        raise HTTPException(status_code=400, detail="audio_path is required")

    if os.path.isabs(p):
        audio_path = os.path.abspath(p)
    else:
        audio_path = os.path.abspath(os.path.join(BASE_DIR, p))

    if not audio_path.startswith(os.path.abspath(BASE_DIR)):
        raise HTTPException(status_code=400, detail="Invalid path")

    if not os.path.exists(audio_path):
        raise HTTPException(status_code=404, detail="File not found")

    return audio_path


def load_audio_mono_16k(path: str):
    y, sr = librosa.load(path, sr=16000, mono=True)
    return y.astype(np.float32), 16000


def concat_speaker_audio(full_y, sr, speaker_rows, min_total_sec=1.2, max_total_sec=25.0):
    clips = []
    total = 0.0
    for r in speaker_rows:
        st = float(r["start"])
        en = float(r["end"])
        if en <= st:
            continue
        a = int(st * sr)
        b = int(en * sr)
        if a >= len(full_y):
            continue
        b = min(b, len(full_y))
        clip = full_y[a:b]
        if clip.size == 0:
            continue
        clips.append(clip)
        total += (b - a) / sr
        if total >= max_total_sec:
            break
    if total < min_total_sec:
        return None
    return np.concatenate(clips)


def estimate_age_group_from_pitch(y_16k: np.ndarray, sr: int = 16000):
    try:
        f0, voiced_flag, voiced_prob = librosa.pyin(
            y_16k, fmin=50, fmax=500, sr=sr, frame_length=2048, hop_length=256
        )
        f0v = f0[~np.isnan(f0)]
        if f0v.size < 20:
            return "unknown", None
        med = float(np.median(f0v))
        if med >= 250:
            return "child", med
        if 180 <= med < 250:
            return "young_adult", med
        if 120 <= med < 180:
            return "adult", med
        return "senior", med
    except Exception:
        return "unknown", None


# ---------------- LAZY MODEL INIT ----------------
def get_whisper_model() -> WhisperModel:
    global _whisper_model
    if _whisper_model is not None:
        return _whisper_model
    with _state_lock:
        if _whisper_model is None:
            _whisper_model = WhisperModel(
                WHISPER_MODEL_NAME,
                device=DEVICE,
                compute_type=WHISPER_COMPUTE_TYPE,
            )
    return _whisper_model


def get_diarize_pipeline() -> Optional[DiarizationPipeline]:
    global _diarize_pipeline
    if not ENABLE_DIARIZATION:
        return None
    if _diarize_pipeline is not None:
        return _diarize_pipeline
    with _state_lock:
        if _diarize_pipeline is None:
            # Try standard init first, fall back to manual pipeline creation
            # (newer huggingface_hub removed use_auth_token parameter)
            try:
                _diarize_pipeline = DiarizationPipeline(
                    model_name=DIARIZATION_MODEL,
                    use_auth_token=HF_TOKEN,
                    device=DEVICE,
                )
            except TypeError:
                logger.info("Falling back to manual diarization pipeline init (use_auth_token deprecated)")
                try:
                    from pyannote.audio import Pipeline as PyannotePipeline
                    pipe = PyannotePipeline.from_pretrained(DIARIZATION_MODEL)
                    pipe = pipe.to(torch.device(DEVICE))
                    dp = object.__new__(DiarizationPipeline)
                    dp.model = pipe
                    dp.device = DEVICE
                    _diarize_pipeline = dp
                except Exception as e:
                    logger.error(f"Diarization pipeline fallback also failed: {e}")
                    return None
            except Exception as e:
                logger.error(f"Diarization pipeline init failed: {e}")
                return None

            # Raise clustering threshold: less sensitive to within-speaker variation,
            # prevents monologue from being split into multiple "speakers".
            # Default ~0.7 → 0.85 merges more aggressively.
            try:
                pipe = _diarize_pipeline.model if hasattr(_diarize_pipeline, 'model') else None
                if pipe is not None and hasattr(pipe, 'klustering'):
                    pipe.klustering.threshold = float(os.environ.get("DIARIZE_THRESHOLD", "0.85"))
                    logger.info(f"Clustering threshold set to {pipe.klustering.threshold}")
            except Exception as e:
                logger.warning(f"Could not set clustering threshold: {e}")
    return _diarize_pipeline


def get_align_model(language_code: str):
    """Lazy-load whisperx alignment model. Reloads if language changes."""
    global _align_model, _align_metadata, _align_language
    if _align_model is not None and _align_language == language_code:
        return _align_model, _align_metadata
    with _state_lock:
        if _align_model is not None and _align_language == language_code:
            return _align_model, _align_metadata
        try:
            print(f"[ALIGN] Loading alignment model for '{language_code}' on {DEVICE}", flush=True)
            _align_model, _align_metadata = whisperx.load_align_model(
                language_code=language_code,
                device=DEVICE,
            )
            _align_language = language_code
            print(f"[ALIGN] Model loaded successfully", flush=True)
        except Exception as e:
            print(f"[ALIGN] Failed to load alignment model: {e}", flush=True)
            _align_model = None
            _align_metadata = None
            _align_language = None
    return _align_model, _align_metadata


def init_gender_model_if_needed() -> Tuple[bool, Optional[str]]:
    global _gender_ok, _gender_extractor, _gender_model
    if not ENABLE_GENDER:
        _gender_ok = False
        return False, "disabled"

    if _gender_ok and _gender_extractor is not None and _gender_model is not None:
        return True, None

    with _state_lock:
        if _gender_ok and _gender_extractor is not None and _gender_model is not None:
            return True, None
        try:
            _gender_extractor = AutoFeatureExtractor.from_pretrained(GENDER_MODEL_ID)
            _gender_model = Wav2Vec2ForSequenceClassification.from_pretrained(GENDER_MODEL_ID)
            _gender_model.eval()
            _gender_ok = True
            return True, None
        except Exception as e:
            _gender_ok = False
            _gender_extractor = None
            _gender_model = None
            return False, str(e)


def init_emotion_model_if_needed() -> Tuple[bool, Optional[str]]:
    global _emotion_ok, _emotion_clf
    if not ENABLE_EMOTION:
        _emotion_ok = False
        return False, "disabled"

    if _emotion_ok and _emotion_clf is not None:
        return True, None

    with _state_lock:
        if _emotion_ok and _emotion_clf is not None:
            return True, None
        try:
            _emotion_clf = EncoderClassifier.from_hparams(
                source=EMOTION_MODEL_ID,
                run_opts={"device": DEVICE},
            )
            _emotion_ok = True
            return True, None
        except Exception as e:
            _emotion_ok = False
            _emotion_clf = None
            return False, str(e)


def predict_gender(y_16k: np.ndarray, sr: int = 16000):
    ok, err = init_gender_model_if_needed()
    if not ok:
        return "unknown", None

    try:
        inputs = _gender_extractor(y_16k, sampling_rate=sr, return_tensors="pt", padding=True)
        with torch.no_grad():
            logits = _gender_model(**inputs).logits
            probs = torch.softmax(logits, dim=-1).cpu().numpy()[0]
        best = int(np.argmax(probs))
        conf = float(probs[best])

        id2label = getattr(_gender_model.config, "id2label", {}) or {}
        label = str(id2label.get(best, "unknown")).lower()

        if "female" in label or label in ("f", "woman"):
            return "female", conf
        if "male" in label or label in ("m", "man"):
            return "male", conf

        # fallback
        if best == 0:
            return "female", conf
        if best == 1:
            return "male", conf

        return "unknown", conf
    except Exception:
        return "unknown", None


def predict_emotion(tmp_wav_path: str):
    ok, err = init_emotion_model_if_needed()
    if not ok:
        return "neutral", None

    try:
        out_prob, score, index, text_lab = _emotion_clf.classify_file(tmp_wav_path)
        lab = text_lab[0] if isinstance(text_lab, (list, tuple)) and len(text_lab) else str(text_lab)
        lab = str(lab).lower()

        mapping = {
            "neu": "neutral",
            "hap": "happy",
            "sad": "sad",
            "ang": "angry",
            "fea": "fear",
            "exc": "excited",
            "fru": "frustration",
            "sur": "surprise",
        }
        emotion = mapping.get(lab, lab)

        conf = None
        try:
            if hasattr(out_prob, "max"):
                conf = float(out_prob.max().item())
        except Exception:
            conf = None

        return emotion, conf
    except Exception:
        return "neutral", None


# Semaphore allows 2 concurrent GPU operations on RTX 3090 (24GB VRAM).
# Models are shared singletons; only per-request tensors use extra VRAM (~1-2GB each).
_analyze_semaphore = threading.Semaphore(2)


@app.get("/health")
def health():
    # Lightweight: do NOT trigger heavy model loads.
    return {
        "ok": True,
        "device": DEVICE,
        "enable_diarization": ENABLE_DIARIZATION,
        "enable_gender": ENABLE_GENDER,
        "enable_emotion": ENABLE_EMOTION,
        "whisper_loaded": _whisper_model is not None,
        "align_loaded": _align_model is not None,
        "align_language": _align_language,
        "diarize_loaded": _diarize_pipeline is not None,
        "gender_loaded": _gender_ok,
        "emotion_loaded": _emotion_ok,
    }


@app.get("/ready")
def ready():
    # Heavier readiness: ensures core models can load.
    # Use this only if you want "warm" readiness checks.
    try:
        get_whisper_model()
        if ENABLE_DIARIZATION:
            get_diarize_pipeline()
        if ENABLE_GENDER:
            init_gender_model_if_needed()
        if ENABLE_EMOTION:
            init_emotion_model_if_needed()
        return {"ok": True}
    except Exception as e:
        return {"ok": False, "error": str(e)}


@app.get("/debug/diarize-check")
def debug_diarize_check():
    """Diagnostic: check diarization pipeline status without running analysis."""
    result = {
        "enable_diarization": ENABLE_DIARIZATION,
        "hf_token_set": bool(HF_TOKEN),
        "hf_token_prefix": HF_TOKEN[:8] + "..." if HF_TOKEN else None,
        "diarization_model": DIARIZATION_MODEL,
        "pipeline_loaded": _diarize_pipeline is not None,
        "pipeline_type": type(_diarize_pipeline).__name__ if _diarize_pipeline else None,
    }

    if _diarize_pipeline is not None:
        result["has_model_attr"] = hasattr(_diarize_pipeline, "model")
        if hasattr(_diarize_pipeline, "model"):
            result["model_type"] = type(_diarize_pipeline.model).__name__
    else:
        # Try to init now and report
        try:
            pipe = get_diarize_pipeline()
            result["init_result"] = "success" if pipe is not None else "returned_none"
            result["pipeline_loaded_after_init"] = pipe is not None
            if pipe is not None:
                result["pipeline_type"] = type(pipe).__name__
                result["has_model_attr"] = hasattr(pipe, "model")
        except Exception as e:
            result["init_result"] = f"error: {e}"
            result["init_traceback"] = traceback.format_exc()

    return result


def _merge_segments(segments: list, max_gap: float = 0.5) -> list:
    """Merge consecutive micro-segments with small gaps into sentence-level segments.

    whisperx.align() can split a single sentence into word-level fragments,
    producing 80+ segments for 10s of audio. This merges them back into
    natural sentence boundaries based on gap size and segment duration.
    """
    if not segments:
        return segments

    merged = [dict(segments[0])]

    for seg in segments[1:]:
        prev = merged[-1]
        gap = seg["start"] - prev["end"]
        prev_duration = prev["end"] - prev["start"]

        # Break on significant pause (>0.5s) or segment already long enough (>6s)
        if gap > max_gap or prev_duration > 6.0:
            merged.append(dict(seg))
        else:
            prev["end"] = seg["end"]
            prev["text"] = prev["text"] + " " + seg["text"]

    return merged


def _analyze_audio(audio_path: str, min_speakers: Optional[int] = None, max_speakers: Optional[int] = None, lite: bool = False, language: Optional[str] = None) -> dict:
    """Core analysis logic shared by path-based and upload endpoints."""
    # 1) TRANSCRIBE
    whisper_model = get_whisper_model()
    transcribe_kwargs = {"vad_filter": True}
    if language:
        transcribe_kwargs["language"] = language
    whisper_segments, info = whisper_model.transcribe(audio_path, **transcribe_kwargs)
    segments = [
        {"start": float(s.start), "end": float(s.end), "text": s.text.strip()}
        for s in whisper_segments
    ]

    # 2) ALIGN — fix segment timestamps using whisperx forced alignment
    language = language or getattr(info, "language", "en") or "en"
    if segments:
        try:
            align_model, align_metadata = get_align_model(language)
            if align_model is not None:
                audio_array = whisperx.load_audio(audio_path)
                aligned = whisperx.align(
                    segments, align_model, align_metadata, audio_array, DEVICE,
                )
                aligned_segs = aligned.get("segments", [])
                if aligned_segs:
                    raw_aligned = [
                        {"start": float(s["start"]), "end": float(s["end"]), "text": s.get("text", "").strip()}
                        for s in aligned_segs
                        if s.get("text", "").strip()
                    ]
                    # Merge micro-segments: whisperx.align() can split sentences into
                    # word-level fragments (80+ segments for 10s). Merge consecutive
                    # segments with gaps < 0.3s into sentence-level segments.
                    segments = _merge_segments(raw_aligned, max_gap=0.3)
                    print(f"[ALIGN] Aligned {len(aligned_segs)} -> merged to {len(segments)} segments", flush=True)
                else:
                    print("[ALIGN] Alignment returned empty, keeping raw timestamps", flush=True)
            else:
                print("[ALIGN] Alignment model not available, keeping raw timestamps", flush=True)
        except Exception as e:
            print(f"[ALIGN] Alignment failed: {e}, keeping raw timestamps", flush=True)
            traceback.print_exc()

    # 3) DIARIZE (optional - gracefully degrade if unavailable)
    # Always run diarization when enabled — lite mode only skips gender/emotion ML.
    diar_rows = []
    diarization_status = "disabled"  # Track what actually happened
    if ENABLE_DIARIZATION:
        try:
            diarize = get_diarize_pipeline()
            if diarize is not None:
                diarize_kwargs = {}
                if min_speakers is not None:
                    diarize_kwargs["min_speakers"] = min_speakers
                if max_speakers is not None:
                    diarize_kwargs["max_speakers"] = max_speakers

                # Let pyannote decide speaker count naturally.
                # Previously auto-hinted min_speakers=2 for long audio, but this
                # forced multiple speakers on single-speaker videos.
                # Callers can still pass min_speakers/max_speakers explicitly.

                print(f"[DIARIZE] Calling with kwargs: {diarize_kwargs}", flush=True)
                diarization_df = diarize(audio_path, **diarize_kwargs)
                diar_rows = diarization_df.to_dict("records")
                diarization_status = "ok"

                # Debug: log diarization results
                unique_speakers = set(r["speaker"] for r in diar_rows)
                print(f"[DIARIZE] Found {len(diar_rows)} speaker segments, {len(unique_speakers)} unique speakers: {sorted(unique_speakers)}", flush=True)
                for i, row in enumerate(diar_rows[:30]):  # limit to 30 for readability
                    print(f"  Diar[{i}]: {row['start']:.2f}-{row['end']:.2f} -> {row['speaker']}", flush=True)
            else:
                diarization_status = "pipeline_unavailable"
                print("[DIARIZE] Pipeline not available, skipping diarization", flush=True)
        except Exception as e:
            diarization_status = f"error: {e}"
            print(f"[DIARIZE] Failed: {e}, continuing without diarization", flush=True)
            traceback.print_exc()
            diar_rows = []

    # 3) Assign speaker to each segment using overlap-based matching
    final_segments = []
    print(f"[WHISPER] Found {len(segments)} transcription segments", flush=True)
    for i, seg in enumerate(segments[:30]):
        print(f"  Seg[{i}]: {seg['start']:.2f}-{seg['end']:.2f} text='{seg['text'][:50]}...'", flush=True)

    if diar_rows:
        print(f"[ASSIGN] Assigning speakers to {len(segments)} transcription segments", flush=True)
        for seg in segments:
            seg_start = float(seg["start"])
            seg_end = float(seg["end"])

            # Find speaker with maximum overlap
            best_speaker = "SPEAKER_UNKNOWN"
            best_overlap = 0.0

            for row in diar_rows:
                row_start = float(row["start"])
                row_end = float(row["end"])

                # Calculate overlap
                overlap_start = max(seg_start, row_start)
                overlap_end = min(seg_end, row_end)
                overlap = max(0.0, overlap_end - overlap_start)

                if overlap > best_overlap:
                    best_overlap = overlap
                    best_speaker = str(row["speaker"])

            # Fallback: if no overlap found, use closest speaker by midpoint
            if best_overlap == 0.0:
                seg_mid = (seg_start + seg_end) / 2
                min_dist = float("inf")
                for row in diar_rows:
                    row_mid = (float(row["start"]) + float(row["end"])) / 2
                    dist = abs(seg_mid - row_mid)
                    if dist < min_dist:
                        min_dist = dist
                        best_speaker = str(row["speaker"])
                print(f"  [ASSIGN] Seg[{seg_start:.2f}-{seg_end:.2f}] NO OVERLAP, closest={best_speaker}, min_dist={min_dist:.2f}s", flush=True)
            else:
                print(f"  [ASSIGN] Seg[{seg_start:.2f}-{seg_end:.2f}] overlap={best_overlap:.2f}s -> {best_speaker}", flush=True)

            final_segments.append({**seg, "speaker": best_speaker})
    else:
        print("[ASSIGN] No diarization rows - assigning all to SPEAKER_0", flush=True)
        for seg in segments:
            final_segments.append({**seg, "speaker": "SPEAKER_0"})

    # 4) Speaker-level meta (optional / safe defaults)
    speakers: Dict[str, Dict[str, Any]] = {}
    if diar_rows:
        full_y, sr = load_audio_mono_16k(audio_path)

        by_spk: Dict[str, list] = {}
        for r in diar_rows:
            spk = str(r.get("speaker", "SPEAKER_UNKNOWN"))
            by_spk.setdefault(spk, []).append({
                "start": float(r["start"]),
                "end": float(r["end"]),
            })

        for spk, rows in by_spk.items():
            y_spk = concat_speaker_audio(full_y, sr, rows)
            if y_spk is None:
                speakers[spk] = {
                    "gender": "unknown",
                    "gender_confidence": None,
                    "age_group": "unknown",
                    "pitch_median_hz": None,
                    "emotion": "neutral",
                    "emotion_confidence": None,
                }
                continue

            # Pitch estimation is fast (pure DSP, no ML) — always run it
            age_group, pitch_med = estimate_age_group_from_pitch(y_spk, sr=sr)

            if lite:
                # Lite mode: skip heavy ML models (gender, emotion) for speed.
                # Used for chunked processing where proxy timeouts are tight.
                # Gender is inferred cross-chunk via pitch proximity in PHP.
                gender, gconf = "unknown", None
                emotion, econf = "neutral", None
                print(f"  [LITE] Speaker {spk}: pitch={pitch_med}, age={age_group} (skipped gender/emotion)", flush=True)
            else:
                gender, gconf = predict_gender(y_spk, sr=sr) if ENABLE_GENDER else ("unknown", None)
                emotion, econf = ("neutral", None)
                if ENABLE_EMOTION:
                    tmp_path = f"/tmp/spk_{spk.replace('/', '_')}.wav"
                    sf.write(tmp_path, y_spk, sr)
                    emotion, econf = predict_emotion(tmp_path)
                    try:
                        os.remove(tmp_path)
                    except Exception:
                        pass

            speakers[spk] = {
                "gender": gender,
                "gender_confidence": gconf,
                "age_group": age_group,
                "pitch_median_hz": pitch_med,
                "emotion": emotion,
                "emotion_confidence": econf,
            }
    else:
        speakers["SPEAKER_0"] = {
            "gender": "unknown",
            "gender_confidence": None,
            "age_group": "unknown",
            "pitch_median_hz": None,
            "emotion": "neutral",
            "emotion_confidence": None,
        }

    return {
        "language": getattr(info, "language", None),
        "segments": final_segments,
        "speakers": speakers,
        "diarization_enabled": ENABLE_DIARIZATION,
        "diarization_status": diarization_status,
        "diarization_segments": len(diar_rows),
        "speakers_detected": len(speakers),
    }


@app.post("/analyze")
def analyze(req: AnalyzeRequest):
    """Analyze audio by file path (requires shared filesystem)."""
    with _analyze_semaphore:
        try:
            audio_path = resolve_audio_path(req.audio_path)
            is_lite = bool(req.lite)
            return _analyze_audio(audio_path, min_speakers=req.min_speakers, max_speakers=req.max_speakers, lite=is_lite)
        except HTTPException:
            raise
        except Exception as e:
            traceback.print_exc()
            return {"error": "whisperx_internal_error", "message": str(e)}


@app.post("/analyze-upload")
def analyze_upload(
    audio: UploadFile = File(...),
    min_speakers: Optional[int] = Form(None),
    max_speakers: Optional[int] = Form(None),
    lite: Optional[int] = Form(None),
    language: Optional[str] = Form(None),
):
    """Analyze audio via file upload (for remote clients without shared filesystem)."""
    with _analyze_semaphore:
        tmp_path = None
        try:
            # Save uploaded file to temp location
            suffix = os.path.splitext(audio.filename or "audio.wav")[1] or ".wav"
            with tempfile.NamedTemporaryFile(delete=False, suffix=suffix, dir="/tmp") as tmp:
                shutil.copyfileobj(audio.file, tmp)
                tmp_path = tmp.name

            is_lite = bool(lite)
            print(f"[UPLOAD] Received {audio.filename}, saved to {tmp_path} ({os.path.getsize(tmp_path)} bytes), lite={is_lite}, language={language}", flush=True)
            return _analyze_audio(tmp_path, min_speakers=min_speakers, max_speakers=max_speakers, lite=is_lite, language=language)
        except HTTPException:
            raise
        except Exception as e:
            traceback.print_exc()
            return {"error": "whisperx_internal_error", "message": str(e)}
        finally:
            if tmp_path and os.path.exists(tmp_path):
                try:
                    os.remove(tmp_path)
                except Exception:
                    pass
