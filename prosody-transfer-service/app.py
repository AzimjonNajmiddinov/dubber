"""
Prosody Transfer Service — WORLD vocoder (pitch + energy transfer).

POST /transfer
  tts_audio:       UploadFile  — Edge TTS chiqargan audio
  reference:       UploadFile  — bandpass-filtered (300-3400Hz) original audio
  f0_mode:         str         — "stats" | "contour"  (default: stats)
  energy_transfer: bool        — energy ko'chirish (default: true)

Port: 8006
"""

import io
import uuid
import logging
import math
from pathlib import Path

import numpy as np
import pyworld as pw
import soundfile as sf
from scipy.signal import resample_poly
from scipy.interpolate import interp1d
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CACHE_PATH = Path("/tmp/prosody-cache")
CACHE_PATH.mkdir(parents=True, exist_ok=True)

WORLD_SR = 22050

app = FastAPI(title="Prosody Transfer Service", version="4.0.0")


@app.get("/health")
def health():
    return {"status": "ok", "service": "prosody-transfer"}


def read_audio_mono(data: bytes, target_sr: int) -> np.ndarray:
    arr, sr = sf.read(io.BytesIO(data), always_2d=True)
    arr = arr.mean(axis=1).astype(np.float64)
    if sr != target_sr:
        g = math.gcd(sr, target_sr)
        arr = resample_poly(arr, target_sr // g, sr // g).astype(np.float64)
    return arr


def world_analyze(audio: np.ndarray, sr: int):
    f0, t = pw.dio(audio, sr)
    f0    = pw.stonemask(audio, f0, t, sr)
    sp    = pw.cheaptrick(audio, f0, t, sr)
    ap    = pw.d4c(audio, f0, t, sr)
    return f0, sp, ap, t


def transfer_f0_stats(f0_src: np.ndarray, f0_ref: np.ndarray) -> np.ndarray:
    """Pitch o'rtachasi va o'zgaruvchanligini ko'chirish — musiqa bor referens uchun xavfsiz."""
    voiced_src = f0_src[f0_src > 0]
    voiced_ref = f0_ref[f0_ref > 0]
    if len(voiced_src) == 0 or len(voiced_ref) == 0:
        return f0_src.copy()
    mean_src, std_src = voiced_src.mean(), voiced_src.std() + 1e-8
    mean_ref, std_ref = voiced_ref.mean(), voiced_ref.std() + 1e-8
    f0_out = f0_src.copy()
    mask = f0_src > 0
    f0_out[mask] = (f0_src[mask] - mean_src) / std_src * std_ref + mean_ref
    return np.maximum(f0_out, 0.0)


def transfer_f0_contour(f0_src: np.ndarray, f0_ref: np.ndarray) -> np.ndarray:
    """Referens pitch konturini to'liq ko'chirish — toza referens uchun."""
    voiced_ref = f0_ref[f0_ref > 0]
    if len(voiced_ref) == 0:
        return f0_src.copy()

    n_src, n_ref = len(f0_src), len(f0_ref)
    ref_times = np.linspace(0, 1, n_ref)
    src_times = np.linspace(0, 1, n_src)

    ref_nonzero = np.where(f0_ref > 0)[0]
    if len(ref_nonzero) > 1:
        fn = interp1d(ref_times[ref_nonzero], f0_ref[ref_nonzero],
                      kind='linear', bounds_error=False,
                      fill_value=(f0_ref[ref_nonzero[0]], f0_ref[ref_nonzero[-1]]))
        ref_filled = fn(ref_times)
    else:
        ref_filled = np.where(f0_ref > 0, f0_ref, voiced_ref.mean())

    fn2 = interp1d(ref_times, ref_filled, kind='linear',
                   bounds_error=False, fill_value='extrapolate')
    f0_stretched = fn2(src_times)

    f0_out = f0_src.copy()
    mask = f0_src > 0
    f0_out[mask] = np.maximum(f0_stretched[mask], 1.0)

    v_out = f0_out[f0_out > 0]
    if len(v_out) > 0 and len(voiced_ref) > 0:
        f0_out[f0_out > 0] *= voiced_ref.mean() / (v_out.mean() + 1e-8)

    return np.maximum(f0_out, 0.0)


def transfer_energy(sp_src: np.ndarray, sp_ref: np.ndarray) -> np.ndarray:
    """Spektral energiya ko'chirish — temporal dinamikani saqlaydi."""
    n_src = len(sp_src)
    n_ref = len(sp_ref)

    e_src = np.sqrt(np.mean(sp_src ** 2, axis=1)) + 1e-8
    e_ref = np.sqrt(np.mean(sp_ref ** 2, axis=1)) + 1e-8

    src_t = np.linspace(0, 1, n_src)
    ref_t = np.linspace(0, 1, n_ref)
    e_ref_s = interp1d(ref_t, e_ref, kind='linear',
                       bounds_error=False, fill_value='extrapolate')(src_t)

    # ±6dB clamp (0.5x – 2.0x)
    ratio = np.clip(e_ref_s / e_src, 0.5, 2.0)
    return sp_src * ratio[:, np.newaxis]


@app.post("/transfer")
async def transfer(
    tts_audio:       UploadFile = File(...),
    reference:       UploadFile = File(...),
    f0_mode:         str        = Form("stats"),
    energy_transfer: bool       = Form(True),
):
    tts_bytes = await tts_audio.read()
    ref_bytes = await reference.read()

    try:
        src = read_audio_mono(tts_bytes, WORLD_SR)
        ref = read_audio_mono(ref_bytes, WORLD_SR)

        if len(src) < WORLD_SR * 0.05:
            raise HTTPException(status_code=422, detail="TTS audio juda qisqa.")
        if len(ref) < WORLD_SR * 0.05:
            raise HTTPException(status_code=422, detail="Referens audio juda qisqa.")

        logger.info(f"WORLD analyze: src={len(src)/WORLD_SR:.2f}s ref={len(ref)/WORLD_SR:.2f}s f0={f0_mode}")

        f0_src, sp_src, ap_src, _ = world_analyze(src, WORLD_SR)
        f0_ref, sp_ref, _,      _ = world_analyze(ref, WORLD_SR)

        f0_out = transfer_f0_contour(f0_src, f0_ref) if f0_mode == "contour" \
                 else transfer_f0_stats(f0_src, f0_ref)

        sp_out = transfer_energy(sp_src, sp_ref) if energy_transfer else sp_src

        out_audio = pw.synthesize(f0_out, sp_out, ap_src, WORLD_SR).astype(np.float32)

        # 75% WORLD + 25% original — undosh tovushlar aniqligini saqlash uchun
        n_out, n_src_len = len(out_audio), len(src)
        if n_out != n_src_len:
            g = math.gcd(n_src_len, n_out)
            src_r = resample_poly(src, n_out // g, n_src_len // g).astype(np.float32)
        else:
            src_r = src.astype(np.float32)
        out_audio = out_audio * 0.75 + src_r * 0.25

        peak = np.abs(out_audio).max()
        if peak > 0:
            out_audio = (out_audio / peak * 0.95).astype(np.float32)

        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"
        sf.write(str(out_path), out_audio, WORLD_SR)

        logger.info(f"Transfer ok: {len(out_audio)/WORLD_SR:.2f}s → {out_path.name}")

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
