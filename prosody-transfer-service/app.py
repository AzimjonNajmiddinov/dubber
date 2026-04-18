"""
Prosody Transfer Service — waveform-level energy matching (no WORLD vocoder).

POST /transfer
  tts_audio:       UploadFile  — Edge TTS chiqargan audio
  reference:       UploadFile  — bandpass-filtered original audio (300-3400Hz)
  energy_transfer: bool        — energy ko'chirish (default: true)
  frame_ms:        int         — frame hajmi ms da (default: 20)
  smooth_frames:   int         — gain smoothing (default: 80)

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

app = FastAPI(title="Prosody Transfer Service", version="3.0.0")


@app.get("/health")
def health():
    return {"status": "ok", "service": "prosody-transfer"}


def read_audio_mono(data: bytes, target_sr: int) -> np.ndarray:
    arr, sr = sf.read(io.BytesIO(data), always_2d=True)
    arr = arr.mean(axis=1).astype(np.float32)
    if sr != target_sr:
        g = math.gcd(sr, target_sr)
        arr = resample_poly(arr, target_sr // g, sr // g).astype(np.float32)
    return arr


def apply_energy_transfer(
    src: np.ndarray,
    ref: np.ndarray,
    frame_ms: int = 20,
    smooth_frames: int = 80,
    max_gain_db: float = 6.0,
    min_ref_rms: float = 0.01,
) -> np.ndarray:
    """
    Frame-by-frame RMS gain envelope transfer.

    - min_ref_rms: gaplar orasida (musiqa) referens energiyasi past bo'lsa
      TTS ni ko'p kuchaytirmasligi uchun pastki chegara.
    - max_gain_db: har bir frame uchun maksimal ±dB o'zgarish (pumping oldini oladi).
    - smooth_frames: gain envelope ni yumshatish — keskin o'tishlar yo'qoladi.
    """
    frame_len = int(TARGET_SR * frame_ms / 1000)

    def frame_rms(signal: np.ndarray) -> np.ndarray:
        n = max(1, len(signal) // frame_len)
        rms = np.zeros(n, dtype=np.float32)
        for i in range(n):
            chunk = signal[i * frame_len:(i + 1) * frame_len]
            rms[i] = float(np.sqrt(np.mean(chunk ** 2) + 1e-10))
        return rms

    src_rms = frame_rms(src)
    ref_rms = frame_rms(ref)

    # ref qisqa bo'lsa oxirgi qiymat bilan to'ldirish
    if len(ref_rms) < len(src_rms):
        ref_rms = np.pad(ref_rms, (0, len(src_rms) - len(ref_rms)), mode='edge')
    else:
        ref_rms = ref_rms[:len(src_rms)]

    # min_ref_rms: referens juda past bo'lsa (musiqa pauza, sukunat) katta boost yo'q
    ref_rms_floored = np.maximum(ref_rms, min_ref_rms)
    gain = ref_rms_floored / (src_rms + 1e-10)

    # ±max_gain_db ga clamp
    max_lin = 10 ** (max_gain_db / 20)
    gain = np.clip(gain, 1.0 / max_lin, max_lin)

    # Yumshoq envelope — keskin sakrashlar yo'qoladi
    gain = uniform_filter1d(gain, size=smooth_frames).astype(np.float32)

    out = src.copy()
    for i, g in enumerate(gain):
        start = i * frame_len
        end = min(start + frame_len, len(out))
        out[start:end] *= g

    return out


@app.post("/transfer")
async def transfer(
    tts_audio:       UploadFile = File(...),
    reference:       UploadFile = File(...),
    energy_transfer: bool       = Form(True),
    frame_ms:        int        = Form(20),
    smooth_frames:   int        = Form(80),
):
    tts_bytes = await tts_audio.read()
    ref_bytes = await reference.read()

    try:
        src = read_audio_mono(tts_bytes, TARGET_SR)
        ref = read_audio_mono(ref_bytes, TARGET_SR)

        if len(src) < TARGET_SR * 0.05:
            raise HTTPException(status_code=422, detail="TTS audio juda qisqa.")
        if len(ref) < TARGET_SR * 0.05:
            raise HTTPException(status_code=422, detail="Referens audio juda qisqa.")

        if energy_transfer:
            out_audio = apply_energy_transfer(src, ref, frame_ms=frame_ms, smooth_frames=smooth_frames)
        else:
            out_audio = src.copy()

        out_audio = np.clip(out_audio, -1.0, 1.0)

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
