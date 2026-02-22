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
import tempfile
from pathlib import Path
from contextlib import asynccontextmanager

import torch
import torchaudio
from fastapi import FastAPI, HTTPException, UploadFile, File, Form
from fastapi.responses import FileResponse

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


def extract_se_direct(audio_path: str, converter) -> torch.Tensor:
    """
    Extract speaker embedding directly using the converter model.
    Bypasses se_extractor to avoid whisper_timestamped/faster_whisper deps.

    Splits audio into chunks and passes them to converter.extract_se().
    """
    from pydub import AudioSegment
    from pydub.silence import detect_nonsilent

    audio = AudioSegment.from_file(audio_path)

    # Find non-silent regions to get clean speech segments
    nonsilent = detect_nonsilent(audio, min_silence_len=500, silence_thresh=-40)

    if not nonsilent:
        # Fallback: use whole audio
        nonsilent = [(0, len(audio))]

    # Merge close segments and split into 5-15s chunks
    chunks = []
    current_start, current_end = nonsilent[0]

    for start, end in nonsilent[1:]:
        if start - current_end < 1000:  # merge if gap < 1s
            current_end = end
        else:
            chunks.append((current_start, current_end))
            current_start, current_end = start, end
    chunks.append((current_start, current_end))

    # Filter to reasonable lengths (1.5s - 20s)
    valid_chunks = [(s, e) for s, e in chunks if 1500 <= (e - s) <= 20000]

    if not valid_chunks:
        # Use the longest chunk regardless
        valid_chunks = [max(chunks, key=lambda x: x[1] - x[0])]

    # Export chunks as temp WAV files
    tmp_dir = Path(tempfile.mkdtemp(prefix="openvoice_se_"))
    wav_files = []

    try:
        for i, (start, end) in enumerate(valid_chunks[:5]):  # max 5 chunks
            chunk = audio[start:end]
            chunk_path = tmp_dir / f"chunk_{i}.wav"
            chunk.export(str(chunk_path), format="wav")
            wav_files.append(str(chunk_path))

        if not wav_files:
            raise ValueError("No valid audio chunks for SE extraction")

        # Use converter's extract_se method directly
        se_path = str(tmp_dir / "se.pth")
        se = converter.extract_se(wav_files, se_save_path=se_path)

        return se

    finally:
        # Cleanup temp files
        import shutil
        shutil.rmtree(str(tmp_dir), ignore_errors=True)


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
async def extract_se_endpoint(
    audio: UploadFile = File(...),
    speaker_key: str = Form(...),
):
    """
    Extract speaker tone embedding from a reference WAV and cache it.
    Returns the speaker_key for later use in /convert.
    """
    try:
        converter = load_converter()

        # Save uploaded audio to temp file
        tmp_path = CACHE_PATH / f"tmp_ref_{speaker_key}.wav"
        audio_bytes = await audio.read()

        # Load and normalize audio: mono, 22050Hz
        audio_tensor, sr = torchaudio.load(io.BytesIO(audio_bytes))
        if sr != 22050:
            audio_tensor = torchaudio.transforms.Resample(sr, 22050)(audio_tensor)
        if audio_tensor.shape[0] > 1:
            audio_tensor = torch.mean(audio_tensor, dim=0, keepdim=True)
        torchaudio.save(str(tmp_path), audio_tensor, 22050)

        # Extract speaker embedding directly (no whisper/VAD deps needed)
        target_se = extract_se_direct(str(tmp_path), converter)

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

        # Extract source SE directly from Edge TTS audio
        source_se = extract_se_direct(str(tmp_input), converter)

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
