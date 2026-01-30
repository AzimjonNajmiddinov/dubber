import shutil
import subprocess
import hashlib
import threading
import time
from pathlib import Path
from typing import Union, Optional, Dict
from contextlib import contextmanager

from fastapi import FastAPI, BackgroundTasks
from pydantic import BaseModel

app = FastAPI()
BASE = Path("/var/www/storage/app").resolve()

# In-memory cache for stem separation results
# Key: hash of input file path, Value: dict with paths and timestamp
_stem_cache: Dict[str, dict] = {}
_cache_lock = threading.Lock()
CACHE_TTL = 3600  # 1 hour cache TTL


class SeparateReq(BaseModel):
    input_rel: str
    video_id: Union[int, str]
    model: str = "htdemucs"
    two_stems: str = "vocals"


class SegmentReq(BaseModel):
    """Request to separate a small segment - optimized for speed."""
    input_rel: str
    video_id: Union[int, str]
    start_time: float  # seconds
    duration: float  # seconds
    model: str = "htdemucs"
    two_stems: str = "vocals"


class ExtractSegmentReq(BaseModel):
    """Extract a segment from already-separated full stems."""
    video_id: Union[int, str]
    start_time: float
    duration: float
    chunk_index: int


def _get_cache_key(path: str) -> str:
    """Generate cache key from file path and modification time."""
    p = Path(path)
    if p.exists():
        mtime = p.stat().st_mtime
        return hashlib.md5(f"{path}:{mtime}".encode()).hexdigest()
    return hashlib.md5(path.encode()).hexdigest()


def _cleanup_old_cache():
    """Remove expired cache entries."""
    now = time.time()
    with _cache_lock:
        expired = [k for k, v in _stem_cache.items() if now - v.get('timestamp', 0) > CACHE_TTL]
        for k in expired:
            del _stem_cache[k]


def _audio_backend_preflight(tmp_dir: Path):
    import torch
    import torchaudio as ta

    backends = ta.list_audio_backends()
    if not backends:
        return {"ok": False, "error": "No torchaudio audio backends available"}

    try:
        wav = torch.zeros(1, 8000)
        test = tmp_dir / "_test.wav"
        ta.save(str(test), wav, 8000)
        test.unlink(missing_ok=True)
    except Exception as e:
        return {"ok": False, "error": f"Audio backend write failed: {e}"}

    return {"ok": True, "backends": backends}


@app.get("/health")
def health():
    import torch, torchaudio, numpy
    return {
        "ok": True,
        "numpy": numpy.__version__,
        "torch": torch.__version__,
        "torchaudio": torchaudio.__version__,
        "backends": torchaudio.list_audio_backends(),
        "cache_size": len(_stem_cache),
    }


@app.post("/separate")
def separate(req: SeparateReq):
    """Full file stem separation - used for processing entire videos."""
    inp = (BASE / req.input_rel).resolve()
    if not inp.exists():
        return {"ok": False, "error": f"Input not found: {req.input_rel}"}

    # Check cache first
    cache_key = _get_cache_key(str(inp))
    with _cache_lock:
        if cache_key in _stem_cache:
            cached = _stem_cache[cache_key]
            if Path(BASE / cached.get('no_vocals_rel', '')).exists():
                return {
                    "ok": True,
                    "no_vocals_rel": cached['no_vocals_rel'],
                    "vocals_rel": cached.get('vocals_rel'),
                    "cached": True,
                }

    out_tmp = BASE / f"audio/stems/_tmp_{req.video_id}"
    out_final = BASE / f"audio/stems/{req.video_id}"

    shutil.rmtree(out_tmp, ignore_errors=True)
    out_tmp.mkdir(parents=True, exist_ok=True)
    out_final.mkdir(parents=True, exist_ok=True)

    pre = _audio_backend_preflight(out_tmp)
    if not pre["ok"]:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return pre

    cmd = [
        "python", "-m", "demucs.separate",
        "-n", req.model,
        f"--two-stems={req.two_stems}",
        "-o", str(out_tmp),
        "--jobs", "2",  # Parallel processing
        str(inp),
    ]

    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
    except Exception as e:
        return {"ok": False, "error": f"Demucs execution failed: {e}"}

    if p.returncode != 0:
        return {
            "ok": False,
            "error": "Demucs failed",
            "stdout": p.stdout[-3000:],
            "stderr": p.stderr[-3000:],
        }

    model_dir = out_tmp / req.model / inp.stem

    def pick(name):
        for ext in (".wav", ".flac"):
            f = model_dir / f"{name}{ext}"
            if f.exists():
                return f
        return None

    vocals = pick("vocals")
    no_vocals = pick("no_vocals")

    if not no_vocals:
        return {"ok": False, "error": "no_vocals not generated"}

    shutil.copyfile(no_vocals, out_final / no_vocals.name)
    if vocals:
        shutil.copyfile(vocals, out_final / vocals.name)

    shutil.rmtree(out_tmp, ignore_errors=True)

    result = {
        "ok": True,
        "no_vocals_rel": f"audio/stems/{req.video_id}/{no_vocals.name}",
        "vocals_rel": f"audio/stems/{req.video_id}/{vocals.name}" if vocals else None,
    }

    # Cache the result
    with _cache_lock:
        _stem_cache[cache_key] = {
            **result,
            'timestamp': time.time(),
        }

    return result


