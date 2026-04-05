#!/bin/bash
# Fine-tune F5-TTS on Uzbek (Google FLEURS uz_uz)
#
# Usage:
#   ./train_uzbek.sh                  # Full pipeline: download → prepare → train
#   ./train_uzbek.sh --skip-download  # Skip FLEURS download (wavs already exist)
#   ./train_uzbek.sh --skip-prepare   # Skip data prep (already prepared)
#
# Output:
#   Checkpoints → /workspace/f5tts-uz-finetuned/
#   Logs        → /tmp/f5tts_train.log

set -o pipefail

VENV=/workspace/tts-venv
WAVS_DIR=/workspace/uz_tts/wavs
TRAIN_CSV=/workspace/xtts-uz-finetuned/train.csv
EVAL_CSV=/workspace/xtts-uz-finetuned/eval.csv
DATASET_DIR=/workspace/f5tts-uz-data
CKPT_DIR=/workspace/f5tts-uz-finetuned
PREPARE_SCRIPT=$VENV/lib/python3.10/site-packages/f5_tts/train/datasets/prepare_csv_wavs.py
FINETUNE_SCRIPT=$VENV/lib/python3.10/site-packages/f5_tts/train/finetune_cli.py

SKIP_DOWNLOAD=false
SKIP_PREPARE=false
for arg in "$@"; do
    case $arg in
        --skip-download) SKIP_DOWNLOAD=true ;;
        --skip-prepare)  SKIP_DOWNLOAD=true; SKIP_PREPARE=true ;;
    esac
done

echo "=== F5-TTS Uzbek Fine-tuning ==="
echo ""

# ─── Step 1: Download FLEURS uz_uz ───────────────────────────────────────────
if [ "$SKIP_DOWNLOAD" = false ]; then
    echo "[1/3] Downloading FLEURS uz_uz..."
    mkdir -p "$WAVS_DIR"

    # Downgrade datasets temporarily — newer versions dropped script support
    echo "  Pinning datasets==2.14.7 for FLEURS download..."
    $VENV/bin/pip install -q "datasets==2.14.7"

    $VENV/bin/python <<'PYEOF'
from pathlib import Path
import soundfile as sf
import numpy as np

wavs_dir = Path("/workspace/uz_tts/wavs")
wavs_dir.mkdir(parents=True, exist_ok=True)

from datasets import load_dataset

print("  Downloading train+validation+test splits...")
ds = load_dataset("google/fleurs", "uz_uz",
                  split="train+validation+test",
                  trust_remote_code=True)

print(f"  Total: {len(ds)} samples")
saved = skipped = 0
for item in ds:
    audio = item["audio"]
    text  = (item.get("transcription") or item.get("raw_transcription", "")).strip()
    if not text:
        skipped += 1
        continue
    stem     = Path(audio.get("path", f"fleurs_{saved:05d}")).stem
    out_path = wavs_dir / f"{stem}.wav"
    if not out_path.exists():
        arr = np.array(audio["array"], dtype=np.float32)
        sf.write(str(out_path), arr, audio["sampling_rate"])
    saved += 1
    if saved % 200 == 0:
        print(f"  {saved}/{len(ds) - skipped} saved...")

print(f"  Done — {saved} WAVs, {skipped} skipped")
PYEOF

    echo "  Restoring latest datasets..."
    $VENV/bin/pip install -q "datasets>=2.20"
else
    echo "[1/3] Skipping download"
fi

WAV_COUNT=$(ls "$WAVS_DIR"/*.wav 2>/dev/null | wc -l)
echo "  WAVs available: $WAV_COUNT"
if [ "$WAV_COUNT" -lt 100 ]; then
    echo "ERROR: Too few WAV files in $WAVS_DIR. Check download."
    exit 1
fi

# ─── Step 2: Prepare dataset ──────────────────────────────────────────────────
if [ "$SKIP_PREPARE" = false ]; then
    echo ""
    echo "[2/3] Preparing F5-TTS dataset..."

    # Build metadata CSV with absolute paths from train.csv
    META_CSV=/tmp/uzbek_f5tts_meta.csv
    $VENV/bin/python <<PYEOF
import csv
from pathlib import Path

train_csv = Path("$TRAIN_CSV")
eval_csv  = Path("$EVAL_CSV")
wavs_dir  = Path("$WAVS_DIR")
out_csv   = Path("$META_CSV")

rows, missing = [], 0
for csv_path in [train_csv, eval_csv]:
    with open(csv_path) as f:
        reader = csv.DictReader(f, delimiter="|")
        for row in reader:
            src  = Path(row["audio_file"])
            name = src.name
            wav  = wavs_dir / name
            if not wav.exists():
                missing += 1
                continue
            rows.append((str(wav.absolute()), row["text"].strip()))

with open(out_csv, "w", encoding="utf-8") as f:
    f.write("audio_file|text\n")
    for path, text in rows:
        f.write(f"{path}|{text}\n")

print(f"  Metadata: {len(rows)} samples, {missing} skipped (missing wav)")
PYEOF

    SAMPLE_COUNT=$(wc -l < "$META_CSV")
    echo "  Samples in metadata: $((SAMPLE_COUNT - 1))"

    mkdir -p "$DATASET_DIR"
    echo "  Running prepare_csv_wavs.py..."
    $VENV/bin/python "$PREPARE_SCRIPT" "$META_CSV" "$DATASET_DIR"

    echo "  Dataset prepared at: $DATASET_DIR"
    ls -lh "$DATASET_DIR"
else
    echo "[2/3] Skipping data preparation"
fi

if [ ! -f "$DATASET_DIR/raw.arrow" ]; then
    echo "ERROR: Dataset not prepared. Missing $DATASET_DIR/raw.arrow"
    exit 1
fi

# ─── Step 3: Fine-tune ───────────────────────────────────────────────────────
echo ""
echo "[3/3] Starting F5-TTS fine-tuning..."
echo "  Dataset:      $DATASET_DIR"
echo "  Checkpoints:  $CKPT_DIR"
echo "  Log:          /tmp/f5tts_train.log"
echo ""

mkdir -p "$CKPT_DIR"
cd "$CKPT_DIR"

# accelerate config — single GPU, no distributed training
$VENV/bin/accelerate config default --config-file /tmp/accelerate_default.yaml 2>/dev/null || true

$VENV/bin/accelerate launch \
    --config_file /tmp/accelerate_default.yaml \
    "$FINETUNE_SCRIPT" \
    --exp_name F5TTS_v1_Base \
    --dataset_name "$DATASET_DIR" \
    --tokenizer char \
    --finetune \
    --epochs 15 \
    --learning_rate 1e-5 \
    --batch_size_per_gpu 1600 \
    --batch_size_type frame \
    --max_samples 32 \
    --grad_accumulation_steps 4 \
    --num_warmup_updates 50 \
    --save_per_updates 500 \
    --keep_last_n_checkpoints 3 \
    --last_per_updates 100 \
    --logger None \
    2>&1 | tee /tmp/f5tts_train.log

echo ""
echo "=== Training complete ==="
echo "Checkpoints: $CKPT_DIR/ckpts/F5TTS_v1_Base/"
