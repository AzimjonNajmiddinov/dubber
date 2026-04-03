#!/usr/bin/env python3
"""
Fine-tuned XTTS model test qilish.

Usage:
  # Base model bilan test (before fine-tuning):
  python test_model.py --reference /path/to/speaker.wav --text "Salom, bu test gap." --lang tr

  # Fine-tuned model bilan test (after fine-tuning):
  python test_model.py --checkpoint /data/xtts-uz-finetuned --reference /path/to/speaker.wav --text "Salom, bu test gap." --lang uz
"""

import argparse
import os
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

import torch

_orig = torch.load
def _patched(*a, **kw):
    kw.setdefault("weights_only", False)
    return _orig(*a, **kw)
torch.load = _patched

import torchaudio


def test(reference_wav: str, text: str, language: str, checkpoint_dir: str | None, output: str):
    from TTS.tts.configs.xtts_config import XttsConfig
    from TTS.tts.models.xtts import Xtts
    from TTS.utils.manage import ModelManager

    device = "cuda" if torch.cuda.is_available() else "cpu"
    print(f"Device: {device}")

    if checkpoint_dir:
        # Fine-tuned model
        model_dir = Path(checkpoint_dir)
        # Find latest checkpoint
        checkpoints = sorted(model_dir.rglob("best_model*.pth"))
        if not checkpoints:
            checkpoints = sorted(model_dir.rglob("*.pth"))
        if not checkpoints:
            raise FileNotFoundError(f"No checkpoint found in {checkpoint_dir}")
        ckpt = checkpoints[-1]
        config_path = model_dir / "config.json"
        print(f"Loading fine-tuned model: {ckpt}")
    else:
        # Base XTTS v2
        manager = ModelManager()
        manager.download_model("tts_models/multilingual/multi-dataset/xtts_v2")
        model_dir = Path.home() / ".local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2"
        if not model_dir.exists():
            model_dir = Path("/root/.local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2")
        config_path = model_dir / "config.json"
        ckpt = None
        print(f"Loading base XTTS v2 from: {model_dir}")

    config = XttsConfig()
    config.load_json(str(config_path))

    model = Xtts.init_from_config(config)
    if ckpt:
        model.load_checkpoint(config, checkpoint_path=str(ckpt), eval=True)
    else:
        model.load_checkpoint(config, checkpoint_dir=str(model_dir), eval=True)

    model = model.to(device)
    model.eval()

    print(f"Computing speaker embedding from: {reference_wav}")
    with torch.inference_mode():
        gpt_cond_latent, speaker_embedding = model.get_conditioning_latents(
            audio_path=[reference_wav],
            gpt_cond_len=30,
            gpt_cond_chunk_len=4,
            max_ref_length=30,
        )

    print(f"Synthesizing: '{text}' [{language}]")
    with torch.inference_mode():
        out = model.inference(
            text=text,
            language=language,
            gpt_cond_latent=gpt_cond_latent,
            speaker_embedding=speaker_embedding,
            temperature=0.7,
            repetition_penalty=2.0,
        )

    wav = out["wav"]
    if not isinstance(wav, torch.Tensor):
        wav = torch.from_numpy(wav)

    torchaudio.save(output, wav.unsqueeze(0), 24000)
    print(f"Saved: {output}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--reference", required=True, help="Reference speaker WAV (3-15 sec)")
    parser.add_argument("--text", required=True, help="Uzbek text to synthesize")
    parser.add_argument("--lang", default="uz", help="Language code: 'tr' for base model, 'uz' for fine-tuned")
    parser.add_argument("--checkpoint", default=None, help="Fine-tuned model dir (omit for base XTTS v2)")
    parser.add_argument("--output", default="output.wav", help="Output WAV file")
    args = parser.parse_args()

    test(args.reference, args.text, args.lang, args.checkpoint, args.output)


if __name__ == "__main__":
    main()
