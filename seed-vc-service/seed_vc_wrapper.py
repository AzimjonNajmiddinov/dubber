"""
SeedVCWrapper — Seed-VC (Plachtaa/SEED-VC) uchun yupqa o'rov.

Seed-VC reposi /workspace/seed-vc da bo'lishi kerak:
    git clone https://github.com/Plachtaa/SEED-VC /workspace/seed-vc

Foydalanish:
    wrapper = SeedVCWrapper(device=torch.device("cuda"))
    for mp3_chunk, full_audio in wrapper.convert_voice(
        source="source.wav", target="reference.wav",
        diffusion_steps=10, inference_cfg_rate=0.7,
        f0_condition=True, auto_f0_adjust=True,
        stream_output=True,
    ):
        if full_audio is not None:
            sr, audio_np = full_audio
"""

import io
import os
import sys
import logging
from pathlib import Path
from typing import Generator, Optional, Tuple

import numpy as np
import torch
import soundfile as sf

logger = logging.getLogger(__name__)

SEED_VC_DIR = Path("/workspace/seed-vc")


class SeedVCWrapper:
    """
    Zero-shot voice conversion via Seed-VC.
    Seed-VC reposi sys.path da bo'lishi kerak (SEED_VC_DIR).
    """

    def __init__(self, device: Optional[torch.device] = None):
        if device is None:
            device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
        self.device = device

        if str(SEED_VC_DIR) not in sys.path:
            sys.path.insert(0, str(SEED_VC_DIR))

        # Working directory must be seed-vc for relative model paths to work
        self._orig_dir = os.getcwd()
        os.chdir(str(SEED_VC_DIR))
        try:
            self._load()
        finally:
            os.chdir(self._orig_dir)

    def _load(self):
        """Load model weights. Must be called from SEED_VC_DIR."""
        logger.info("Seed-VC model loading...")

        # Try the newer inference_main.py API first (repo post-2024)
        try:
            from inference_main import load_models as _load_models
            (
                self._model,
                self._codec_model,
                self._hift_gen,
                self._vc_fn,
                self._sr,
            ) = _load_models(device=str(self.device))
            self._api = "inference_main"
            logger.info("Seed-VC loaded via inference_main API")
            return
        except Exception as e:
            logger.debug(f"inference_main API failed: {e}")

        # Fall back to direct model build (repo pre-2025)
        try:
            self._load_direct()
            logger.info("Seed-VC loaded via direct API")
            return
        except Exception as e:
            logger.error(f"Direct API also failed: {e}")
            raise RuntimeError(
                "Could not load Seed-VC. Make sure the repo is at "
                "/workspace/seed-vc and dependencies are installed."
            ) from e

    def _load_direct(self):
        """Direct model loading — compatible with multiple Seed-VC versions."""
        from hf_utils import load_custom_model_from_hf

        # DiT-based VC model (current default)
        dit_ckpt = load_custom_model_from_hf(
            "Plachtaa/Seed-VC", "DiT_uvit_wav2vec2_small.pth", None
        )
        cfg_path = load_custom_model_from_hf(
            "Plachtaa/Seed-VC", "config_dit_mel_seed_uvit_wav2vec2_small.yml", None
        )

        import yaml
        with open(cfg_path) as f:
            cfg = yaml.safe_load(f)

        # Build model from config
        from modules.commons import build_model, load_checkpoint
        model = build_model(cfg["model_params"])
        load_checkpoint(model, dit_ckpt)
        model = model.to(self.device).eval()
        self._dit_model = model
        self._cfg = cfg

        # Codec / vocoder
        codec_ckpt = load_custom_model_from_hf(
            "Plachtaa/Seed-VC", "vec2wav2_ckpt.pth", None
        )
        codec_cfg_path = load_custom_model_from_hf(
            "Plachtaa/Seed-VC", "config_vec2wav2.yml", None
        )
        with open(codec_cfg_path) as f:
            codec_cfg = yaml.safe_load(f)

        from modules.commons import build_model as _bm, load_checkpoint as _lc
        codec = _bm(codec_cfg["model_params"])
        _lc(codec, codec_ckpt)
        self._codec = codec.to(self.device).eval()
        self._sr = cfg.get("preprocess_params", {}).get("sr", 22050)
        self._api = "direct"

    # ──────────────────────────────────────────────────────────────────────────
    def convert_voice(
        self,
        source: str,
        target: str,
        diffusion_steps: int = 10,
        length_adjust: float = 1.0,
        inference_cfg_rate: float = 0.7,
        f0_condition: bool = True,
        auto_f0_adjust: bool = True,
        stream_output: bool = True,
    ) -> Generator[Tuple[Optional[bytes], Optional[Tuple[int, np.ndarray]]], None, None]:
        """
        Generator: yields (mp3_bytes, full_audio) tuples.
        - mp3_bytes  : None (streaming not implemented — placeholder)
        - full_audio : (sample_rate, np.ndarray[float32]) on last chunk, else None
        """
        os.chdir(str(SEED_VC_DIR))
        try:
            if self._api == "inference_main":
                audio_np, sr = self._convert_via_inference_main(
                    source, target, diffusion_steps, length_adjust,
                    inference_cfg_rate, f0_condition, auto_f0_adjust,
                )
            else:
                audio_np, sr = self._convert_direct(
                    source, target, diffusion_steps, length_adjust,
                    inference_cfg_rate, f0_condition, auto_f0_adjust,
                )
        finally:
            os.chdir(self._orig_dir)

        yield (None, (sr, audio_np))

    # ── inference_main API ────────────────────────────────────────────────────
    def _convert_via_inference_main(
        self, source, target, diffusion_steps, length_adjust,
        inference_cfg_rate, f0_condition, auto_f0_adjust,
    ):
        """Use inference_main.vc_fn (newer Seed-VC API)."""
        import torchaudio

        src_audio, src_sr = torchaudio.load(source)
        tgt_audio, tgt_sr = torchaudio.load(target)

        # vc_fn signature may vary — try several
        for attempt in [
            lambda: self._vc_fn(
                src_audio, src_sr, tgt_audio, tgt_sr,
                diffusion_steps=diffusion_steps,
                length_adjust=length_adjust,
                inference_cfg_rate=inference_cfg_rate,
                f0_condition=f0_condition,
                auto_f0_adjust=auto_f0_adjust,
            ),
            lambda: self._vc_fn(
                source, target,
                diffusion_steps=diffusion_steps,
                length_adjust=length_adjust,
                inference_cfg_rate=inference_cfg_rate,
                f0_condition=f0_condition,
                auto_f0_adjust=auto_f0_adjust,
            ),
        ]:
            try:
                result = attempt()
                if isinstance(result, tuple):
                    sr, audio = result
                    return audio.astype(np.float32), int(sr)
                elif isinstance(result, np.ndarray):
                    return result.astype(np.float32), self._sr
                elif isinstance(result, torch.Tensor):
                    return result.cpu().numpy().astype(np.float32), self._sr
            except TypeError:
                continue

        raise RuntimeError("vc_fn signature not recognized")

    # ── direct API ────────────────────────────────────────────────────────────
    def _convert_direct(
        self, source, target, diffusion_steps, length_adjust,
        inference_cfg_rate, f0_condition, auto_f0_adjust,
    ):
        """Fallback: run inference via repo's CLI-style code."""
        import subprocess, tempfile

        out_wav = f"/tmp/seedvc_out_{torch.randint(0, 99999, (1,)).item()}.wav"

        # Try running the repo's inference CLI
        cmd = [
            sys.executable, str(SEED_VC_DIR / "inference.py"),
            "--source", source,
            "--target", target,
            "--output", out_wav,
            "--diffusion-steps", str(diffusion_steps),
            "--length-adjust", str(length_adjust),
            "--inference-cfg-rate", str(inference_cfg_rate),
            "--device", str(self.device),
        ]
        if f0_condition:
            cmd.append("--f0-condition")
        if auto_f0_adjust:
            cmd.append("--auto-f0-adjust")

        result = subprocess.run(cmd, capture_output=True, text=True, cwd=str(SEED_VC_DIR))
        if result.returncode != 0 or not Path(out_wav).exists():
            raise RuntimeError(
                f"Seed-VC inference.py failed:\n{result.stderr[-500:]}"
            )

        audio, sr = sf.read(out_wav)
        Path(out_wav).unlink(missing_ok=True)
        return audio.astype(np.float32), sr
