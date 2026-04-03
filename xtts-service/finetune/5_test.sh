#!/bin/bash
set -e

REFERENCE_WAV="${1:-/workspace/reference.wav}"
CHECKPOINT_DIR="/workspace/xtts-uz-finetuned/run/training/GPT_XTTS_FT-April-03-2026_05+59PM-b483a33"
OUTPUT="/workspace/test_output.wav"
TEST_TEXT="Siz meni aldadingiz! Men sizga ishongan edim, lekin siz buni qildingiz!"

echo "=== XTTS Uzbek Model Test ==="
echo "Reference : $REFERENCE_WAV"
echo "Checkpoint: $CHECKPOINT_DIR"
echo "Text      : $TEST_TEXT"
echo

export COQUI_TOS_AGREED=1

python3 - <<EOF
import os, torch
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

_orig = torch.load
def _p(*a, **kw): kw.setdefault("weights_only", False); return _orig(*a, **kw)
torch.load = _p

# Tokenizer monkey-patch
from TTS.tts.layers.xtts import tokenizer as _tok
_op = _tok.VoiceBpeTokenizer.preprocess_text
def _pp(self, txt, lang): return _op(self, txt, "tr" if lang == "uz" else lang)
_tok.VoiceBpeTokenizer.preprocess_text = _pp
_oe = _tok.VoiceBpeTokenizer.encode
def _pe(self, txt, lang): return _oe(self, txt, "tr" if lang == "uz" else lang)
_tok.VoiceBpeTokenizer.encode = _pe

import torchaudio
from TTS.tts.configs.xtts_config import XttsConfig
from TTS.tts.models.xtts import Xtts

device = "cuda" if torch.cuda.is_available() else "cpu"
reference_wav = "$REFERENCE_WAV"
ckpt_dir = Path("$CHECKPOINT_DIR")
text = "$TEST_TEXT"
output = "$OUTPUT"

# Find best checkpoint
checkpoints = sorted(ckpt_dir.glob("best_model*.pth"))
if not checkpoints:
    checkpoints = sorted(ckpt_dir.glob("*.pth"))
ckpt = checkpoints[0] if checkpoints else None
print(f"Checkpoint: {ckpt}")

# Load config from fine-tuned dir
config = XttsConfig()
config.load_json(str(ckpt_dir / "config.json"))

model = Xtts.init_from_config(config)
model.load_checkpoint(config, checkpoint_path=str(ckpt), eval=True, strict=False)
model = model.to(device).eval()
print(f"Model loaded on {device}")

# Get speaker embedding from reference
print("Computing speaker embedding...")
with torch.inference_mode():
    gpt_cond_latent, speaker_embedding = model.get_conditioning_latents(
        audio_path=[reference_wav],
        gpt_cond_len=30,
        gpt_cond_chunk_len=4,
        max_ref_length=30,
    )

# Synthesize
print(f"Synthesizing...")
with torch.inference_mode():
    out = model.inference(
        text=text,
        language="uz",
        gpt_cond_latent=gpt_cond_latent,
        speaker_embedding=speaker_embedding,
        temperature=0.7,
        repetition_penalty=2.0,
    )

wav = out["wav"]
if not isinstance(wav, torch.Tensor):
    import numpy as np
    wav = torch.from_numpy(wav)

torchaudio.save(output, wav.unsqueeze(0), 24000)
print(f"Saved: {output}")
print("Download it and listen!")
EOF
