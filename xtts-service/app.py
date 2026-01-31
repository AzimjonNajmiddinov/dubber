#!/usr/bin/env python3
"""
XTTS Voice Cloning Service - OPTIMIZED FOR CPU SPEED

Optimizations:
- Cached speaker embeddings (compute once per voice)
- Chunked text synthesis (shorter texts = faster)
- Torch inference optimizations
- Direct model API access
"""

import os

# IMPORTANT: Accept Coqui TOS before importing TTS
os.environ["COQUI_TOS_AGREED"] = "1"

# Optimize CPU threading - use 2 threads for balance of speed and CPU usage
os.environ["OMP_NUM_THREADS"] = "2"
os.environ["MKL_NUM_THREADS"] = "2"

import io
import re
import uuid
import hashlib
import logging
from pathlib import Path
from typing import Optional, List
from contextlib import asynccontextmanager

import torch
import torchaudio
import numpy as np
from fastapi import FastAPI, HTTPException, UploadFile, File, Form
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

# Global model and cache
xtts_model = None
speaker_embeddings_cache = {}  # voice_id -> (gpt_cond_latent, speaker_embedding)

# Maximum characters per chunk for faster synthesis
MAX_CHUNK_CHARS = 250


class SynthesizeRequest(BaseModel):
    text: str
    voice_id: str
    language: str = "uz"
    emotion: str = "neutral"
    speed: float = 1.0
    output_path: str


def load_xtts_model():
    """Load XTTS model with optimizations."""
    global xtts_model

    if xtts_model is not None:
        return xtts_model

    logger.info("Loading XTTS model with CPU optimizations...")

    try:
        from TTS.tts.configs.xtts_config import XttsConfig
        from TTS.tts.models.xtts import Xtts
        from TTS.utils.manage import ModelManager
        from pathlib import Path

        # Get model path using ModelManager
        manager = ModelManager()

        # First ensure model is downloaded
        model_name = "tts_models/multilingual/multi-dataset/xtts_v2"
        model_item = manager.download_model(model_name)

        # Find the model directory
        model_dir = Path.home() / ".local/share/tts" / model_name.replace("/", "--")

        if not model_dir.exists():
            # Try alternate location
            model_dir = Path("/root/.local/share/tts") / model_name.replace("/", "--")

        config_path = model_dir / "config.json"

        if not config_path.exists():
            raise FileNotFoundError(f"Config not found at {config_path}")

        logger.info(f"Loading model from {model_dir}")

        # Load config
        config = XttsConfig()
        config.load_json(str(config_path))

        # Load model directly (faster than TTS wrapper)
        xtts_model = Xtts.init_from_config(config)
        xtts_model.load_checkpoint(config, checkpoint_dir=str(model_dir), eval=True)

        # Move to GPU if available
        if torch.cuda.is_available():
            xtts_model = xtts_model.cuda()
            logger.info(f"XTTS model loaded on GPU: {torch.cuda.get_device_name(0)}")
        else:
            torch.set_num_threads(2)
            logger.info("XTTS model loaded on CPU")

        xtts_model.eval()
        torch.set_grad_enabled(False)

        logger.info("XTTS model loaded with CPU optimizations")
        return xtts_model

    except Exception as e:
        logger.error(f"Failed to load XTTS model: {e}")
        raise


def get_speaker_embedding(voice_id: str):
    """Get cached speaker embedding or compute it."""
    global speaker_embeddings_cache

    if voice_id in speaker_embeddings_cache:
        return speaker_embeddings_cache[voice_id]

    voice_dir = VOICES_PATH / voice_id
    sample_path = voice_dir / "sample.wav"

    if not sample_path.exists():
        raise ValueError(f"Voice sample not found: {voice_id}")

    model = load_xtts_model()

    logger.info(f"Computing speaker embedding for {voice_id}...")

    with torch.inference_mode():
        gpt_cond_latent, speaker_embedding = model.get_conditioning_latents(
            audio_path=[str(sample_path)],
            gpt_cond_len=30,  # Shorter conditioning = faster
            gpt_cond_chunk_len=4,
            max_ref_length=30,
        )

    speaker_embeddings_cache[voice_id] = (gpt_cond_latent, speaker_embedding)
    logger.info(f"Speaker embedding cached for {voice_id}")

    return gpt_cond_latent, speaker_embedding


