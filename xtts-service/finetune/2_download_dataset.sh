#!/bin/bash
set -e

# Google FLEURS — Uzbek (uz_uz)
# HuggingFace da bepul, token shart emas
# ~10 soat Uzbek audio

OUTPUT_DIR="/workspace/uz_dataset_raw"
mkdir -p "$OUTPUT_DIR"

echo "=== Google FLEURS Uzbek dataset yuklash ==="
echo "Output: $OUTPUT_DIR"
echo

HF_DATASETS_TRUST_REMOTE_CODE=1 python3 - <<EOF
from datasets import load_dataset, concatenate_datasets
import soundfile as sf
import numpy as np
from pathlib import Path

output_dir = Path("$OUTPUT_DIR")
clips_dir = output_dir / "clips"
clips_dir.mkdir(parents=True, exist_ok=True)

print("FLEURS uz_uz yuklanmoqda (train + validation + test)...")

splits = []
for split in ["train", "validation", "test"]:
    try:
        ds = load_dataset("google/fleurs", "uz_uz", split=split, trust_remote_code=True)
        splits.append(ds)
        print(f"  {split}: {len(ds)} samples")
    except Exception as e:
        print(f"  {split}: skip ({e})")

ds = concatenate_datasets(splits)
print(f"Total: {len(ds)} samples")
print()

meta_lines = []
skipped = 0

for i, sample in enumerate(ds):
    try:
        audio = sample["audio"]
        arr = np.array(audio["array"], dtype=np.float32)
        sr = audio["sampling_rate"]
        text = sample["transcription"].strip()

        if not text:
            skipped += 1
            continue

        filename = f"fleurs_{i:05d}"
        wav_path = clips_dir / f"{filename}.wav"
        sf.write(str(wav_path), arr, sr)
        meta_lines.append(f"{filename}|{text}")

    except Exception as e:
        skipped += 1
        continue

    if (i + 1) % 200 == 0:
        print(f"  {i+1}/{len(ds)} saved...")

meta_path = output_dir / "metadata_raw.csv"
with open(meta_path, "w", encoding="utf-8") as f:
    f.write("\n".join(meta_lines))

print(f"\nSaqlandi : {len(meta_lines)} sample")
print(f"Skipped  : {skipped}")
print(f"Output   : {output_dir}")
EOF

echo
echo "Done. Run 3_prepare.sh next."