@app.post("/separate-segment")
def separate_segment(req: SegmentReq):
    """
    Optimized stem separation for small segments.
    Extracts the segment first, then processes only that portion.
    Much faster for small chunks from large movies.
    """
    inp = (BASE / req.input_rel).resolve()
    if not inp.exists():
        return {"ok": False, "error": f"Input not found: {req.input_rel}"}

    # For very short segments, use a lighter approach
    segment_id = f"{req.video_id}_seg_{int(req.start_time * 1000)}"
    out_tmp = BASE / f"audio/stems/_tmp_{segment_id}"
    out_final = BASE / f"audio/stems/{req.video_id}"

    shutil.rmtree(out_tmp, ignore_errors=True)
    out_tmp.mkdir(parents=True, exist_ok=True)
    out_final.mkdir(parents=True, exist_ok=True)

    # Step 1: Extract just the segment we need (fast with ffmpeg)
    segment_audio = out_tmp / "segment.wav"
    extract_cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-ss", str(req.start_time),
        "-i", str(inp),
        "-t", str(req.duration),
        "-vn", "-ac", "2", "-ar", "44100", "-c:a", "pcm_s16le",
        str(segment_audio),
    ]

    try:
        p = subprocess.run(extract_cmd, capture_output=True, text=True, timeout=60)
        if p.returncode != 0:
            return {"ok": False, "error": f"Segment extraction failed: {p.stderr[-500:]}"}
    except Exception as e:
        return {"ok": False, "error": f"Segment extraction error: {e}"}

    if not segment_audio.exists() or segment_audio.stat().st_size < 1000:
        return {"ok": False, "error": "Extracted segment is empty or too small"}

    pre = _audio_backend_preflight(out_tmp)
    if not pre["ok"]:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return pre

    # Step 2: Run demucs on the small segment (much faster)
    cmd = [
        "python", "-m", "demucs.separate",
        "-n", req.model,
        f"--two-stems={req.two_stems}",
        "-o", str(out_tmp),
        str(segment_audio),
    ]

    try:
        # Shorter timeout for segments
        timeout = max(60, int(req.duration * 10))  # ~10x realtime max
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return {"ok": False, "error": "Segment separation timed out"}
    except Exception as e:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return {"ok": False, "error": f"Demucs execution failed: {e}"}

    if p.returncode != 0:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return {
            "ok": False,
            "error": "Demucs failed",
            "stdout": p.stdout[-1000:],
            "stderr": p.stderr[-1000:],
        }

    model_dir = out_tmp / req.model / "segment"

    def pick(name):
        for ext in (".wav", ".flac"):
            f = model_dir / f"{name}{ext}"
            if f.exists():
                return f
        return None

    vocals = pick("vocals")
    no_vocals = pick("no_vocals")

    if not no_vocals:
        shutil.rmtree(out_tmp, ignore_errors=True)
        return {"ok": False, "error": "no_vocals not generated for segment"}

    # Copy to final location with unique segment name
    output_name = f"no_vocals_chunk_{req.video_id}_{int(req.start_time * 1000)}.wav"
    vocals_name = f"vocals_chunk_{req.video_id}_{int(req.start_time * 1000)}.wav" if vocals else None

    shutil.copyfile(no_vocals, out_final / output_name)
    if vocals:
        shutil.copyfile(vocals, out_final / vocals_name)

    shutil.rmtree(out_tmp, ignore_errors=True)

    return {
        "ok": True,
        "no_vocals_rel": f"audio/stems/{req.video_id}/{output_name}",
        "vocals_rel": f"audio/stems/{req.video_id}/{vocals_name}" if vocals else None,
        "segment": True,
        "duration": req.duration,
    }