def split_text_into_chunks(text: str, max_chars: int = MAX_CHUNK_CHARS) -> List[str]:
    """Split text into smaller chunks for faster synthesis."""
    # Split by sentences first
    sentences = re.split(r'(?<=[.!?])\s+', text.strip())

    chunks = []
    current_chunk = ""

    for sentence in sentences:
        sentence = sentence.strip()
        if not sentence:
            continue

        # If single sentence is too long, split by commas or force split
        if len(sentence) > max_chars:
            # Try splitting by commas
            parts = re.split(r',\s*', sentence)
            for part in parts:
                part = part.strip()
                if not part:
                    continue
                if len(current_chunk) + len(part) + 2 <= max_chars:
                    current_chunk = f"{current_chunk}, {part}".strip(", ")
                else:
                    if current_chunk:
                        chunks.append(current_chunk)
                    # Force split if still too long
                    if len(part) > max_chars:
                        words = part.split()
                        current_chunk = ""
                        for word in words:
                            if len(current_chunk) + len(word) + 1 <= max_chars:
                                current_chunk = f"{current_chunk} {word}".strip()
                            else:
                                if current_chunk:
                                    chunks.append(current_chunk)
                                current_chunk = word
                    else:
                        current_chunk = part
        else:
            if len(current_chunk) + len(sentence) + 1 <= max_chars:
                current_chunk = f"{current_chunk} {sentence}".strip()
            else:
                if current_chunk:
                    chunks.append(current_chunk)
                current_chunk = sentence

    if current_chunk:
        chunks.append(current_chunk)

    return chunks if chunks else [text[:max_chars]]


