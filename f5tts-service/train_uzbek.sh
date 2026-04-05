#!/bin/bash
# Fine-tune F5-TTS on Uzbek (Google FLEURS uz_uz dataset)
# Run from: /workspace/dubber/f5tts-service
#
# Usage:
#   ./train_uzbek.sh              # Download data + prepare + launch training UI
#   ./train_uzbek.sh --skip-download  # Skip download (wavs already exist)
#   ./train_uzbek.sh --train-only     # Skip data prep, go straight to training UI

set -o pipefail

VENV=/workspace/tts-venv
WAVS_DIR=/workspace/uz_tts/wavs
TRAIN_CSV=/workspace/xtts-uz-finetuned/train.csv
EVAL_CSV=/workspace/xtts-uz-finetuned/eval.csv
F5_DATA=/workspace/f5tts-uz-data

SKIP_DOWNLOAD=false
TRAIN_ONLY=false
for arg in "$@"; do
    case $arg in
        --skip-download) SKIP_DOWNLOAD=true ;;
        --train-only) SKIP_DOWNLOAD=true; TRAIN_ONLY=true ;;
    esac
done

echo "=== F5-TTS Uzbek Fine-tuning ==="

# ─── Step 1: Download FLEURS uz_uz ───────────────────────────────────────────
if [ "$SKIP_DOWNLOAD" = false ]; then
    echo ""
    echo "[1/3] Downloading Google FLEURS uz_uz dataset..."
    mkdir -p "$WAVS_DIR"

    $VENV/bin/python <<'PYEOF'
from pathlib import Path
import soundfile as sf
import numpy as np

wavs_dir = Path("/workspace/uz_tts/wavs")
wavs_dir.mkdir(parents=True, exist_ok=True)

print("  Loading FLEURS uz_uz from HuggingFace (all splits)...")
from datasets import load_dataset

ds = load_dataset("google/fleurs", "uz_uz",
                  split="train+validation+test",
                  trust_remote_code=True)

print(f"  Total samples: {len(ds)}")
saved, skipped = 0, 0
for item in ds:
    audio    = item["audio"]
    text     = item.get("transcription") or item.get("raw_transcription", "")
    if not text.strip():
        skipped += 1
        continue
    stem     = Path(audio.get("path", f"fleurs_{saved:05d}")).stem
    out_path = wavs_dir / f"{stem}.wav"
    if not out_path.exists():
        arr = np.array(audio["array"], dtype=np.float32)
        sf.write(str(out_path), arr, audio["sampling_rate"])
    saved += 1
    if saved % 200 == 0:
        print(f"  Saved {saved}/{len(ds) - skipped}")

print(f"  Done — {saved} WAV files saved, {skipped} skipped (no text)")
PYEOF

else
    echo "[1/3] Skipping download"
fi

# ─── Step 2: Prepare F5-TTS dataset ──────────────────────────────────────────
if [ "$TRAIN_ONLY" = false ]; then
    echo ""
    echo "[2/3] Preparing F5-TTS dataset..."

    $VENV/bin/python <<PYEOF
import csv, shutil
from pathlib import Path

train_csv = Path("$TRAIN_CSV")
eval_csv  = Path("$EVAL_CSV")
f5_data   = Path("$F5_DATA")

# F5-TTS expects: dataset_dir/audio/*.wav + dataset_dir/metadata.csv
# metadata.csv format: audio_file|text  (filename only, not full path)
audio_out = f5_data / "audio"
audio_out.mkdir(parents=True, exist_ok=True)

def process(csv_path, split):
    rows, missing = [], 0
    with open(csv_path) as f:
        reader = csv.DictReader(f, delimiter="|")
        for row in reader:
            src = Path(row["audio_file"])
            if not src.exists():
                missing += 1
                continue
            dst = audio_out / src.name
            if not dst.exists():
                shutil.copy2(src, dst)
            rows.append((src.name, row["text"].strip()))
    print(f"  {split}: {len(rows)} samples ({missing} missing wavs skipped)")
    return rows

train_rows = process(train_csv, "train")
eval_rows  = process(eval_csv,  "eval")

# Write combined metadata.csv (F5-TTS uses one file, splits by ratio internally)
with open(f5_data / "metadata.csv", "w") as f:
    f.write("audio_file|text\n")
    for name, text in train_rows + eval_rows:
        f.write(f"{name}|{text}\n")

# Also write separate files for reference
with open(f5_data / "metadata_train.csv", "w") as f:
    f.write("audio_file|text\n")
    for name, text in train_rows:
        f.write(f"{name}|{text}\n")

with open(f5_data / "metadata_eval.csv", "w") as f:
    f.write("audio_file|text\n")
    for name, text in eval_rows:
        f.write(f"{name}|{text}\n")

total = len(train_rows) + len(eval_rows)
print(f"  Total: {total} samples → {f5_data}")
PYEOF

else
    echo "[2/3] Skipping data prep (--train-only)"
fi

# ─── Step 3: Launch Gradio fine-tuning UI ────────────────────────────────────
echo ""
echo "[3/3] Launching F5-TTS fine-tuning UI on port 7860..."
echo ""
echo "  Access at: https://gf7d4njyfe95a4-7860.proxy.runpod.net"
echo ""
echo "  In the UI:"
echo "    1. Dataset dir: $F5_DATA"
echo "    2. Epochs: 15"
echo "    3. Batch size: 4"
echo "    4. Learning rate: 1e-5"
echo "    5. Click 'Start Training'"
echo ""
echo "  Checkpoint will be saved to: /root/.cache/... (shown in UI)"
echo "  Training log: tail -f /tmp/f5tts_train.log"
echo ""

nohup $VENV/bin/python -m f5_tts.train.finetune_gradio \
    --port 7860 \
    --share false \
    > /tmp/f5tts_train.log 2>&1 &

echo "  Gradio UI PID: $!"
echo "  Starting up... wait 10s then open the URL above."
