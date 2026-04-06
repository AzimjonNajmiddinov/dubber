"""
F5-TTS service — drop-in replacement for xtts-service.
Endpoints match XttsClient interface in Laravel.

Ports: 8004
"""

import io
import os
import uuid
import logging
import hashlib
from pathlib import Path

import math
import numpy as np
import torch
import soundfile as sf
from scipy.signal import resample_poly
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel
from typing import Optional

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Force soundfile backend for torchaudio — no ffmpeg/torchcodec needed for WAV files.
import torchaudio as _torchaudio
try:
    _torchaudio.set_audio_backend("soundfile")
    logger.info("torchaudio backend set to soundfile")
except Exception as e:
    logger.warning(f"set_audio_backend failed: {e}")

# Patch torchaudio.load as belt-and-suspenders fallback
_orig_torchaudio_load = _torchaudio.load
def _soundfile_load(filepath, *args, **kwargs):
    logger.info(f"[patch] torchaudio.load: {filepath}")
    try:
        data, sr = sf.read(str(filepath), always_2d=True)
        return torch.from_numpy(data.T.copy()).float(), sr
    except Exception as e:
        logger.error(f"[patch] soundfile.read failed: {e}")
        raise  # don't fall back to ffmpeg-dependent orig
_torchaudio.load = _soundfile_load
logger.info("torchaudio.load patched to soundfile")

app = FastAPI()

VOICES_PATH = Path("/workspace/f5tts-voices")
CACHE_PATH  = Path("/tmp/f5tts-cache")
VOICES_PATH.mkdir(parents=True, exist_ok=True)
CACHE_PATH.mkdir(parents=True, exist_ok=True)

_f5tts_model = None


def load_model():
    global _f5tts_model
    if _f5tts_model is not None:
        return _f5tts_model

    logger.info("Loading F5-TTS model...")
    from f5_tts.api import F5TTS
    device = "cuda" if torch.cuda.is_available() else "cpu"

    ckpt_file = "/workspace/f5tts-uz-finetuned/model_last.pt"
    vocab_file = "/workspace/f5tts-uz-data_char/vocab.txt"

    if Path(ckpt_file).exists() and Path(vocab_file).exists():
        logger.info(f"Loading fine-tuned Uzbek checkpoint: {ckpt_file}")
        _f5tts_model = F5TTS(device=device, ckpt_file=ckpt_file, vocab_file=vocab_file)
    else:
        logger.warning(f"Fine-tuned checkpoint not found, loading base model")
        _f5tts_model = F5TTS(device=device)

    logger.info(f"F5-TTS loaded on {device}")
    return _f5tts_model


class SynthesizeRequest(BaseModel):
    text: str
    voice_id: str
    language: str = "uz"
    emotion: str = "neutral"
    speed: float = 1.0
    output_path: Optional[str] = None


@app.on_event("startup")
async def startup_event():
    import asyncio
    asyncio.create_task(asyncio.to_thread(load_model))


@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "cuda_available": torch.cuda.is_available(),
        "model": "f5-tts",
    }


@app.get("/ready")
async def ready():
    load_model()
    return {"status": "ready", "model": "f5-tts"}


@app.get("/voices")
async def list_voices():
    voices = []
    for voice_dir in VOICES_PATH.iterdir():
        if voice_dir.is_dir():
            meta_path = voice_dir / "meta.json"
            if meta_path.exists():
                import json
                meta = json.loads(meta_path.read_text())
                voices.append({"voice_id": voice_dir.name, **meta, "cached": True})
    return {"voices": voices}


