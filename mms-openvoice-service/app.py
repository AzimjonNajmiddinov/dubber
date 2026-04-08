"""
MMS TTS + OpenVoice v2 service.
- MMS TTS (facebook/mms-tts-uzb): trainsiz o'zbek nutq sintezi
- OpenVoice v2 ToneColorConverter: reference ovoz tembri klonlash
Port: 8005
"""

import io
import os
import uuid
import json
import logging
import hashlib
from pathlib import Path

import numpy as np
import torch
import soundfile as sf
from scipy.signal import resample_poly
import math
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel
from typing import Optional

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Uzbek Latin → Cyrillic transliteration (official standard)
_LAT2CYR = [
    ("shʼ", "шъ"), ("Shʼ", "Шъ"),
    ("sh", "ш"), ("Sh", "Ш"), ("SH", "Ш"),
    ("ch", "ч"), ("Ch", "Ч"), ("CH", "Ч"),
    ("ng", "нг"), ("Ng", "Нг"), ("NG", "НГ"),
    ("oʻ", "ў"), ("Oʻ", "Ў"), ("oʼ", "ў"), ("Oʼ", "Ў"), ("o'", "ў"), ("O'", "Ў"),
    ("gʻ", "ғ"), ("Gʻ", "Ғ"), ("gʼ", "ғ"), ("Gʼ", "Ғ"), ("g'", "ғ"), ("G'", "Ғ"),
    ("a", "а"), ("A", "А"),
    ("b", "б"), ("B", "Б"),
    ("d", "д"), ("D", "Д"),
    ("e", "е"), ("E", "Е"),
    ("f", "ф"), ("F", "Ф"),
    ("g", "г"), ("G", "Г"),
    ("h", "ҳ"), ("H", "Ҳ"),
    ("i", "и"), ("I", "И"),
    ("j", "ж"), ("J", "Ж"),
    ("k", "к"), ("K", "К"),
    ("l", "л"), ("L", "Л"),
    ("m", "м"), ("M", "М"),
    ("n", "н"), ("N", "Н"),
    ("o", "о"), ("O", "О"),
    ("p", "п"), ("P", "П"),
    ("q", "қ"), ("Q", "Қ"),
    ("r", "р"), ("R", "Р"),
    ("s", "с"), ("S", "С"),
    ("t", "т"), ("T", "Т"),
    ("u", "у"), ("U", "У"),
    ("v", "в"), ("V", "В"),
    ("x", "х"), ("X", "Х"),
    ("y", "й"), ("Y", "Й"),
    ("z", "з"), ("Z", "З"),
]

def latin_to_cyrillic(text: str) -> str:
    """Convert Uzbek Latin script to Cyrillic for MMS TTS model."""
    for lat, cyr in _LAT2CYR:
        text = text.replace(lat, cyr)
    return text

app = FastAPI()

VOICES_PATH = Path("/workspace/mms-voices")
CACHE_PATH  = Path("/tmp/mms-cache")
VOICES_PATH.mkdir(parents=True, exist_ok=True)
CACHE_PATH.mkdir(parents=True, exist_ok=True)

OPENVOICE_CKPT = Path("/workspace/openvoice-v2/checkpoints_v2/converter")

_mms_model     = None
_mms_tokenizer = None
_ov_converter  = None


def load_models():
    global _mms_model, _mms_tokenizer, _ov_converter

    logger.info("Loading MMS TTS (facebook/mms-tts-uzb)...")
    from transformers import VitsModel, AutoTokenizer
    _mms_tokenizer = AutoTokenizer.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model = VitsModel.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model.eval()
    logger.info("MMS TTS loaded")

    logger.info("Loading OpenVoice v2 ToneColorConverter...")
    from openvoice.api import ToneColorConverter
    _ov_converter = ToneColorConverter(
        str(OPENVOICE_CKPT / "config.json"),
        device="cuda" if torch.cuda.is_available() else "cpu",
    )
    _ov_converter.load_ckpt(str(OPENVOICE_CKPT / "checkpoint.pth"))
    logger.info("OpenVoice v2 loaded")


@app.on_event("startup")
async def startup_event():
    import asyncio
    asyncio.create_task(asyncio.to_thread(load_models))


