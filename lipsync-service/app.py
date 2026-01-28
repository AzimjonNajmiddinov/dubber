import os
import subprocess
import time
from fastapi import FastAPI
from pydantic import BaseModel

APP_STORAGE = "/var/www/storage/app"
W2L_DIR = "/app/Wav2Lip"
CKPT = "/app/Wav2Lip/checkpoints/wav2lip.pth"

app = FastAPI()


@app.get("/health")
def health():
    """Health check endpoint."""
    import torch
    return {
        "ok": True,
        "torch": torch.__version__,
        "wav2lip_dir": os.path.exists(W2L_DIR),
        "checkpoint": os.path.exists(CKPT),
    }


class Req(BaseModel):
    video_path: str  # relative to storage/app
    audio_path: str  # relative to storage/app (wav recommended)
    out_path: str    # relative to storage/app

def abs_path(rel: str) -> str:
    rel = rel.lstrip("/").strip()
    return os.path.join(APP_STORAGE, rel)

def run(cmd: list[str], timeout: int = 3600) -> tuple[int, str, float]:
    t0 = time.time()
    p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    try:
        out, _ = p.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        p.kill()
        out, _ = p.communicate()
        return 124, out[-16000:], time.time() - t0
    return p.returncode, out[-16000:], time.time() - t0

@app.post("/lipsync")
def lipsync(req: Req):
    in_mp4 = abs_path(req.video_path)
    in_wav = abs_path(req.audio_path)
    out_mp4 = abs_path(req.out_path)

    if not os.path.exists(in_mp4):
        return {"ok": False, "error": f"video not found: {req.video_path}"}
    if not os.path.exists(in_wav):
        return {"ok": False, "error": f"audio not found: {req.audio_path}"}

    os.makedirs(os.path.dirname(out_mp4), exist_ok=True)
    if os.path.exists(out_mp4):
        os.remove(out_mp4)

    # Wav2Lip wants a clean 16k mono wav
    tmp_dir = os.path.dirname(out_mp4)
    tmp_wav = os.path.join(tmp_dir, "audio_16k_mono.wav")

    code, log1, dt1 = run([
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-i", in_wav, "-ac", "1", "-ar", "16000", tmp_wav
    ], timeout=600)

    if code != 0 or not os.path.exists(tmp_wav):
        return {"ok": False, "error": "ffmpeg wav resample failed", "code": code, "seconds": dt1, "log": log1}

    # Wav2Lip CPU stability knobs:
    # - face_det_batch_size=1 reduces RAM spikes significantly
    # - wav2lip_batch_size=4 reduces compute bursts
    # - resize_factor=2 reduces resolution to save memory (half resolution)
    # - nosmooth avoids extra temporal smoothing cost
    tmp_w2l = os.path.join(tmp_dir, "w2l_out.mp4")
    if os.path.exists(tmp_w2l):
        os.remove(tmp_w2l)

    cmd = [
        "python", os.path.join(W2L_DIR, "inference.py"),
        "--checkpoint_path", CKPT,
        "--face", in_mp4,
        "--audio", tmp_wav,
        "--outfile", tmp_w2l,
        "--pads", "0", "10", "0", "0",
        "--resize_factor", "2",
        "--face_det_batch_size", "1",
        "--wav2lip_batch_size", "4",
        "--nosmooth",
    ]

    code, log2, dt2 = run(cmd, timeout=3600)

    if code != 0 or not os.path.exists(tmp_w2l) or os.path.getsize(tmp_w2l) < 5000:
        # code 137 usually means OOM kill
        return {"ok": False, "error": "wav2lip failed", "code": code, "seconds": dt2, "cmd": " ".join(cmd), "log": log2}

    # Force mux Uzbek audio (keep Wav2Lip video, replace audio)
    code, log3, dt3 = run([
        "ffmpeg", "-y", "-hide_banner", "-loglevel", "error",
        "-i", tmp_w2l, "-i", in_wav,
        "-map", "0:v:0", "-map", "1:a:0",
        "-c:v", "copy", "-c:a", "aac", "-b:a", "192k",
        "-shortest", out_mp4
    ], timeout=600)

    if code != 0 or not os.path.exists(out_mp4):
        return {"ok": False, "error": "ffmpeg mux failed", "code": code, "seconds": dt3, "log": log3}

    # cleanup
    for p in (tmp_wav, tmp_w2l):
        try:
            os.remove(p)
        except Exception:
            pass

    return {"ok": True, "out_path": req.out_path}
