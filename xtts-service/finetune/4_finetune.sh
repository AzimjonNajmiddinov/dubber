#!/bin/bash
set -e

DATASET_DIR="/workspace/uz_tts"
OUTPUT_DIR="/workspace/xtts-uz-finetuned"
EPOCHS=10
BATCH_SIZE=4

echo "=== XTTS v2 Uzbek Fine-tuning ==="
echo "Dataset: $DATASET_DIR"
echo "Output : $OUTPUT_DIR"
echo "Epochs : $EPOCHS"
echo "Batch  : $BATCH_SIZE"
echo

mkdir -p "$OUTPUT_DIR"
export COQUI_TOS_AGREED=1

python3 - <<EOF
import os, torch
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

_orig = torch.load
def _p(*a, **kw): kw.setdefault("weights_only", False); return _orig(*a, **kw)
torch.load = _p

dataset_dir = Path("$DATASET_DIR")
output_dir  = Path("$OUTPUT_DIR")
epochs      = int("$EPOCHS")
batch_size  = int("$BATCH_SIZE")

# metadata.csv: stem|text|text  →  train_gpt CSV: full_path|text|speaker
print("CSV tayyorlanmoqda...")
all_lines = []
with open(dataset_dir / "metadata.csv", encoding="utf-8") as f:
    for line in f:
        parts = line.strip().split("|")
        if len(parts) < 2:
            continue
        stem, text = parts[0], parts[1]
        wav = dataset_dir / "wavs" / f"{stem}.wav"
        if wav.exists():
            all_lines.append(f"{wav}|{text}|speaker")

# 85% train, 15% eval
split = int(len(all_lines) * 0.85)
train_lines = all_lines[:split]
eval_lines  = all_lines[split:]

train_csv = output_dir / "train.csv"
eval_csv  = output_dir / "eval.csv"

with open(train_csv, "w", encoding="utf-8") as f:
    f.write("\n".join(train_lines))
with open(eval_csv, "w", encoding="utf-8") as f:
    f.write("\n".join(eval_lines))

print(f"Train: {len(train_lines)}, Eval: {len(eval_lines)}")

device = "cuda" if torch.cuda.is_available() else "cpu"
print(f"Device: {device}")
if device == "cuda":
    print(f"GPU   : {torch.cuda.get_device_name(0)}")
    print(f"VRAM  : {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")

# Coqui XTTS fine-tuning utility
from TTS.demos.xtts_ft_demo.utils.gpt_train import train_gpt

print("\nFine-tuning boshlandi...")
train_gpt(
    language="uz",
    num_epochs=epochs,
    batch_size=batch_size,
    grad_acumm=1,
    train_csv=str(train_csv),
    eval_csv=str(eval_csv),
    output_path=str(output_dir),
    max_audio_length=255995,
)

print(f"\nTayor! Model: {output_dir}")
EOF

echo
echo "=== Fine-tuning tugadi ==="
echo "Run: bash 5_test.sh /path/to/reference.wav"
