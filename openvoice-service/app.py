#!/usr/bin/env python3
"""
OpenVoice v2 Voice Conversion Service

Converts Edge TTS audio to match a target speaker's voice tone.
Used as stage 2 of the Hybrid Uzbek TTS pipeline:
  1. Edge TTS generates Uzbek speech (correct pronunciation, generic voice)
  2. OpenVoice converts it to the original speaker's voice (voice cloning)
"""

import os
import io
import hashlib
import logging
from pathlib import Path
from contextlib import asynccontextmanager

import torch
import torchaudio
from fastapi import FastAPI, HTTPException, UploadFile, File, Form
from fastapi.responses import FileResponse
from pydantic import BaseModel

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Storage paths
CACHE_PATH = Path(os.getenv("CACHE_PATH", "/app/openvoice-cache"))
CACHE_PATH.mkdir(parents=True, exist_ok=True)

# Global model and cache
tone_color_converter = None
speaker_embeddings = {}  # speaker_key -> SE tensor


def load_converter():
    """Load OpenVoice v2 ToneColorConverter."""
    global tone_color_converter

    if tone_color_converter is not None:
        return tone_color_converter

    logger.info("Loading OpenVoice v2 ToneColorConverter...")

    try:
        from openvoice.api import ToneColorConverter

        # OpenVoice v2 checkpoint path
        ckpt_path = os.getenv("OPENVOICE_CKPT", "checkpoints_v2/converter")

        device = "cuda" if torch.cuda.is_available() else "cpu"
        tone_color_converter = ToneColorConverter(
            f"{ckpt_path}/config.json", device=device
        )
        tone_color_converter.load_ckpt(f"{ckpt_path}/checkpoint.pth")

        logger.info(f"OpenVoice ToneColorConverter loaded on {device}")
        return tone_color_converter

    except Exception as e:
        logger.error(f"Failed to load OpenVoice converter: {e}")
        raise


def get_se(speaker_key: str) -> torch.Tensor:
    """Get cached speaker embedding by key."""
    if speaker_key in speaker_embeddings:
        return speaker_embeddings[speaker_key]

    # Try loading from disk cache
    cache_file = CACHE_PATH / f"{speaker_key}.pth"
    if cache_file.exists():
        se = torch.load(cache_file, map_location="cpu", weights_only=True)
        speaker_embeddings[speaker_key] = se
        logger.info(f"Loaded speaker embedding from disk: {speaker_key}")
        return se

    raise ValueError(f"Speaker embedding not found: {speaker_key}")


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Preload converter model on startup."""
    logger.info("OpenVoice Service starting - preloading model...")
    try:
        load_converter()
        logger.info("OpenVoice model preloaded successfully")
    except Exception as e:
        logger.warning(f"Model preload failed (will load on first request): {e}")
    yield
    logger.info("OpenVoice Service shutting down...")


app = FastAPI(
    title="OpenVoice Voice Conversion Service",
    version="1.0.0",
    lifespan=lifespan,
)


@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "service": "openvoice",
        "torch_version": torch.__version__,
        "cuda_available": torch.cuda.is_available(),
        "cached_speakers": len(speaker_embeddings),
    }


@app.post("/extract-se")
async def extract_se(
    audio: UploadFile = File(...),
    speaker_key: str = Form(...),
):
    """
    Extract speaker tone embedding from a reference WAV and cache it.
    Returns the speaker_key for later use in /convert.
    """
    try:
        converter = load_converter()

        # Save uploaded audio to temp file (OpenVoice needs file path)
        tmp_path = CACHE_PATH / f"tmp_ref_{speaker_key}.wav"
        audio_bytes = await audio.read()

        # Load and normalize audio: mono, 22050Hz
        audio_tensor, sr = torchaudio.load(io.BytesIO(audio_bytes))
        if sr != 22050:
            audio_tensor = torchaudio.transforms.Resample(sr, 22050)(audio_tensor)
        if audio_tensor.shape[0] > 1:
            audio_tensor = torch.mean(audio_tensor, dim=0, keepdim=True)
        torchaudio.save(str(tmp_path), audio_tensor, 22050)

        # Extract speaker embedding
        from openvoice import se_extractor
        target_se, _ = se_extractor.get_se(
            str(tmp_path), converter, vad=True
        )

        # Cache in memory and on disk
        speaker_embeddings[speaker_key] = target_se
        cache_file = CACHE_PATH / f"{speaker_key}.pth"
        torch.save(target_se, str(cache_file))

        # Cleanup temp file
        tmp_path.unlink(missing_ok=True)

        logger.info(f"Speaker embedding extracted and cached: {speaker_key}")

        return {
            "ok": True,
            "speaker_key": speaker_key,
            "cached": True,
        }

    except Exception as e:
        logger.error(f"Speaker embedding extraction failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/convert")
async def convert(
    audio: UploadFile = File(...),
    speaker_key: str = Form(...),
    tau: float = Form(0.3),
):
    """
    Convert Edge TTS audio to match the target speaker's voice.

    Args:
        audio: Edge TTS WAV file to convert
        speaker_key: Key for the cached speaker embedding (from /extract-se)
        tau: Conversion strength (0.0 = keep Edge TTS voice, 1.0 = full conversion)
    """
    try:
        converter = load_converter()
        target_se = get_se(speaker_key)

        # Save input audio to temp file
        input_hash = hashlib.md5(speaker_key.encode()).hexdigest()[:8]
        tmp_input = CACHE_PATH / f"tmp_input_{input_hash}.wav"
        tmp_output = CACHE_PATH / f"tmp_output_{input_hash}.wav"

        audio_bytes = await audio.read()

        # Load and save as proper WAV
        audio_tensor, sr = torchaudio.load(io.BytesIO(audio_bytes))
        # OpenVoice expects specific format
        if audio_tensor.shape[0] > 1:
            audio_tensor = torch.mean(audio_tensor, dim=0, keepdim=True)
        torchaudio.save(str(tmp_input), audio_tensor, sr)

        # Extract source SE from the Edge TTS audio
        from openvoice import se_extractor
        source_se, _ = se_extractor.get_se(
            str(tmp_input), converter, vad=False
        )

        # Run voice conversion
        converter.convert(
            audio_src_path=str(tmp_input),
            src_se=source_se,
            tgt_se=target_se,
            output_path=str(tmp_output),
            tau=tau,
        )

        # Cleanup input temp file
        tmp_input.unlink(missing_ok=True)

        if not tmp_output.exists() or tmp_output.stat().st_size < 1000:
            raise HTTPException(
                status_code=500, detail="Voice conversion produced invalid output"
            )

        logger.info(f"Voice conversion complete: speaker={speaker_key}, tau={tau}")

        return FileResponse(
            str(tmp_output),
            media_type="audio/wav",
            filename=f"converted_{speaker_key}.wav",
            headers={
                "X-Speaker-Key": speaker_key,
                "X-Tau": str(tau),
            },
        )

    except ValueError as e:
        raise HTTPException(status_code=404, detail=str(e))
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Voice conversion failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8005)
