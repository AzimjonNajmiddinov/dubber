#!/bin/bash
set -e

# Reference audio — kinodagi original speaker ovozi (3-15 soniya, toza)
REFERENCE_WAV="${1:-/workspace/reference.wav}"
CHECKPOINT_DIR="/workspace/xtts-uz-finetuned"
OUTPUT_BASE="/workspace/test_base.wav"
OUTPUT_FINETUNED="/workspace/test_finetuned.wav"

TEST_TEXT="Siz meni aldadingiz! Men sizga ishongan edim, lekin siz buni qildingiz!"

echo "=== XTTS Model Test ==="
echo "Reference: $REFERENCE_WAV"
echo "Text: $TEST_TEXT"
echo

export COQUI_TOS_AGREED=1

python3 - <<EOF
import os, torch
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

_orig = torch.load
def _p(*a, **kw): kw.setdefault("weights_only", False); return _orig(*a, **kw)
torch.load = _p

import torchaudio
from TTS.tts.configs.xtts_config import XttsConfig
from TTS.tts.models.xtts import Xtts
from TTS.utils.manage import ModelManager

device = "cuda" if torch.cuda.is_available() else "cpu"
reference_wav = "$REFERENCE_WAV"
checkpoint_dir = Path("$CHECKPOINT_DIR")
text = "$TEST_TEXT"

def load_model(model_dir, checkpoint_path=None):
    config = XttsConfig()
    config.load_json(str(model_dir / "config.json"))
    model = Xtts.init_from_config(config)
    if checkpoint_path:
        model.load_checkpoint(config, checkpoint_path=str(checkpoint_path), eval=True)
    else:
        model.load_checkpoint(config, checkpoint_dir=str(model_dir), eval=True)
    return model.to(device).eval(), config

def synthesize(model, reference_wav, text, language, output_path):
    with torch.inference_mode():
        gpt_cond_latent, speaker_embedding = model.get_conditioning_latents(
            audio_path=[reference_wav],
            gpt_cond_len=30,
            gpt_cond_chunk_len=4,
            max_ref_length=30,
        )
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
        import numpy as np
        wav = torch.from_numpy(wav)
    torchaudio.save(output_path, wav.unsqueeze(0), 24000)
    print(f"  Saved: {output_path}")

# 1. Base model (Turkish lang, before fine-tuning)
print("1) Base XTTS v2 (Turkish lang)...")
manager = ModelManager()
manager.download_model("tts_models/multilingual/multi-dataset/xtts_v2")
base_dir = Path.home() / ".local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2"
if not base_dir.exists():
    base_dir = Path("/root/.local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2")

model_base, _ = load_model(base_dir)
synthesize(model_base, reference_wav, text, "tr", "$OUTPUT_BASE")
del model_base
torch.cuda.empty_cache() if torch.cuda.is_available() else None

# 2. Fine-tuned model (Uzbek lang)
if checkpoint_dir.exists():
    print("2) Fine-tuned XTTS (Uzbek lang)...")
    checkpoints = sorted(checkpoint_dir.rglob("best_model*.pth"))
    if not checkpoints:
        checkpoints = sorted(checkpoint_dir.rglob("*.pth"))
    ckpt = checkpoints[-1] if checkpoints else None

    model_ft, _ = load_model(checkpoint_dir, ckpt)
    synthesize(model_ft, reference_wav, text, "uz", "$OUTPUT_FINETUNED")
else:
    print("2) Fine-tuned model topilmadi, skip.")

print("\nTaqqoslash:")
print(f"  Base (Turkish): $OUTPUT_BASE")
print(f"  Fine-tuned (Uzbek): $OUTPUT_FINETUNED")
print("Ikkala faylni eshiting va taqqoslang.")
EOF
