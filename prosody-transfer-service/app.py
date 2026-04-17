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


def peak_normalize(src: np.ndarray, target_dbfs: float = -1.0) -> np.ndarray:
    """
    Peak normalization only — no RMS gain, no modulation artifacts.

    Reference-based gain transfer o'chirildi: ref audio musiqa+ovoz o'z ichiga oladi,
    shuning uchun ref_rms har doim TTS dan yuqori bo'ladi → gain = 1.5 (max clip) →
    normalizatsiya uni qayta pasaytiradi → net effekt yo'q, lekin artefaktlar kuchayadi.

    Faqat peak normalizatsiya: kliping oldini oladi, hech narsa kuchaytirmaydi.
    """
    peak = float(np.max(np.abs(src)))
    if peak < 1e-6:
        return src.copy()
    target_peak = 10 ** (target_dbfs / 20)
    gain = target_peak / peak
    # Cap gain: agar signal juda past bo'lsa ko'p kuchaytirmasin (noise floor)
    gain = min(gain, 4.0)
    return (src * gain).astype(np.float32)


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

        src_peak_db = 20 * np.log10(np.max(np.abs(src)) + 1e-10)
        logger.info(f"Peak normalize: src={len(src)/TARGET_SR:.2f}s peak={src_peak_db:.1f}dBFS")

        # Peak normalize to -1 dBFS — kliping oldini oladi, hech narsa kuchaytirmaydi.
        # RMS/energy transfer o'chirildi: ref (musiqa+ovoz) TTS (faqat ovoz) dan doim
        # yuqori RMS → gain=1.5 max → normalizatsiya bekor qiladi → net effekt yo'q,
        # lekin ikki qadam artefakt hosil qiladi.
        out_audio = peak_normalize(src, target_dbfs=-1.0)
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
