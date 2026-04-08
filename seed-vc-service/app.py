"""
MMS TTS + Seed-VC voice conversion service.
- MMS TTS (facebook/mms-tts-uzb-script_cyrillic): Uzbek speech synthesis
- SeedVCWrapper: zero-shot voice conversion (replaces OpenVoice)
Port: 8005
"""

import io
import os
import sys
import uuid
import json
import shutil
import logging
from pathlib import Path
from typing import Optional

import numpy as np
import torch
import torchaudio
import soundfile as sf
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel

# Add Seed-VC repo to path
SEED_VC_DIR = Path("/workspace/seed-vc")
sys.path.insert(0, str(SEED_VC_DIR))

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

VOICES_PATH = Path("/workspace/mms-voices")
CACHE_PATH  = Path("/tmp/mms-cache")
VOICES_PATH.mkdir(parents=True, exist_ok=True)
CACHE_PATH.mkdir(parents=True, exist_ok=True)

DEVICE = "cuda" if torch.cuda.is_available() else "cpu"

_mms_model     = None
_mms_tokenizer = None
_vc_wrapper    = None  # SeedVCWrapper singleton


# ─── Uzbek Latin → Cyrillic ───────────────────────────────────────────────
_LAT2CYR = [
    ("shʼ", "шъ"), ("Shʼ", "Шъ"),
    ("sh", "ш"), ("Sh", "Ш"), ("SH", "Ш"),
    ("ch", "ч"), ("Ch", "Ч"), ("CH", "Ч"),
    ("ng", "нг"), ("Ng", "Нг"), ("NG", "НГ"),
    ("oʻ", "ў"), ("Oʻ", "Ў"), ("oʼ", "ў"), ("Oʼ", "Ў"), ("o'", "ў"), ("O'", "Ў"),
    ("gʻ", "ғ"), ("Gʻ", "Ғ"), ("gʼ", "ғ"), ("Gʼ", "Ғ"), ("g'", "ғ"), ("G'", "Ғ"),
    ("a", "а"), ("A", "А"), ("b", "б"), ("B", "Б"), ("d", "д"), ("D", "Д"),
    ("e", "е"), ("E", "Е"), ("f", "ф"), ("F", "Ф"), ("g", "г"), ("G", "Г"),
    ("h", "ҳ"), ("H", "Ҳ"), ("i", "и"), ("I", "И"), ("j", "ж"), ("J", "Ж"),
    ("k", "к"), ("K", "К"), ("l", "л"), ("L", "Л"), ("m", "м"), ("M", "М"),
    ("n", "н"), ("N", "Н"), ("o", "о"), ("O", "О"), ("p", "п"), ("P", "П"),
    ("q", "қ"), ("Q", "Қ"), ("r", "р"), ("R", "Р"), ("s", "с"), ("S", "С"),
    ("t", "т"), ("T", "Т"), ("u", "у"), ("U", "У"), ("v", "в"), ("V", "В"),
    ("x", "х"), ("X", "Х"), ("y", "й"), ("Y", "Й"), ("z", "з"), ("Z", "З"),
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


# ─── Model loading ────────────────────────────────────────────────────────
def load_mms():
    global _mms_model, _mms_tokenizer
    if _mms_model is not None:
        return
    logger.info("Loading MMS TTS (facebook/mms-tts-uzb-script_cyrillic)...")
    from transformers import VitsModel, AutoTokenizer
    _mms_tokenizer = AutoTokenizer.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model = VitsModel.from_pretrained("facebook/mms-tts-uzb-script_cyrillic").to(DEVICE)
    _mms_model.eval()
    logger.info("MMS TTS loaded")


def load_seed_vc():
    global _vc_wrapper
    if _vc_wrapper is not None:
        return
    logger.info("Loading SeedVCWrapper (downloads models on first run)...")
    os.chdir(str(SEED_VC_DIR))  # hf_utils saves checkpoints relative to cwd
    from seed_vc_wrapper import SeedVCWrapper
    _vc_wrapper = SeedVCWrapper(device=torch.device(DEVICE))
    os.chdir("/workspace/dubber")
    logger.info("SeedVCWrapper loaded")


@app.on_event("startup")
async def startup_event():
    import asyncio
    asyncio.create_task(asyncio.to_thread(_startup_load))

def _startup_load():
    try:
        load_mms()
        load_seed_vc()
    except Exception as e:
        logger.error(f"Startup load failed: {e}")
        import traceback; traceback.print_exc()


# ─── Endpoints ────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "model": "mms-tts-uzb+seed-vc",
        "mms_loaded": _mms_model is not None,
        "vc_loaded": _vc_wrapper is not None,
        "device": DEVICE,
    }


@app.get("/ready")
async def ready():
    load_mms()
    load_seed_vc()
    return {"status": "ready"}


@app.get("/voices")
async def list_voices():
    voices = []
    for vdir in VOICES_PATH.iterdir():
        if vdir.is_dir():
            meta_path = vdir / "meta.json"
            if meta_path.exists():
                meta = json.loads(meta_path.read_text())
                voices.append({"voice_id": vdir.name, **meta})
    return {"voices": voices}


