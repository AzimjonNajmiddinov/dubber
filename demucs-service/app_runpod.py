"""
Demucs GPU service for RunPod.
Accepts audio file uploads, runs GPU-accelerated stem separation, returns stems.
"""
import os
import shutil
import subprocess
import tempfile
import hashlib
import time
from pathlib import Path
from typing import Optional
from contextlib import asynccontextmanager

from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import FileResponse, JSONResponse
import torch

# Cache for avoiding re-processing
_cache_dir = Path("/tmp/demucs_cache")
_cache_dir.mkdir(exist_ok=True)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: verify GPU and demucs
    print("=== Demucs GPU Service Starting ===")
    if torch.cuda.is_available():
        print(f"GPU: {torch.cuda.get_device_name(0)}")
        print(f"VRAM: {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")
    else:
        print("WARNING: No GPU detected, will use CPU (slow)")

    # Pre-load demucs model
    try:
        import demucs.pretrained
        print("Loading htdemucs model...")
        _ = demucs.pretrained.get_model("htdemucs")
        print("Model loaded successfully")
    except Exception as e:
        print(f"Model pre-load failed (will load on first request): {e}")

    yield

    # Shutdown: cleanup
    shutil.rmtree(_cache_dir, ignore_errors=True)


app = FastAPI(title="Demucs GPU Service", lifespan=lifespan)


def get_file_hash(file_path: Path) -> str:
    """Get MD5 hash of file for caching."""
    h = hashlib.md5()
    with open(file_path, 'rb') as f:
        for chunk in iter(lambda: f.read(8192), b''):
            h.update(chunk)
    return h.hexdigest()


@app.get("/health")
def health():
    """Health check endpoint."""
    gpu_available = torch.cuda.is_available()
    gpu_name = torch.cuda.get_device_name(0) if gpu_available else None

    return {
        "ok": True,
        "service": "demucs",
        "gpu": gpu_available,
        "gpu_name": gpu_name,
        "torch_version": torch.__version__,
        "cache_size": len(list(_cache_dir.iterdir())) if _cache_dir.exists() else 0,
    }


@app.post("/separate")
async def separate(
    audio: UploadFile = File(...),
    model: str = "htdemucs",
    two_stems: str = "vocals",
):
    """
    Separate audio into stems using Demucs GPU.

    Args:
        audio: Audio file (WAV, MP3, FLAC, etc.)
        model: Demucs model to use (default: htdemucs)
        two_stems: Which stems to separate (default: vocals)

    Returns:
        JSON with download URLs for stems
    """
    # Create temp directory for this request
    work_dir = Path(tempfile.mkdtemp(prefix="demucs_"))

    try:
        # Save uploaded file
        input_path = work_dir / f"input{Path(audio.filename or 'audio.wav').suffix}"
        with open(input_path, 'wb') as f:
            content = await audio.read()
            f.write(content)

        if input_path.stat().st_size < 1000:
            raise HTTPException(status_code=400, detail="Audio file too small")

        # Check cache
        file_hash = get_file_hash(input_path)
        cache_key = f"{file_hash}_{model}_{two_stems}"
        cached_dir = _cache_dir / cache_key

        if cached_dir.exists():
            no_vocals = cached_dir / "no_vocals.wav"
            if no_vocals.exists():
                return {
                    "ok": True,
                    "cached": True,
                    "job_id": cache_key,
                    "no_vocals_url": f"/download/{cache_key}/no_vocals.wav",
                    "vocals_url": f"/download/{cache_key}/vocals.wav" if (cached_dir / "vocals.wav").exists() else None,
                }

        # Output directory
        output_dir = work_dir / "output"
        output_dir.mkdir()

        # Run demucs with GPU
        cmd = [
            "python", "-m", "demucs.separate",
            "-n", model,
            f"--two-stems={two_stems}",
            "-o", str(output_dir),
            "--device", "cuda" if torch.cuda.is_available() else "cpu",
            str(input_path),
        ]

        start_time = time.time()

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=600,  # 10 minute timeout
            )
        except subprocess.TimeoutExpired:
            raise HTTPException(status_code=504, detail="Separation timed out")

        elapsed = time.time() - start_time

        if result.returncode != 0:
            raise HTTPException(
                status_code=500,
                detail=f"Demucs failed: {result.stderr[-1000:]}"
            )

        # Find output files
        model_dir = output_dir / model / input_path.stem

        no_vocals_src = None
        vocals_src = None

        for ext in (".wav", ".flac"):
            nv = model_dir / f"no_vocals{ext}"
            v = model_dir / f"vocals{ext}"
            if nv.exists():
                no_vocals_src = nv
            if v.exists():
                vocals_src = v

        if not no_vocals_src:
            raise HTTPException(status_code=500, detail="no_vocals not generated")

        # Copy to cache directory
        cached_dir.mkdir(parents=True, exist_ok=True)

        no_vocals_dst = cached_dir / "no_vocals.wav"
        shutil.copy2(no_vocals_src, no_vocals_dst)

        vocals_url = None
        if vocals_src:
            vocals_dst = cached_dir / "vocals.wav"
            shutil.copy2(vocals_src, vocals_dst)
            vocals_url = f"/download/{cache_key}/vocals.wav"

        return {
            "ok": True,
            "cached": False,
            "job_id": cache_key,
            "elapsed_seconds": round(elapsed, 2),
            "no_vocals_url": f"/download/{cache_key}/no_vocals.wav",
            "vocals_url": vocals_url,
        }

    finally:
        # Cleanup work directory
        shutil.rmtree(work_dir, ignore_errors=True)


@app.get("/download/{job_id}/{filename}")
def download(job_id: str, filename: str):
    """Download a separated stem file."""
    # Validate filename
    if filename not in ("no_vocals.wav", "vocals.wav"):
        raise HTTPException(status_code=400, detail="Invalid filename")

    file_path = _cache_dir / job_id / filename

    if not file_path.exists():
        raise HTTPException(status_code=404, detail="File not found")

    return FileResponse(
        path=file_path,
        media_type="audio/wav",
        filename=filename,
    )


@app.delete("/cache/{job_id}")
def delete_cache(job_id: str):
    """Delete cached stems for a job."""
    cached_dir = _cache_dir / job_id
    if cached_dir.exists():
        shutil.rmtree(cached_dir, ignore_errors=True)
        return {"ok": True, "deleted": True}
    return {"ok": True, "deleted": False}


@app.post("/cleanup-cache")
def cleanup_cache(max_age_hours: int = 2):
    """Remove cache entries older than max_age_hours."""
    now = time.time()
    max_age_seconds = max_age_hours * 3600
    deleted = 0

    for entry in _cache_dir.iterdir():
        if entry.is_dir():
            age = now - entry.stat().st_mtime
            if age > max_age_seconds:
                shutil.rmtree(entry, ignore_errors=True)
                deleted += 1

    return {"ok": True, "deleted_entries": deleted}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)
