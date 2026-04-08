"""
MMS TTS + Seed-VC voice conversion service.
- MMS TTS (facebook/mms-tts-uzb-script_cyrillic): Uzbek speech synthesis
- Seed-VC: zero-shot voice conversion (replaces OpenVoice)
Port: 8005
"""

import io
import os
import sys
import uuid
import json
import shutil
import logging
import hashlib
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
if SEED_VC_DIR.exists():
    sys.path.insert(0, str(SEED_VC_DIR))

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

VOICES_PATH = Path("/workspace/mms-voices")
CACHE_PATH  = Path("/tmp/mms-cache")
VOICES_PATH.mkdir(parents=True, exist_ok=True)
CACHE_PATH.mkdir(parents=True, exist_ok=True)

SEED_VC_WEIGHTS = Path("/workspace/seed-vc-weights")
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"

# Lazy singletons
_mms_model     = None
_mms_tokenizer = None
_vc_model      = None  # Seed-VC model


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
    # Normalize apostrophe variants
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
    logger.info("Loading MMS TTS...")
    from transformers import VitsModel, AutoTokenizer
    _mms_tokenizer = AutoTokenizer.from_pretrained("facebook/mms-tts-uzb-script_cyrillic")
    _mms_model = VitsModel.from_pretrained("facebook/mms-tts-uzb-script_cyrillic").to(DEVICE)
    _mms_model.eval()
    logger.info("MMS TTS loaded")


def load_seed_vc():
    global _vc_model
    if _vc_model is not None:
        return

    if not SEED_VC_DIR.exists():
        raise RuntimeError("Seed-VC repo not found at /workspace/seed-vc. Run setup.sh first.")

    logger.info("Loading Seed-VC model...")
    try:
        import yaml
        from modules.commons import recursive_munch, build_model
        from hf_utils import load_custom_model_from_hf

        # Use Seed-VC's own hf_utils to download + load the default model
        # This handles all caching automatically
        config_path = SEED_VC_DIR / "configs" / "presets" / "config_dit_mel_seed_uvit_whisper_small_wavenet.yml"
        if not config_path.exists():
            # Fallback: first available preset config
            configs = sorted((SEED_VC_DIR / "configs" / "presets").glob("*.yml"))
            config_path = configs[0] if configs else None

        if config_path is None:
            raise RuntimeError("No Seed-VC config found in configs/presets/")

        logger.info(f"Using config: {config_path.name}")

        with open(config_path) as f:
            config = yaml.safe_load(f)
        config = recursive_munch(config)

        model = build_model(config.model_params, stage="DiT")

        # Download checkpoint via hf_utils (Seed-VC's built-in HF downloader)
        ckpt_path = load_custom_model_from_hf(
            "Plachtaa/Seed-VC",
            "DiT_uvit_wav2vec2_small.pth",
            None,
        )
        logger.info(f"Checkpoint: {ckpt_path}")
        ckpt = torch.load(ckpt_path, map_location=DEVICE)
        model.load_state_dict(ckpt["model"] if "model" in ckpt else ckpt, strict=False)
        model = model.to(DEVICE).eval()

        _vc_model = {"model": model, "config": config}
        logger.info("Seed-VC loaded")

    except Exception as e:
        logger.error(f"Seed-VC load failed: {e}")
        import traceback; traceback.print_exc()
        raise


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


# ─── Endpoints ────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "model": "mms-tts-uzb+seed-vc",
        "mms_loaded": _mms_model is not None,
        "vc_loaded": _vc_model is not None,
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
    """Save reference audio for Seed-VC voice conversion."""
    try:
        voice_id = hashlib.md5(f"{name}_{uuid.uuid4()}".encode()).hexdigest()[:16]
        voice_dir = VOICES_PATH / voice_id
        voice_dir.mkdir(parents=True, exist_ok=True)

        audio_bytes = await audio.read()

        # Save as 22050Hz mono WAV
        import io as _io
        data, sr = sf.read(_io.BytesIO(audio_bytes), always_2d=True)
        data = data.mean(axis=1).astype(np.float32)
        if sr != 22050:
            wt = torch.from_numpy(data).unsqueeze(0)
            data = torchaudio.functional.resample(wt, sr, 22050).squeeze(0).numpy()

        # Clip to 30s
        max_samples = 30 * 22050
        if len(data) > max_samples:
            data = data[:max_samples]

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
    tau: float = 0.7  # diffusion cfg rate (similar role to tau in OpenVoice)


