"""
Voice Clone Service — MMS TTS + WORLD prosody transfer.

POST /synthesize
  text:            str         — gapirilishi kerak bo'lgan matn (Latin yoki Cyrillic)
  reference:       UploadFile  — aktyor ovozi (referens WAV/MP3)
  lang:            str         — til kodi (default: uzb-script_cyrillic)
  f0_mode:         str         — "contour" | "stats"  (default: contour)
  energy_transfer: bool        — energiya ko'chirish (default: true)

GET /health

Port: 8007
"""

import io
import math
import uuid
import logging
from pathlib import Path

import numpy as np
import pyworld as pw
import soundfile as sf
import torch
from scipy.signal import resample_poly
from scipy.interpolate import interp1d
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

CACHE_PATH = Path("/tmp/voice-clone-cache")
CACHE_PATH.mkdir(parents=True, exist_ok=True)

WORLD_SR = 22050

app = FastAPI(title="Voice Clone Service", version="1.0.0")

_mms_model     = None
_mms_tokenizer = None

# ── Latin → Cyrillic transliteration ──────────────────────────────────────────
_LAT2CYR = [
    ("shʼ", "шъ"), ("Shʼ", "Шъ"),
    ("sh", "ш"), ("Sh", "Ш"), ("SH", "Ш"),
    ("ch", "ч"), ("Ch", "Ч"), ("CH", "Ч"),
    ("ng", "нг"), ("Ng", "Нг"), ("NG", "НГ"),
    ("oʻ", "ў"), ("Oʻ", "Ў"), ("oʼ", "ў"), ("Oʼ", "Ў"), ("o'", "ў"), ("O'", "Ў"),
    ("gʻ", "ғ"), ("Gʻ", "Ғ"), ("gʼ", "ғ"), ("Gʼ", "Ғ"), ("g'", "ғ"), ("G'", "Ғ"),
    ("a","а"),("A","А"),("b","б"),("B","Б"),("d","д"),("D","Д"),
    ("e","е"),("E","Е"),("f","ф"),("F","Ф"),("g","г"),("G","Г"),
    ("h","ҳ"),("H","Ҳ"),("i","и"),("I","И"),("j","ж"),("J","Ж"),
    ("k","к"),("K","К"),("l","л"),("L","Л"),("m","м"),("M","М"),
    ("n","н"),("N","Н"),("o","о"),("O","О"),("p","п"),("P","П"),
    ("q","қ"),("Q","Қ"),("r","р"),("R","Р"),("s","с"),("S","С"),
    ("t","т"),("T","Т"),("u","у"),("U","У"),("v","в"),("V","В"),
    ("x","х"),("X","Х"),("y","й"),("Y","Й"),("z","з"),("Z","З"),
]

def latin_to_cyrillic(text: str) -> str:
    text = (text
        .replace('\u2018', "'").replace('\u2019', "'")
        .replace('\u02bb', "'").replace('\u02bc', "'")
        .replace('\u0060', "'").replace('\u00b4', "'")
        .replace('\u201c', '"').replace('\u201d', '"')
    )
    for lat, cyr in _LAT2CYR:
        text = text.replace(lat, cyr)
    return text

# ── Model loading ──────────────────────────────────────────────────────────────
def load_models():
    global _mms_model, _mms_tokenizer
    logger.info("Loading MMS TTS (facebook/mms-tts-uzb-script_cyrillic)...")
    from transformers import VitsModel, AutoTokenizer
    _mms_tokenizer = AutoTokenizer.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model     = VitsModel.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model.eval()
    logger.info("MMS TTS loaded ✓")

@app.on_event("startup")
async def on_startup():
    import asyncio
    asyncio.create_task(asyncio.to_thread(load_models))

@app.get("/health")
def health():
    ready = _mms_model is not None
    return {"status": "ok" if ready else "loading", "service": "voice-clone"}

# ── Audio helpers ──────────────────────────────────────────────────────────────
def read_mono_f64(data: bytes, target_sr: int) -> np.ndarray:
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

# ── Prosody transfer ───────────────────────────────────────────────────────────
def transfer_f0_contour(f0_src: np.ndarray, f0_ref: np.ndarray) -> np.ndarray:
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

def transfer_f0_stats(f0_src: np.ndarray, f0_ref: np.ndarray) -> np.ndarray:
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

