import shutil
import subprocess
from pathlib import Path

from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI()
BASE = Path("/var/www/storage/app").resolve()


class SeparateReq(BaseModel):
    input_rel: str
    video_id: int
    model: str = "htdemucs"
    two_stems: str = "vocals"


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
    }


@app.post("/separate")
def separate(req: SeparateReq):
    inp = (BASE / req.input_rel).resolve()
    if not inp.exists():
        return {"ok": False, "error": f"Input not found: {req.input_rel}"}

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
        str(inp),
    ]

    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
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

    return {
        "ok": True,
        "no_vocals_rel": f"audio/stems/{req.video_id}/{no_vocals.name}",
        "vocals_rel": f"audio/stems/{req.video_id}/{vocals.name}" if vocals else None,
    }