@app.post("/extract-from-stems")
def extract_from_stems(req: ExtractSegmentReq):
    """
    Extract a segment from already-separated full stems.
    This is VERY fast when full stems are already available.
    No ML processing needed - just ffmpeg extraction.
    """
    stems_dir = BASE / f"audio/stems/{req.video_id}"

    # Find the no_vocals file
    no_vocals_path = None
    for ext in (".wav", ".flac"):
        candidate = stems_dir / f"no_vocals{ext}"
        if candidate.exists():
            no_vocals_path = candidate
            break

    if not no_vocals_path:
        return {"ok": False, "error": "Full stems not found - run /separate first"}

    # Output path for this chunk's segment
    output_name = f"bg_chunk_{req.chunk_index}.wav"
    output_path = stems_dir / output_name

    # Extract just the segment we need (very fast - no ML)
    extract_cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-ss", str(req.start_time),
        "-i", str(no_vocals_path),
        "-t", str(req.duration),
        "-c:a", "pcm_s16le", "-ar", "44100", "-ac", "2",
        str(output_path),
    ]

    try:
        p = subprocess.run(extract_cmd, capture_output=True, text=True, timeout=30)
        if p.returncode != 0:
            return {"ok": False, "error": f"Segment extraction failed: {p.stderr[-500:]}"}
    except Exception as e:
        return {"ok": False, "error": f"Extraction error: {e}"}

    if not output_path.exists() or output_path.stat().st_size < 100:
        return {"ok": False, "error": "Extracted segment is empty"}

    return {
        "ok": True,
        "no_vocals_rel": f"audio/stems/{req.video_id}/{output_name}",
        "extracted_from_full": True,
    }


@app.get("/stems-ready/{video_id}")
def check_stems_ready(video_id: Union[int, str]):
    """
    Check if full stems are available for a video.
    Used by chunk jobs to decide whether to extract from full stems
    or do per-chunk separation.
    """
    stems_dir = BASE / f"audio/stems/{video_id}"

    if not stems_dir.exists():
        return {"ready": False}

    for ext in (".wav", ".flac"):
        candidate = stems_dir / f"no_vocals{ext}"
        if candidate.exists() and candidate.stat().st_size > 1000:
            return {
                "ready": True,
                "no_vocals_rel": f"audio/stems/{video_id}/no_vocals{ext}",
            }

    return {"ready": False}


class ExtractVocalsReq(BaseModel):
    """Extract vocals segment for voice cloning."""
    video_id: Union[int, str]
    start_time: float
    duration: float
    speaker_id: Union[int, str]


@app.post("/extract-vocals")
def extract_vocals(req: ExtractVocalsReq):
    """
    Extract a vocals segment for voice cloning.
    Used to get clean voice samples from separated vocals track.
    """
    stems_dir = BASE / f"audio/stems/{req.video_id}"

    # Find the vocals file
    vocals_path = None
    for ext in (".wav", ".flac"):
        candidate = stems_dir / f"vocals{ext}"
        if candidate.exists():
            vocals_path = candidate
            break

    if not vocals_path:
        return {"ok": False, "error": "Vocals stem not found - run full stem separation first"}

    # Output path for this speaker's voice sample
    samples_dir = BASE / f"audio/voice_samples/{req.video_id}"
    samples_dir.mkdir(parents=True, exist_ok=True)

    output_name = f"speaker_{req.speaker_id}_sample.wav"
    output_path = samples_dir / output_name

    # Extract the vocals segment with noise reduction
    extract_cmd = [
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-ss", str(req.start_time),
        "-i", str(vocals_path),
        "-t", str(req.duration),
        "-af", "highpass=f=80,lowpass=f=12000,afftdn=nf=-20,volume=1.5",
        "-c:a", "pcm_s16le", "-ar", "22050", "-ac", "1",
        str(output_path),
    ]

    try:
        p = subprocess.run(extract_cmd, capture_output=True, text=True, timeout=30)
        if p.returncode != 0:
            return {"ok": False, "error": f"Vocals extraction failed: {p.stderr[-500:]}"}
    except Exception as e:
        return {"ok": False, "error": f"Extraction error: {e}"}

    if not output_path.exists() or output_path.stat().st_size < 1000:
        return {"ok": False, "error": "Extracted vocals segment is empty"}

    return {
        "ok": True,
        "vocals_sample_rel": f"audio/voice_samples/{req.video_id}/{output_name}",
        "duration": req.duration,
    }


@app.on_event("startup")
async def startup_cleanup():
    """Clean up old temp directories and cache on startup."""
    stems_base = BASE / "audio/stems"
    if stems_base.exists():
        for d in stems_base.iterdir():
            if d.is_dir() and d.name.startswith("_tmp_"):
                shutil.rmtree(d, ignore_errors=True)


@app.on_event("shutdown")
async def shutdown_cleanup():
    """Clean cache on shutdown."""
    global _stem_cache
    with _cache_lock:
        _stem_cache.clear()