@app.post("/synthesize")
async def synthesize(request: SynthesizeRequest):
    """MMS TTS → Seed-VC voice conversion."""
    try:
        if _mms_model is None:
            load_mms()
        if _vc_model is None:
            load_seed_vc()

        voice_dir = VOICES_PATH / request.voice_id
        ref_path = voice_dir / "reference.wav"
        if not ref_path.exists():
            raise HTTPException(status_code=404, detail=f"Voice {request.voice_id} not found")

        # 1. MMS TTS → Uzbek speech (16kHz)
        cyrillic_text = latin_to_cyrillic(request.text)
        logger.info(f"MMS TTS: {request.text!r} → {cyrillic_text!r}")

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

        # Save source audio (16kHz, as Seed-VC expects)
        src_path = CACHE_PATH / f"src_{uuid.uuid4()}.wav"
        sf.write(str(src_path), waveform.astype(np.float32), mms_sr)

        out_path = CACHE_PATH / f"{uuid.uuid4()}.wav"

        # 2. tau=0 → skip voice conversion, return raw MMS
        if request.tau <= 0.0:
            shutil.copy(str(src_path), str(out_path))
            src_path.unlink(missing_ok=True)
        else:
            # 3. Seed-VC voice conversion
            try:
                _seed_vc_convert(
                    src_path=str(src_path),
                    ref_path=str(ref_path),
                    out_path=str(out_path),
                    cfg_rate=float(request.tau),  # tau maps to inference_cfg_rate
                )
                src_path.unlink(missing_ok=True)
                if not out_path.exists() or out_path.stat().st_size < 500:
                    raise RuntimeError("Seed-VC output invalid")
            except Exception as e:
                logger.warning(f"Seed-VC conversion failed ({e}), using raw MMS")
                src_path.unlink(missing_ok=True)
                sf.write(str(out_path), waveform.astype(np.float32), mms_sr)

        if not out_path.exists() or out_path.stat().st_size < 500:
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


def _seed_vc_convert(src_path: str, ref_path: str, out_path: str, cfg_rate: float = 0.7,
                     diffusion_steps: int = 30, length_adjust: float = 1.0):
    """Run Seed-VC voice conversion."""
    model   = _vc_model["model"]
    config  = _vc_model["config"]

    # Load source and reference audio
    source, src_sr = torchaudio.load(src_path)
    ref,    ref_sr = torchaudio.load(ref_path)

    # Resample to model sample rate (typically 22050 or 44100)
    model_sr = getattr(config.preprocess_params, "sr", 22050)

    if src_sr != model_sr:
        source = torchaudio.functional.resample(source, src_sr, model_sr)
    if ref_sr != model_sr:
        ref = torchaudio.functional.resample(ref, ref_sr, model_sr)

    # Mono
    if source.shape[0] > 1:
        source = source.mean(0, keepdim=True)
    if ref.shape[0] > 1:
        ref = ref.mean(0, keepdim=True)

    source = source.to(DEVICE)
    ref    = ref.to(DEVICE)

    # Run Seed-VC inference
    # The exact API depends on the model type loaded.
    # Try the standard Seed-VC v2 inference pattern.
    with torch.no_grad():
        # Try calling model directly (some versions expose __call__ with these args)
        try:
            output = model.inference(
                source=source,
                target=ref,
                diffusion_steps=diffusion_steps,
                length_adjust=length_adjust,
                inference_cfg_rate=cfg_rate,
            )
        except AttributeError:
            # Fallback: use the seed-vc inference script directly
            output = _seed_vc_script_inference(src_path, ref_path, cfg_rate, diffusion_steps)
            torchaudio.save(out_path, output, model_sr)
            return

    if isinstance(output, torch.Tensor):
        if output.dim() == 1:
            output = output.unsqueeze(0)
        torchaudio.save(out_path, output.cpu(), model_sr)
    else:
        # numpy array
        sf.write(out_path, output, model_sr)


def _seed_vc_script_inference(src_path: str, ref_path: str, cfg_rate: float, diffusion_steps: int) -> torch.Tensor:
    """Fallback: use Seed-VC's inference.py script via subprocess."""
    import subprocess
    tmp_out = str(CACHE_PATH / f"seedvc_{uuid.uuid4()}.wav")

    result = subprocess.run(
        [
            sys.executable,
            str(SEED_VC_DIR / "inference.py"),
            "--source", src_path,
            "--target", ref_path,
            "--output", tmp_out,
            "--diffusion-steps", str(diffusion_steps),
            "--inference-cfg-rate", str(cfg_rate),
            "--length-adjust", "1.0",
        ],
        capture_output=True, text=True, cwd=str(SEED_VC_DIR)
    )

    if result.returncode != 0 or not Path(tmp_out).exists():
        raise RuntimeError(f"Seed-VC script failed: {result.stderr[-500:]}")

    audio, sr = torchaudio.load(tmp_out)
    Path(tmp_out).unlink(missing_ok=True)
    return audio


@app.delete("/voices/{voice_id}")
async def delete_voice(voice_id: str):
    vdir = VOICES_PATH / voice_id
    if vdir.exists():
        shutil.rmtree(vdir)
    return {"ok": True}
