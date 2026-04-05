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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VENV=/workspace/tts-venv
WAVS_DIR=/workspace/uz_tts/wavs
DATASET_NAME=f5tts-uz-data          # short name passed to --dataset_name
DATASET_DIR=/workspace/f5tts-uz-data_char  # actual files on disk (prepare outputs here)
# finetune_cli constructs path as {site-packages}/f5_tts/../../data/{DATASET_NAME}_char
# f5_tts/../../ = python3.10/ so data dir is {venv}/lib/python3.10/data/
# symlink: {venv}/lib/python3.10/data/f5tts-uz-data_char → $DATASET_DIR
F5_DATA_DIR=$VENV/lib/python3.10/data
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
    echo "[1/3] Downloading FLEURS uz_uz via HuggingFace Hub..."
    mkdir -p "$WAVS_DIR"

    $VENV/bin/python <<'PYEOF'
import os, io, tarfile, subprocess
from pathlib import Path
import soundfile as sf
import numpy as np
from huggingface_hub import HfApi, hf_hub_download

wavs_dir = Path("/workspace/uz_tts/wavs")
wavs_dir.mkdir(parents=True, exist_ok=True)
hf_token = os.environ.get("HF_TOKEN")

api = HfApi()
print("  Listing FLEURS uz_uz files on HuggingFace Hub...")
all_files = list(api.list_repo_files("google/fleurs", repo_type="dataset", token=hf_token))
uz_files  = [f for f in all_files if "uz_uz" in f]
print(f"  Found {len(uz_files)} uz_uz files")

