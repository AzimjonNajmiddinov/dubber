"""
Demucs GPU service for RunPod.
Accepts audio file uploads, runs GPU-accelerated stem separation, returns stems.
Uses demucs Python API directly (no subprocess) to avoid PyTorch in-place op issues.
"""
import hashlib
import shutil
import tempfile
import time
from contextlib import asynccontextmanager
from pathlib import Path

import numpy as np
import soundfile as sf
import torch
import torchaudio
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse

_cache_dir = Path("/tmp/demucs_cache")
_cache_dir.mkdir(exist_ok=True)

_model = None
_device = "cuda" if torch.cuda.is_available() else "cpu"


def _load_model(name: str = "htdemucs"):
    from demucs.pretrained import get_model
    m = get_model(name)
    m.to(_device)
    m.eval()
    return m


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _model
    print("=== Demucs GPU Service Starting ===")
    if torch.cuda.is_available():
        print(f"GPU: {torch.cuda.get_device_name(0)}")
        print(f"VRAM: {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")
    else:
        print("WARNING: No GPU detected, will use CPU (slow)")
    try:
        print("Loading htdemucs model...")
        _model = _load_model("htdemucs")
        print("Model loaded successfully")
    except Exception as e:
        print(f"Model pre-load failed (will load on first request): {e}")
    yield
    shutil.rmtree(_cache_dir, ignore_errors=True)


app = FastAPI(title="Demucs GPU Service", lifespan=lifespan)


def _get_file_hash(file_path: Path) -> str:
    h = hashlib.md5()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    return h.hexdigest()


def _load_audio_to_tensor(audio_path: Path) -> tuple[torch.Tensor, int]:
    """Load any audio format to stereo float32 tensor using PyAV + soundfile fallback."""
    suffix = audio_path.suffix.lower()
    if suffix in (".wav", ".flac"):
        wav, sr = torchaudio.load(str(audio_path))
    else:
        try:
            import av
            container = av.open(str(audio_path))
            stream = container.streams.audio[0]
            sr = stream.rate
            chunks = []
            fmt = None
            for frame in container.decode(stream):
                fmt = frame.format.name
                chunks.append(frame.to_ndarray())
            container.close()
            raw = np.concatenate(chunks, axis=1).T  # (samples, channels)
            # fltp/flt = already float [-1,1]; s16p/s16 = int16, divide by 32768
            if fmt and fmt.startswith('s16'):
                data = np.ascontiguousarray(raw.astype(np.float32) / 32768.0)
            else:
                data = np.ascontiguousarray(raw.astype(np.float32))
            wav = torch.from_numpy(data.T)  # (channels, samples)
        except Exception as e:
            raise HTTPException(status_code=400, detail=f"Audio load failed: {e}")
    # Ensure stereo
    if wav.shape[0] == 1:
        wav = wav.repeat(2, 1)
    elif wav.shape[0] > 2:
        wav = wav[:2]
    return wav, sr


def _separate(wav: torch.Tensor, sr: int, model_name: str = "htdemucs") -> tuple[torch.Tensor, torch.Tensor]:
    """
    Run demucs separation. Returns (vocals, no_vocals) tensors, shape (2, samples).
    """
    global _model
    from demucs.apply import apply_model

    if _model is None:
        _model = _load_model(model_name)

    target_sr = _model.samplerate
    if sr != target_sr:
        wav = torchaudio.functional.resample(wav, sr, target_sr)

    wav = wav.to(_device)
    wav_batch = wav.unsqueeze(0)  # (1, 2, samples)

    with torch.no_grad():
        sources = apply_model(_model, wav_batch, device=_device, progress=False)
    # sources: (1, num_stems, 2, samples)
    sources = sources[0].cpu()  # (num_stems, 2, samples)

    source_names = _model.sources  # e.g. ['drums', 'bass', 'other', 'vocals']
    vocals_idx = source_names.index("vocals")
    vocals = sources[vocals_idx]                      # (2, samples)
    no_vocals = sources.sum(0) - vocals               # (2, samples)

    return vocals, no_vocals


@app.get("/health")
def health():
    return {
        "ok": True,
        "service": "demucs",
        "gpu": torch.cuda.is_available(),
        "gpu_name": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        "torch_version": torch.__version__,
        "model_loaded": _model is not None,
        "cache_size": len(list(_cache_dir.iterdir())) if _cache_dir.exists() else 0,
    }


@app.post("/separate")
async def separate(
    audio: UploadFile = File(...),
    model: str = Form("htdemucs"),
    two_stems: str = Form("vocals"),
):
    work_dir = Path(tempfile.mkdtemp(prefix="demucs_"))
    try:
        input_path = work_dir / f"input{Path(audio.filename or 'audio.wav').suffix}"
        with open(input_path, "wb") as f:
            f.write(await audio.read())

        if input_path.stat().st_size < 1000:
            raise HTTPException(status_code=400, detail="Audio file too small")

        file_hash = _get_file_hash(input_path)
        cache_key = f"{file_hash}_{model}_{two_stems}"
        cached_dir = _cache_dir / cache_key

        if cached_dir.exists() and (cached_dir / "no_vocals.wav").exists():
            return {
                "ok": True,
                "cached": True,
                "job_id": cache_key,
                "no_vocals_url": f"/download/{cache_key}/no_vocals.wav",
                "vocals_url": f"/download/{cache_key}/vocals.wav" if (cached_dir / "vocals.wav").exists() else None,
            }

        start_time = time.time()

        wav, sr = _load_audio_to_tensor(input_path)
        vocals, no_vocals = _separate(wav, sr, model)

        elapsed = time.time() - start_time

        cached_dir.mkdir(parents=True, exist_ok=True)

        def _save(tensor: torch.Tensor, path: Path):
            data = tensor.numpy().T  # (samples, channels)
            sf.write(str(path), data, _model.samplerate)

        _save(no_vocals, cached_dir / "no_vocals.wav")
        _save(vocals, cached_dir / "vocals.wav")

        return {
            "ok": True,
            "cached": False,
            "job_id": cache_key,
            "elapsed_seconds": round(elapsed, 2),
            "no_vocals_url": f"/download/{cache_key}/no_vocals.wav",
            "vocals_url": f"/download/{cache_key}/vocals.wav",
        }

    finally:
        shutil.rmtree(work_dir, ignore_errors=True)


@app.get("/download/{job_id}/{filename}")
def download(job_id: str, filename: str):
    if filename not in ("no_vocals.wav", "vocals.wav"):
        raise HTTPException(status_code=400, detail="Invalid filename")
    file_path = _cache_dir / job_id / filename
    if not file_path.exists():
        raise HTTPException(status_code=404, detail="File not found")
    return FileResponse(path=file_path, media_type="audio/wav", filename=filename)


@app.delete("/cache/{job_id}")
def delete_cache(job_id: str):
    cached_dir = _cache_dir / job_id
    if cached_dir.exists():
        shutil.rmtree(cached_dir, ignore_errors=True)
        return {"ok": True, "deleted": True}
    return {"ok": True, "deleted": False}


@app.post("/cleanup-cache")
def cleanup_cache(max_age_hours: int = 2):
    now = time.time()
    deleted = 0
    for entry in _cache_dir.iterdir():
        if entry.is_dir() and now - entry.stat().st_mtime > max_age_hours * 3600:
            shutil.rmtree(entry, ignore_errors=True)
            deleted += 1
    return {"ok": True, "deleted_entries": deleted}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