@app.post("/clone")
async def clone_voice(
    audio: UploadFile = File(...),
    name: str = Form(...),
    description: str = Form(""),
    language: str = Form("uz"),
):
    """Store reference audio for voice cloning."""
    try:
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        audio_bytes = await audio.read()
        audio_np, sr = sf.read(io.BytesIO(audio_bytes), always_2d=False)

        # Mono
        if audio_np.ndim > 1:
            audio_np = audio_np.mean(axis=1)

        # Resample to 24000Hz (F5-TTS native)
        if sr != 24000:
            gcd = math.gcd(sr, 24000)
            audio_np = resample_poly(audio_np, 24000 // gcd, sr // gcd).astype(np.float32)

        sample_path = voice_dir / "sample.wav"
        sf.write(str(sample_path), audio_np, 24000)

        # Transcribe reference audio via WhisperX (already running on port 8002)
        # Cached so synthesis never needs to run Whisper itself
        ref_text = " "  # fallback: skip f5-tts internal transcription
        try:
            import httpx
            with open(sample_path, "rb") as f:
                resp = httpx.post(
                    "http://localhost:8002/analyze-upload",
                    files={"audio": ("sample.wav", f, "audio/wav")},
                    data={"lite": "1"},
                    timeout=60,
                )
            if resp.status_code == 200:
                segments = resp.json().get("segments", [])
                raw_text = " ".join(s.get("text", "") for s in segments).strip()
                # Filter to only chars in our Uzbek vocab — WhisperX may transcribe
                # in a wrong language (Turkish/Azerbaijani) with chars outside our vocab.
                # Unknown chars all map to index 0 (space), breaking F5-TTS alignment.
                vocab_path = Path("/workspace/f5tts-uz-data_char/vocab.txt")
                if vocab_path.exists():
                    known = set(vocab_path.read_text().splitlines())
                    filtered = "".join(c for c in raw_text if c in known)
                    ref_text = filtered.strip() or " "
                else:
                    ref_text = raw_text or " "
                logger.info(f"Reference transcribed via WhisperX: {raw_text!r} → filtered: {ref_text!r}")
        except Exception as e:
            logger.warning(f"WhisperX transcription failed, using fallback: {e}")

        import json
        from datetime import datetime
        meta = {
            "name": name,
            "description": description,
            "language": language,
            "ref_text": ref_text,
            "created_at": datetime.now().isoformat(),
        }
        (voice_dir / "meta.json").write_text(json.dumps(meta, indent=2))

        logger.info(f"Voice stored: {voice_id} ({name})")
        return {"ok": True, "voice_id": voice_id, "name": name}

    except Exception as e:
        logger.error(f"Clone failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """Synthesize text using a cloned voice reference."""
    try:
        model = load_model()

        voice_dir = VOICES_PATH / request.voice_id
        sample_path = voice_dir / "sample.wav"
        if not sample_path.exists():
            raise HTTPException(status_code=404, detail=f"Voice {request.voice_id} not found")

        output_path = CACHE_PATH / f"{uuid.uuid4()}.wav"

        # Load cached ref_text to skip re-transcription on every call
        import json as _json
        meta_path = voice_dir / "meta.json"
        ref_text = " "  # fallback: space skips f5-tts internal whisper
        if meta_path.exists():
            ref_text = _json.loads(meta_path.read_text()).get("ref_text", " ") or " "

        logger.info(f"Synthesizing {len(request.text)} chars with voice={request.voice_id}, speed={request.speed}")

        wav, sr, _ = model.infer(
            ref_file=str(sample_path),
            ref_text=ref_text,
            gen_text=request.text,
            speed=request.speed,
            nfe_step=32,
            remove_silence=False,
        )

        # Save output
        if isinstance(wav, torch.Tensor):
            wav_np = wav.squeeze().cpu().numpy()
        else:
            wav_np = wav

        sf.write(str(output_path), wav_np, sr)

        if not output_path.exists() or output_path.stat().st_size < 1000:
            raise HTTPException(status_code=500, detail="Synthesis produced invalid output")

        logger.info(f"Synthesis complete: {output_path}")

        import asyncio
        response = FileResponse(
            str(output_path),
            media_type="audio/wav",
            filename=output_path.name,
        )
        async def _cleanup():
            await asyncio.sleep(60)
            output_path.unlink(missing_ok=True)
        asyncio.create_task(_cleanup())
        return response

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Synthesis failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    import shutil
    voice_dir = VOICES_PATH / voice_id
    if voice_dir.exists():
        shutil.rmtree(voice_dir)
    return {"ok": True}