def transfer_energy(sp_src: np.ndarray, sp_ref: np.ndarray) -> np.ndarray:
    n_src, n_ref = len(sp_src), len(sp_ref)
    e_src = np.sqrt(np.mean(sp_src ** 2, axis=1)) + 1e-8
    e_ref = np.sqrt(np.mean(sp_ref ** 2, axis=1)) + 1e-8
    src_t = np.linspace(0, 1, n_src)
    ref_t = np.linspace(0, 1, n_ref)
    e_ref_s = interp1d(ref_t, e_ref, kind='linear',
                       bounds_error=False, fill_value='extrapolate')(src_t)
    ratio = np.clip(e_ref_s / e_src, 0.2, 5.0)
    return sp_src * ratio[:, np.newaxis]

# ── Main endpoint ──────────────────────────────────────────────────────────────
@app.post("/synthesize")
async def synthesize(
    text:            str        = Form(...),
    reference:       UploadFile = File(...),
    lang:            str        = Form("uzb-script_cyrillic"),
    f0_mode:         str        = Form("contour"),
    energy_transfer: bool       = Form(True),
):
    """
    1. MMS TTS: text → Uzbek speech (16 kHz)
    2. WORLD prosody: reference F0 + energy → MMS output
    3. Blend 75% prosody + 25% MMS (consonant clarity)
    4. Return WAV
    """
    if _mms_model is None or _mms_tokenizer is None:
        raise HTTPException(status_code=503, detail="Models not loaded yet. Retry in 30s.")

    ref_bytes = await reference.read()

    try:
        # ── 1. MMS TTS ────────────────────────────────────────────────────────
        cyrillic = latin_to_cyrillic(text)
        logger.info(f"Synthesize: {text!r} → {cyrillic!r}")

        inputs = _mms_tokenizer(cyrillic, return_tensors="pt")
        with torch.no_grad():
            waveform = _mms_model(**inputs).waveform.squeeze().cpu().numpy()

        mms_sr = _mms_model.config.sampling_rate  # 16000

        # Resample MMS output to WORLD_SR
        g = math.gcd(mms_sr, WORLD_SR)
        src = resample_poly(waveform, WORLD_SR // g, mms_sr // g).astype(np.float64)

        if len(src) < WORLD_SR * 0.05:
            raise HTTPException(status_code=422, detail="MMS produced empty audio for this text.")

        # ── 2. Load reference ─────────────────────────────────────────────────
        ref = read_mono_f64(ref_bytes, WORLD_SR)
        if len(ref) < WORLD_SR * 0.05:
            raise HTTPException(status_code=422, detail="Reference audio too short.")

        logger.info(f"WORLD analyze: src={len(src)/WORLD_SR:.2f}s ref={len(ref)/WORLD_SR:.2f}s")

        # ── 3. WORLD analysis ─────────────────────────────────────────────────
        f0_src, sp_src, ap_src, _ = world_analyze(src, WORLD_SR)
        f0_ref, sp_ref, _,      _ = world_analyze(ref, WORLD_SR)

        # ── 4. Prosody transfer ───────────────────────────────────────────────
        f0_out = (transfer_f0_contour(f0_src, f0_ref) if f0_mode == "contour"
                  else transfer_f0_stats(f0_src, f0_ref))
        sp_out = transfer_energy(sp_src, sp_ref) if energy_transfer else sp_src

        # ── 5. WORLD synthesis ────────────────────────────────────────────────
        out_audio = pw.synthesize(f0_out, sp_out, ap_src, WORLD_SR).astype(np.float32)

        # 75% prosody + 25% original MMS — preserves consonant intelligibility
        n_out, n_src_len = len(out_audio), len(src)
        if n_out != n_src_len:
            g2 = math.gcd(n_src_len, n_out)
            src_r = resample_poly(src, n_out // g2, n_src_len // g2).astype(np.float32)
        else:
            src_r = src.astype(np.float32)

        out_audio = out_audio * 0.75 + src_r * 0.25

        # Normalize
        peak = np.abs(out_audio).max()
        if peak > 0:
            out_audio = out_audio / peak * 0.95

        # ── 6. Save & return ──────────────────────────────────────────────────
        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"
        sf.write(str(out_path), out_audio, WORLD_SR)

        logger.info(f"Done: {len(out_audio)/WORLD_SR:.2f}s → {out_path.name}")

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
        logger.error(f"Synthesize failed: {e}\n{traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=str(e))