@app.get("/health")
async def health():
    return {"status": "healthy", "model": "mms-tts-uzb+openvoice-v2"}


@app.get("/ready")
async def ready():
    load_models()
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


def _to_mono_22k(audio_bytes: bytes) -> np.ndarray:
    """Read any audio, convert to mono 22050 Hz float32."""
    data, sr = sf.read(io.BytesIO(audio_bytes), always_2d=True)
    data = data.mean(axis=1).astype(np.float32)
    if sr != 22050:
        g = math.gcd(sr, 22050)
        data = resample_poly(data, 22050 // g, sr // g).astype(np.float32)
    return data


@app.post("/clone")
async def clone_voice(
    audio: UploadFile = File(...),
    name: str = Form(...),
    description: str = Form(""),
    language: str = Form("uz"),
    ref_text: Optional[str] = Form(None),
):
    """Save reference audio and extract OpenVoice tone color embedding."""
    try:
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        audio_bytes = await audio.read()
        data = _to_mono_22k(audio_bytes)

        # Clip to 30s
        max_samples = 30 * 22050
        if len(data) > max_samples:
            data = data[:max_samples]

        ref_path = voice_dir / "reference.wav"
        sf.write(str(ref_path), data, 22050)

        # Extract tone color embedding from reference audio
        from openvoice import se_extractor
        target_se, _ = se_extractor.get_se(
            str(ref_path), _ov_converter, vad=True
        )
        se_path = voice_dir / "target_se.pth"
        torch.save(target_se, str(se_path))

        from datetime import datetime
        meta = {
            "name": name,
            "description": description,
            "language": language,
            "created_at": datetime.now().isoformat(),
        }
        (voice_dir / "meta.json").write_text(json.dumps(meta, indent=2))

        logger.info(f"Voice cloned: {voice_id} ({name})")
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


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """MMS TTS → Uzbek audio → OpenVoice tone color convert → cloned voice."""
    try:
        if _mms_model is None or _ov_converter is None:
            raise HTTPException(status_code=503, detail="Models not loaded yet")

        voice_dir = VOICES_PATH / request.voice_id
        se_path = voice_dir / "target_se.pth"
        ref_path = voice_dir / "reference.wav"
        if not se_path.exists():
            raise HTTPException(status_code=404, detail=f"Voice {request.voice_id} not found")

        # 1. MMS TTS — convert Latin→Cyrillic, then generate Uzbek speech at 16kHz
        cyrillic_text = latin_to_cyrillic(request.text)
        logger.info(f"MMS TTS: {request.text!r} → {cyrillic_text!r}")
        inputs = _mms_tokenizer(cyrillic_text, return_tensors="pt")
        with torch.no_grad():
            waveform = _mms_model(**inputs).waveform.squeeze().cpu().numpy()

        mms_sr = _mms_model.config.sampling_rate  # 16000

        # 2. Resample to 22050 Hz for OpenVoice
        g = math.gcd(mms_sr, 22050)
        waveform_22k = resample_poly(waveform, 22050 // g, mms_sr // g).astype(np.float32)

        # Apply speed (resample to change duration)
        if request.speed != 1.0:
            target_len = int(len(waveform_22k) / request.speed)
            waveform_22k = resample_poly(
                waveform_22k, target_len, len(waveform_22k)
            ).astype(np.float32)

        # Save MMS output temporarily
        src_path = CACHE_PATH / f"src_{uuid.uuid4()}.wav"
        sf.write(str(src_path), waveform_22k, 22050)

        # 3. OpenVoice tone color conversion
        from openvoice import se_extractor
        target_se = torch.load(str(se_path))
        src_se, _ = se_extractor.get_se(str(src_path), _ov_converter, vad=False)

        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"
        _ov_converter.convert(
            audio_src_path=str(src_path),
            src_se=src_se,
            tgt_se=target_se,
            output_path=str(out_path),
            tau=0.3,
        )

        src_path.unlink(missing_ok=True)

        if not out_path.exists() or out_path.stat().st_size < 1000:
            raise HTTPException(status_code=500, detail="Synthesis produced invalid output")

        logger.info(f"Synthesis complete: {out_path}")

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
    import shutil
    vdir = VOICES_PATH / voice_id
    if vdir.exists():
        shutil.rmtree(vdir)
    return {"ok": True}
