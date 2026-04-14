"""
Prosody Transfer Service — waveform-level energy matching (no WORLD vocoder).

WORLD ishlatilmaydi — faqat frame-by-frame RMS gain envelope.
Bu OpenVoice / MMS sifatini saqlab, referens energiya dinamikasini ko'chiradi.

POST /transfer
  tts_audio:       UploadFile  — MMS/OpenVoice chiqargan audio
  reference:       UploadFile  — original aktyor ovozi (ruscha dublyaj)
  energy_transfer: bool        — energy ko'chirish (default: true)
  frame_ms:        int         — frame hajmi ms da (default: 20)
  smooth_frames:   int         — gain smoothing (default: 7)

Port: 8006
"""

import io
import uuid
import logging
import math
from pathlib import Path

import numpy as np
import soundfile as sf
from scipy.signal import resample_poly
from scipy.ndimage import uniform_filter1d
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CACHE_PATH = Path("/tmp/prosody-cache")
CACHE_PATH.mkdir(parents=True, exist_ok=True)

TARGET_SR = 22050

app = FastAPI(title="Prosody Transfer Service", version="2.0.0")


@app.get("/health")
def health():
    return {"status": "ok", "service": "prosody-transfer"}


def read_audio_mono(data: bytes, target_sr: int) -> np.ndarray:
    """Har qanday formatdan mono float32 array."""
    arr, sr = sf.read(io.BytesIO(data), always_2d=True)
    arr = arr.mean(axis=1).astype(np.float32)
    if sr != target_sr:
        g = math.gcd(sr, target_sr)
        arr = resample_poly(arr, target_sr // g, sr // g).astype(np.float32)
    return arr


def rms_gain_transfer(
    src: np.ndarray,
    ref: np.ndarray,
    sr: int,
    frame_ms: int = 20,
    smooth_frames: int = 7,
) -> np.ndarray:
    """
    Frame-by-frame RMS gain envelope ko'chirish.

    Referens audiodagi loudness dinamikasini TTS audioga qo'llaydi.
    WORLD vocoder ishlatilmaydi — faqat amplitude scaling.
    Consonantlar, timbre, OpenVoice sifati to'liq saqlanadi.
    """
    frame_size = int(sr * frame_ms / 1000)   # e.g. 20ms → 441 samples at 22050
    hop        = frame_size // 2              # 50% overlap

    def compute_rms_envelope(audio: np.ndarray) -> np.ndarray:
        """Sliding window RMS, har bir hop uchun bir qiymat."""
        n_frames = max(1, (len(audio) - frame_size) // hop + 1)
        rms = np.zeros(n_frames, dtype=np.float32)
        for i in range(n_frames):
            start = i * hop
            frame = audio[start:start + frame_size]
            rms[i] = np.sqrt(np.mean(frame ** 2) + 1e-10)
        return rms

    rms_src = compute_rms_envelope(src)   # [T_src]
    rms_ref = compute_rms_envelope(ref)   # [T_ref]

    # Referens RMS ni src uzunligiga vaqt bo'yicha interpolatsiya
    t_src = np.linspace(0, 1, len(rms_src))
    t_ref = np.linspace(0, 1, len(rms_ref))
    rms_ref_interp = np.interp(t_src, t_ref, rms_ref).astype(np.float32)

    # Gain = ref_rms / src_rms, clipped to prevent extreme amplification
    # Max 2.0 to avoid noise-floor amplification (hissing artifacts at 4.0)
    gain_frames = np.clip(rms_ref_interp / (rms_src + 1e-10), 0.2, 2.0)

    # Smooth gain to avoid clicks at frame boundaries
    if smooth_frames > 1:
        gain_frames = uniform_filter1d(gain_frames, size=smooth_frames).astype(np.float32)

    # Expand gain frames to sample-level (linear interpolation between frame centers)
    frame_centers = np.arange(len(gain_frames)) * hop + frame_size // 2
    sample_idx    = np.arange(len(src))
    gain_samples  = np.interp(sample_idx, frame_centers, gain_frames).astype(np.float32)

    return src * gain_samples


@app.post("/transfer")
async def transfer(
    tts_audio:       UploadFile = File(...),
    reference:       UploadFile = File(...),
    energy_transfer: bool       = Form(True),
    frame_ms:        int        = Form(20),
    smooth_frames:   int        = Form(15),
):
    """
    TTS audio + referens → referens energiya dinamikasi bilan natija audio.

    WORLD ishlatilmaydi. Frame-by-frame RMS gain envelope qo'llanadi.
    OpenVoice / MMS sifati saqlanadi, faqat loudness dinamikasi ko'chiriladi.
    """
    tts_bytes = await tts_audio.read()
    ref_bytes = await reference.read()

    try:
        src = read_audio_mono(tts_bytes, TARGET_SR)
        ref = read_audio_mono(ref_bytes, TARGET_SR)

        if len(src) < TARGET_SR * 0.05:
            raise HTTPException(status_code=422, detail="TTS audio juda qisqa.")
        if len(ref) < TARGET_SR * 0.05:
            raise HTTPException(status_code=422, detail="Referens audio juda qisqa.")

        logger.info(f"Energy transfer: src={len(src)/TARGET_SR:.2f}s ref={len(ref)/TARGET_SR:.2f}s")

        if energy_transfer:
            out_audio = rms_gain_transfer(src, ref, TARGET_SR, frame_ms, smooth_frames)
        else:
            out_audio = src.copy()

        # Normalize — peak 0.95
        peak = np.abs(out_audio).max()
        if peak > 0:
            out_audio = out_audio / peak * 0.95

        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"
        sf.write(str(out_path), out_audio, TARGET_SR)

        logger.info(f"Transfer ok: {len(out_audio)/TARGET_SR:.2f}s → {out_path.name}")

        import asyncio
        resp = FileResponse(str(out_path), media_type="audio/wav", filename=out_path.name)
        async def _cleanup():
            await asyncio.sleep(60)
            out_path.unlink(missing_ok=True)
        asyncio.create_task(_cleanup())
        return resp

    except HTTPException:
        raise
    except Exception as e:
        import traceback
        logger.error(f"Transfer failed: {e}\n{traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=str(e))