def convert_opus_to_wav(opus_path: Path, wav_path: Path):
    """Convert opus file to 24kHz mono WAV using soundfile+resampy or ffmpeg."""
    try:
        data, sr = sf.read(str(opus_path))
        if data.ndim > 1:
            data = data.mean(axis=1)
        data = data.astype(np.float32)
        if sr != 24000:
            from scipy.signal import resample_poly
            import math
            gcd = math.gcd(sr, 24000)
            data = resample_poly(data, 24000 // gcd, sr // gcd).astype(np.float32)
        sf.write(str(wav_path), data, 24000)
        return True
    except Exception as e:
        print(f"    soundfile failed for {opus_path.name}: {e}")
        return False

saved = 0
for fpath in uz_files:
    if not (fpath.endswith(".opus") or fpath.endswith(".wav") or fpath.endswith(".tar.gz")):
        continue
    print(f"  Downloading {fpath}...")
    local = hf_hub_download("google/fleurs", fpath, repo_type="dataset", token=hf_token)

    if fpath.endswith(".tar.gz"):
        with tarfile.open(local) as tar:
            members = [m for m in tar.getmembers() if m.name.endswith((".opus", ".wav"))]
            print(f"    Extracting {len(members)} audio files...")
            for member in members:
                stem = Path(member.name).stem
                wav_path = wavs_dir / f"{stem}.wav"
                if wav_path.exists():
                    saved += 1
                    continue
                f = tar.extractfile(member)
                if f is None:
                    continue
                tmp = Path(f"/tmp/{stem}.opus")
                tmp.write_bytes(f.read())
                if convert_opus_to_wav(tmp, wav_path):
                    saved += 1
                tmp.unlink(missing_ok=True)
    elif fpath.endswith(".opus"):
        stem = Path(fpath).stem
        wav_path = wavs_dir / f"{stem}.wav"
        if not wav_path.exists():
            if convert_opus_to_wav(Path(local), wav_path):
                saved += 1
        else:
            saved += 1
    elif fpath.endswith(".wav"):
        stem = Path(fpath).stem
        wav_path = wavs_dir / f"{stem}.wav"
        if not wav_path.exists():
            import shutil
            shutil.copy2(local, wav_path)
        saved += 1

print(f"  Done — {saved} WAVs ready at {wavs_dir}")
PYEOF

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

    # Build metadata CSV from FLEURS TSV files (downloaded alongside audio)
    META_CSV=/tmp/uzbek_f5tts_meta.csv
    $VENV/bin/python <<'PYEOF'
import os
from pathlib import Path
from huggingface_hub import hf_hub_download

wavs_dir = Path("/workspace/uz_tts/wavs")
out_csv  = Path("/tmp/uzbek_f5tts_meta.csv")
hf_token = os.environ.get("HF_TOKEN")

rows, missing = [], 0
for split in ["train", "dev", "test"]:
    # Download the TSV with transcriptions
    try:
        tsv_path = hf_hub_download(
            "google/fleurs", f"data/uz_uz/{split}.tsv",
            repo_type="dataset", token=hf_token
        )
    except Exception as e:
        print(f"  Warning: could not download {split}.tsv: {e}")
        continue

    with open(tsv_path, encoding="utf-8") as f:
        header = f.readline().strip().split("\t")
        # FLEURS TSV columns: id, file_name, raw_transcription, transcription, ...
        try:
            fname_idx = header.index("file_name")
            text_idx  = header.index("transcription")
        except ValueError:
            fname_idx, text_idx = 1, 3  # fallback positions

        for line in f:
            parts = line.strip().split("\t")
            if len(parts) <= max(fname_idx, text_idx):
                continue
            fname = Path(parts[fname_idx]).stem  # e.g. "1234" from "1234.opus"
            text  = parts[text_idx].strip()
            if not text:
                continue
            wav = wavs_dir / f"{fname}.wav"
            if not wav.exists():
                missing += 1
                continue
            rows.append((str(wav.absolute()), text))

with open(out_csv, "w", encoding="utf-8") as f:
    f.write("audio_file|text\n")
    for path, text in rows:
        f.write(f"{path}|{text}\n")

print(f"  Metadata: {len(rows)} samples, {missing} skipped (missing wav)")
PYEOF

    SAMPLE_COUNT=$(wc -l < "$META_CSV")
    echo "  Samples in metadata: $((SAMPLE_COUNT - 1))"

    mkdir -p "$DATASET_DIR"
    echo "  Running prepare_csv_wavs.py (--pretrain builds Uzbek char vocab from scratch)..."
    $VENV/bin/python "$PREPARE_SCRIPT" "$META_CSV" "$DATASET_DIR" --pretrain

    echo "  Dataset prepared at: $DATASET_DIR"
    ls -lh "$DATASET_DIR"
else
    echo "[2/3] Skipping data preparation"
fi

if [ ! -f "$DATASET_DIR/raw.arrow" ]; then
    echo "ERROR: Dataset not prepared. Missing $DATASET_DIR/raw.arrow"
    exit 1
fi

# Create symlink so finetune_cli can find data via its relative path resolution:
#   {venv}/site-packages/data/{DATASET_NAME}_char → $DATASET_DIR
mkdir -p "$F5_DATA_DIR"
ln -sfn "$DATASET_DIR" "$F5_DATA_DIR/${DATASET_NAME}_char"
echo "  Symlink: $F5_DATA_DIR/${DATASET_NAME}_char → $DATASET_DIR"

# ─── Step 3: Fine-tune ───────────────────────────────────────────────────────
echo ""
echo "[3/3] Starting F5-TTS fine-tuning..."
echo "  Dataset:      $DATASET_NAME (files at $DATASET_DIR)"
echo "  Checkpoints:  $CKPT_DIR"
echo "  Log:          /tmp/f5tts_train.log"
echo ""

mkdir -p "$CKPT_DIR"
cd "$CKPT_DIR"

# Patch trainer to use strict=False so pretrained acoustic weights load
# even when vocab size differs (Uzbek 67 chars vs pretrained 2546 chars)
$VENV/bin/python "$SCRIPT_DIR/patch_trainer.py"

# accelerate config — single GPU, no distributed training
$VENV/bin/accelerate config default --config-file /tmp/accelerate_default.yaml 2>/dev/null || true

$VENV/bin/accelerate launch \
    --config_file /tmp/accelerate_default.yaml \
    "$FINETUNE_SCRIPT" \
    --exp_name F5TTS_v1_Base \
    --dataset_name "$DATASET_NAME" \
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
    2>&1 | tee /tmp/f5tts_train.log

echo ""
echo "=== Training complete ==="
echo "Checkpoints: $CKPT_DIR/ckpts/F5TTS_v1_Base/"
