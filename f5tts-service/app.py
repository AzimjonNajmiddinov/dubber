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

    ckpt_file = next((p for p in [
        "/root/f5tts-uz-finetuned/model_last.pt",
        "/workspace/f5tts-uz-finetuned/model_last.pt",
    ] if Path(p).exists()), None) or "/workspace/f5tts-uz-finetuned/model_last.pt"
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
    ref_text: Optional[str] = Form(None),
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

        # Clip to 12s max — F5-TTS clips internally anyway, and ref_text must match the clipped audio
        max_samples = 12 * 24000
        if len(audio_np) > max_samples:
            audio_np = audio_np[:max_samples]
            logger.info(f"Reference audio clipped to 12s")

        sample_path = voice_dir / "sample.wav"
        sf.write(str(sample_path), audio_np, 24000)

        # Use manually provided ref_text if given, otherwise fall back to WhisperX
        if ref_text and ref_text.strip():
            ref_text = ref_text.strip()
            logger.info(f"Using manual ref_text: {ref_text!r}")
        else:
            ref_text = "salom"  # fallback placeholder
            try:
                import httpx
                with open(sample_path, "rb") as f:
                    resp = httpx.post(
                        "http://localhost:8002/analyze-upload",
                        files={"audio": ("sample.wav", f, "audio/wav")},
                        data={"lite": "1", "language": "uz"},
                        timeout=60,
                    )
                if resp.status_code == 200:
                    segments = resp.json().get("segments", [])
                    raw_text = " ".join(s.get("text", "") for s in segments).strip()
                    import re
                    normalized = re.sub(r"[^a-zоʻгʻ\s]", "", raw_text.lower()).strip()
                    ref_text = normalized if len(normalized) >= 3 else "salom"
                    logger.info(f"WhisperX raw: {raw_text!r} → ref_text: {ref_text!r}")
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
        return {"ok": True, "voice_id": voice_id, "name": name, "ref_text": ref_text}

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

        # Split text into sentences ourselves to avoid F5-TTS internal batch
        # concatenation bug (tensor size mismatch across chunks).
        import re as _re
        sentences = [s.strip() for s in _re.split(r'(?<=[.!?])\s+', request.text) if s.strip()]
        if not sentences:
            sentences = [request.text]

        # Clear stale text_embed cache — F5-TTS caches text_cond/text_uncond on the
        # transformer per-call but never clears between calls. Different texts have
        # different seq_len → stale cache causes tensor size mismatch on 2nd+ synthesis.
        try:
            model.ema_model.transformer.clear_cache()
        except AttributeError:
            try:
                model.model.transformer.clear_cache()
            except AttributeError:
                pass

        wav_parts = []
        sr = 24000
        for sent in sentences:
            w, sr, _ = model.infer(
                ref_file=str(sample_path),
                ref_text=ref_text,
                gen_text=sent,
                speed=request.speed,
                nfe_step=32,
                remove_silence=False,
            )
            if isinstance(w, torch.Tensor):
                w = w.squeeze().cpu().numpy()
            wav_parts.append(w)

        wav_np = np.concatenate(wav_parts) if len(wav_parts) > 1 else wav_parts[0]

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
        import traceback
        logger.error(f"Synthesis failed: {e}\n{traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=str(e))


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    import shutil
    voice_dir = VOICES_PATH / voice_id
    if voice_dir.exists():
        shutil.rmtree(voice_dir)
    return {"ok": True}