@app.post("/clone")
async def clone_voice(
    audio: UploadFile = File(...),
    name: str = Form(...),
    description: str = Form(""),
    language: str = Form("uz"),
    ref_text: Optional[str] = Form(None),
):
    """Register a reference voice for Seed-VC conversion."""
    try:
        import hashlib
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        audio_bytes = await audio.read()
        data, sr = sf.read(io.BytesIO(audio_bytes), always_2d=True)
        data = data.mean(axis=1).astype(np.float32)

        # Save at 22050 Hz (Seed-VC default sr)
        if sr != 22050:
            wt = torch.from_numpy(data).unsqueeze(0)
            data = torchaudio.functional.resample(wt, sr, 22050).squeeze(0).numpy()

        # Clip to 25s (SeedVCWrapper clips reference to sr*25 internally)
        data = data[:25 * 22050]

        ref_path = voice_dir / "reference.wav"
        sf.write(str(ref_path), data, 22050)

        from datetime import datetime
        meta = {
            "name": name,
            "description": description,
            "language": language,
            "created_at": datetime.now().isoformat(),
        }
        (voice_dir / "meta.json").write_text(json.dumps(meta, indent=2))

        logger.info(f"Voice registered: {voice_id} ({name})")
        return {"ok": True, "voice_id": voice_id, "name": name, "ref_text": ref_text or ""}

    except Exception as e:
        logger.error(f"Clone failed: {e}")
        import traceback; traceback.print_exc()
        raise HTTPException(status_code=500, detail=str(e))


class SynthesizeRequest(BaseModel):
    text: str
    voice_id: str
    language: str = "uz"
    speed: float = 1.0
    tau: float = 0.7  # maps to inference_cfg_rate in Seed-VC


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """MMS TTS → Seed-VC voice conversion → WAV response."""
    try:
        if _mms_model is None:
            load_mms()
        if _vc_wrapper is None:
            load_seed_vc()

        voice_dir = VOICES_PATH / request.voice_id
        ref_path  = voice_dir / "reference.wav"
        if not ref_path.exists():
            raise HTTPException(status_code=404, detail=f"Voice {request.voice_id} not found")

        # 1. MMS TTS → Uzbek speech
        cyrillic_text = latin_to_cyrillic(request.text)
        logger.info(f"MMS: {request.text!r} → {cyrillic_text!r}")

        inputs = _mms_tokenizer(cyrillic_text, return_tensors="pt")
        inputs = {k: v.to(DEVICE) for k, v in inputs.items()}
        with torch.no_grad():
            waveform = _mms_model(**inputs).waveform.squeeze().cpu().numpy()

        mms_sr = _mms_model.config.sampling_rate  # 16000

        if len(waveform) < 1600:
            raise ValueError(f"MMS produced empty audio for: {cyrillic_text!r}")

        # Apply speed
        if request.speed != 1.0:
            wt = torch.from_numpy(waveform).unsqueeze(0)
            speed_sr = int(mms_sr * request.speed)
            waveform = torchaudio.functional.resample(wt, speed_sr, mms_sr).squeeze(0).numpy()

        # Save MMS output to temp file (Seed-VC reads from file path)
        src_path = CACHE_PATH / f"src_{uuid.uuid4()}.wav"
        sf.write(str(src_path), waveform.astype(np.float32), mms_sr)

        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"

        # 2. tau=0 → raw MMS, no conversion
        if request.tau <= 0.0:
            shutil.copy(str(src_path), str(out_path))
            src_path.unlink(missing_ok=True)
        else:
            # 3. Seed-VC voice conversion
            try:
                os.chdir(str(SEED_VC_DIR))
                result = _vc_wrapper.convert_voice(
                    source=str(src_path),
                    target=str(ref_path),
                    diffusion_steps=10,
                    length_adjust=1.0,
                    inference_cfg_rate=float(request.tau),
                    f0_condition=True,   # pitch matching — ayol/erkak farqi to'g'ri chiqadi
                    auto_f0_adjust=True, # reference pitch ga avtomatik moslashtiradi
                    stream_output=False,
                )
                os.chdir("/workspace/dubber")
                src_path.unlink(missing_ok=True)

                if result is None or (isinstance(result, np.ndarray) and len(result) < 100):
                    raise RuntimeError("Seed-VC returned empty audio")

                # f0_condition=True → 44100 Hz, False → 22050 Hz
                out_sr = 44100
                sf.write(str(out_path), result.astype(np.float32), out_sr)

            except Exception as e:
                logger.warning(f"Seed-VC failed ({e}), using raw MMS")
                os.chdir("/workspace/dubber")
                src_path.unlink(missing_ok=True)
                sf.write(str(out_path), waveform.astype(np.float32), mms_sr)

        if not out_path.exists() or out_path.stat().st_size < 500:
            raise HTTPException(status_code=500, detail="Synthesis produced invalid output")

        logger.info(f"Synthesis done: {out_path}")

        import asyncio
        response = FileResponse(str(out_path), media_type="audio/wav", filename=out_path.name)
        async def _cleanup():
            await asyncio.sleep(60)
            out_path.unlink(missing_ok=True)
        asyncio.create_task(_cleanup())
        return response

    except HTTPException:
        raise
    except Exception as e:
        import traceback
        logger.error(f"Synthesis failed: {e}\n{traceback.format_exc()}")
        raise HTTPException(status_code=500, detail=str(e))


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    vdir = VOICES_PATH / voice_id
    if vdir.exists():
        shutil.rmtree(vdir)
    return {"ok": True}
