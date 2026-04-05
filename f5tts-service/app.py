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

# Monkey-patch torchaudio.load to use soundfile — avoids ffmpeg/torchcodec dependency.
# F5-TTS calls torchaudio.load(ref_file) internally; soundfile handles WAV without ffmpeg.
import torchaudio as _torchaudio
_orig_torchaudio_load = _torchaudio.load
def _soundfile_load(filepath, *args, **kwargs):
    try:
        data, sr = sf.read(str(filepath), always_2d=True)
        return torch.from_numpy(data.T.copy()).float(), sr
    except Exception:
        return _orig_torchaudio_load(filepath, *args, **kwargs)
_torchaudio.load = _soundfile_load
logger.info("torchaudio.load patched to use soundfile backend (no ffmpeg needed)")

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

        import json
        from datetime import datetime
        meta = {
            "name": name,
            "description": description,
            "language": language,
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
        ref_text = ""
        if meta_path.exists():
            ref_text = _json.loads(meta_path.read_text()).get("ref_text", "")

        logger.info(f"Synthesizing {len(request.text)} chars with voice={request.voice_id}, speed={request.speed}")

        wav, sr, _ = model.infer(
            ref_file=str(sample_path),
            ref_text=ref_text,
            gen_text=request.text,
            speed=request.speed,
            nfe_step=16,
            remove_silence=True,
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