def synthesize_chunk(model, text: str, gpt_cond_latent, speaker_embedding, language: str, speed: float) -> torch.Tensor:
    """Synthesize a single chunk of text."""
    with torch.inference_mode():
        out = model.inference(
            text=text,
            language=language,
            gpt_cond_latent=gpt_cond_latent,
            speaker_embedding=speaker_embedding,
            speed=speed,
            temperature=0.7,  # Slightly lower for speed
            length_penalty=1.0,
            repetition_penalty=2.0,
            top_k=50,
            top_p=0.85,
            enable_text_splitting=False,  # We handle splitting ourselves
        )
    wav = out["wav"]
    # Ensure it's a tensor
    if isinstance(wav, np.ndarray):
        wav = torch.from_numpy(wav)
    return wav


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Preload model on startup."""
    logger.info("XTTS Service starting - preloading model...")
    try:
        load_xtts_model()
        logger.info("Model preloaded successfully")
    except Exception as e:
        logger.warning(f"Model preload failed (will load on first request): {e}")
    yield
    logger.info("XTTS Service shutting down...")


app = FastAPI(
    title="XTTS Voice Cloning Service (Optimized)",
    version="2.0.0",
    lifespan=lifespan,
)


@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "torch_version": torch.__version__,
        "cuda_available": torch.cuda.is_available(),
        "cached_voices": len(speaker_embeddings_cache),
    }


@app.get("/ready")
async def ready():
    try:
        model = load_xtts_model()
        return {"status": "ready", "model": "xtts_v2_optimized", "device": "cpu"}
    except Exception as e:
        raise HTTPException(status_code=503, detail=f"Model not ready: {e}")


@app.get("/voices")
async def list_voices():
    voices = []
    for voice_dir in VOICES_PATH.iterdir():
        if voice_dir.is_dir():
            sample_file = voice_dir / "sample.wav"
            if sample_file.exists():
                import json
                meta_file = voice_dir / "meta.json"
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
                    "cached": voice_dir.name in speaker_embeddings_cache,
                })
    return {"voices": voices}


@app.post("/clone")
async def clone_voice(
    audio: UploadFile = File(...),
    name: str = Form(...),
    description: str = Form(""),
    language: str = Form("uz"),
):
    try:
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        sample_path = voice_dir / "sample.wav"
        audio_bytes = await audio.read()

        audio_tensor, sr = torchaudio.load(io.BytesIO(audio_bytes))

        # Resample to 22050Hz
        if sr != 22050:
            resampler = torchaudio.transforms.Resample(sr, 22050)
            audio_tensor = resampler(audio_tensor)

        # Mono
        if audio_tensor.shape[0] > 1:
            audio_tensor = torch.mean(audio_tensor, dim=0, keepdim=True)

        torchaudio.save(str(sample_path), audio_tensor, 22050)

        # Save metadata
        import json
        from datetime import datetime
        meta = {
            "name": name,
            "description": description,
            "language": language,
            "created_at": datetime.now().isoformat(),
        }
        with open(voice_dir / "meta.json", "w") as f:
            json.dump(meta, f, indent=2)

        # Pre-cache the embedding
        try:
            get_speaker_embedding(voice_id)
        except Exception as e:
            logger.warning(f"Failed to pre-cache embedding: {e}")

        logger.info(f"Voice cloned: {voice_id} ({name})")

        return {"ok": True, "voice_id": voice_id, "name": name}

    except Exception as e:
        logger.error(f"Voice cloning failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """Fast chunked synthesis."""
    try:
        model = load_xtts_model()

        # Get cached speaker embedding
        gpt_cond_latent, speaker_embedding = get_speaker_embedding(request.voice_id)

        output_path = STORAGE_PATH / request.output_path
        output_path.parent.mkdir(parents=True, exist_ok=True)

        # Map language
        lang_map = {
            "uz": "tr", "ru": "ru", "en": "en", "tr": "tr",
            "ar": "ar", "zh": "zh-cn", "ja": "ja", "ko": "ko",
            "es": "es", "fr": "fr", "de": "de", "it": "it",
        }
        language = lang_map.get(request.language, "en")

        # Split into chunks
        chunks = split_text_into_chunks(request.text)
        logger.info(f"Synthesizing {len(chunks)} chunks for voice={request.voice_id}")

        # Synthesize each chunk
        audio_segments = []
        for i, chunk in enumerate(chunks):
            logger.info(f"  Chunk {i+1}/{len(chunks)}: {len(chunk)} chars")
            wav = synthesize_chunk(
                model, chunk, gpt_cond_latent, speaker_embedding,
                language, request.speed
            )
            audio_segments.append(wav)

        # Concatenate all chunks
        if len(audio_segments) == 1:
            final_wav = audio_segments[0]
        else:
            final_wav = torch.cat(audio_segments, dim=0)

        # Save output
        torchaudio.save(str(output_path), final_wav.unsqueeze(0), 24000)

        if not output_path.exists() or output_path.stat().st_size < 1000:
            raise HTTPException(status_code=500, detail="Synthesis produced invalid output")

        logger.info(f"Synthesis complete: {output_path} ({len(chunks)} chunks)")

        return {
            "ok": True,
            "output_path": request.output_path,
            "size": output_path.stat().st_size,
            "chunks": len(chunks),
        }

    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Synthesis failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/warmup/{voice_id}")
async def warmup_voice(voice_id: str):
    """Pre-cache a voice embedding."""
    try:
        get_speaker_embedding(voice_id)
        return {"ok": True, "voice_id": voice_id, "cached": True}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    voice_dir = VOICES_PATH / voice_id
    if not voice_dir.exists():
        raise HTTPException(status_code=404, detail="Voice not found")

    import shutil
    shutil.rmtree(voice_dir)

    # Remove from cache
    speaker_embeddings_cache.pop(voice_id, None)

    return {"ok": True}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
