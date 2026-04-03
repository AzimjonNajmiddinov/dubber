#!/bin/bash
set -e

RAW_DIR="/workspace/uz_dataset_raw"
OUTPUT_DIR="/workspace/uz_tts"
MIN_DUR=1.0
MAX_DUR=12.0
TARGET_SR=22050

echo "=== Dataset tayyorlash ==="
echo "Input : $RAW_DIR"
echo "Output: $OUTPUT_DIR"
echo

mkdir -p "$OUTPUT_DIR/wavs"

python3 - <<EOF
import csv
from pathlib import Path
import torch
import torchaudio

raw_dir = Path("$RAW_DIR")
output_dir = Path("$OUTPUT_DIR")
wavs_dir = output_dir / "wavs"
wavs_dir.mkdir(parents=True, exist_ok=True)

min_dur = float("$MIN_DUR")
max_dur = float("$MAX_DUR")
target_sr = int("$TARGET_SR")

meta_raw = raw_dir / "metadata_raw.csv"
clips_dir = raw_dir / "clips"

rows = []
skipped = 0

with open(meta_raw, encoding="utf-8") as f:
    for line in f:
        line = line.strip()
        if not line or "|" not in line:
            continue
        stem, text = line.split("|", 1)
        src = clips_dir / f"{stem}.wav"
        if not src.exists():
            skipped += 1
            continue

        try:
            wav, sr = torchaudio.load(str(src))

            # Mono
            if wav.shape[0] > 1:
                wav = torch.mean(wav, dim=0, keepdim=True)

            # Resample
            if sr != target_sr:
                wav = torchaudio.transforms.Resample(sr, target_sr)(wav)

            duration = wav.shape[1] / target_sr
            if not (min_dur <= duration <= max_dur):
                skipped += 1
                continue

            dst = wavs_dir / f"{stem}.wav"
            torchaudio.save(str(dst), wav, target_sr)
            rows.append((stem, text))

        except Exception as e:
            skipped += 1
            continue

        if len(rows) % 1000 == 0 and len(rows) > 0:
            print(f"  {len(rows)} processed...")

# LJSpeech format: filename|text|text
meta_out = output_dir / "metadata.csv"
with open(meta_out, "w", encoding="utf-8") as f:
    for stem, text in rows:
        f.write(f"{stem}|{text}|{text}\n")

total_dur_min = len(rows) * 4.0 / 60  # rough estimate ~4s avg
print(f"\nReady   : {len(rows)} samples")
print(f"Skipped : {skipped}")
print(f"Est. dur: ~{total_dur_min:.0f} min")
print(f"Metadata: {meta_out}")
EOF

echo
echo "Done. Run 4_finetune.sh next."
