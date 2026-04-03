#!/usr/bin/env python3
"""
Uzbek TTS Dataset Preparation for XTTS v2 Fine-tuning

Supports:
  - Mozilla Common Voice (validated.tsv + clips/*.mp3)
  - Generic (wavs/*.wav + metadata.csv already in LJSpeech format)

Usage:
  # Common Voice:
  python prepare_dataset.py --source common_voice --input /data/cv-corpus-uz --output /data/uz_tts

  # Generic (already have wavs + metadata.csv):
  python prepare_dataset.py --source generic --input /data/my_dataset --output /data/uz_tts
"""

import argparse
import csv
import shutil
from pathlib import Path

import torchaudio
import torch

MIN_DURATION_SEC = 1.0
MAX_DURATION_SEC = 12.0
TARGET_SR = 22050


def resample_and_save(src_path: Path, dst_path: Path) -> float:
    """Resample audio to 22050Hz mono, return duration in seconds."""
    wav, sr = torchaudio.load(str(src_path))

    # Mono
    if wav.shape[0] > 1:
        wav = torch.mean(wav, dim=0, keepdim=True)

    # Resample
    if sr != TARGET_SR:
        resampler = torchaudio.transforms.Resample(sr, TARGET_SR)
        wav = resampler(wav)

    duration = wav.shape[1] / TARGET_SR
    torchaudio.save(str(dst_path), wav, TARGET_SR)
    return duration


def prepare_common_voice(input_dir: Path, output_dir: Path):
    """Process Mozilla Common Voice Uzbek dataset."""
    clips_dir = input_dir / "clips"
    tsv_file = input_dir / "validated.tsv"

    if not tsv_file.exists():
        raise FileNotFoundError(f"validated.tsv not found in {input_dir}")
    if not clips_dir.exists():
        raise FileNotFoundError(f"clips/ not found in {input_dir}")

    wavs_dir = output_dir / "wavs"
    wavs_dir.mkdir(parents=True, exist_ok=True)

    rows = []
    skipped = 0
    total = 0

    with open(tsv_file, encoding="utf-8") as f:
        reader = csv.DictReader(f, delimiter="\t")
        for row in reader:
            total += 1
            mp3_name = row["path"]
            text = row["sentence"].strip()
            src = clips_dir / mp3_name
            if not src.exists():
                skipped += 1
                continue

            stem = src.stem
            dst = wavs_dir / f"{stem}.wav"

            try:
                duration = resample_and_save(src, dst)
            except Exception as e:
                print(f"  Skip {mp3_name}: {e}")
                skipped += 1
                continue

            if not (MIN_DURATION_SEC <= duration <= MAX_DURATION_SEC):
                dst.unlink(missing_ok=True)
                skipped += 1
                continue

            rows.append((stem, text))

            if len(rows) % 500 == 0:
                print(f"  Processed {len(rows)}/{total} ...")

    _write_metadata(output_dir, rows)
    print(f"\nDone: {len(rows)} samples, {skipped} skipped")
    _print_stats(rows, wavs_dir)


def prepare_generic(input_dir: Path, output_dir: Path):
    """
    Generic format: input_dir/wavs/*.wav + input_dir/metadata.csv
    metadata.csv format: filename|text  (no header, pipe-separated)
    """
    src_wavs = input_dir / "wavs"
    src_meta = input_dir / "metadata.csv"

    if not src_meta.exists():
        raise FileNotFoundError(f"metadata.csv not found in {input_dir}")

    wavs_dir = output_dir / "wavs"
    wavs_dir.mkdir(parents=True, exist_ok=True)

    rows = []
    skipped = 0

    with open(src_meta, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            parts = line.split("|")
            if len(parts) < 2:
                continue
            stem, text = parts[0].strip(), parts[1].strip()
            src = src_wavs / f"{stem}.wav"
            if not src.exists():
                skipped += 1
                continue

            dst = wavs_dir / f"{stem}.wav"
            try:
                duration = resample_and_save(src, dst)
            except Exception as e:
                print(f"  Skip {stem}: {e}")
                skipped += 1
                continue

            if not (MIN_DURATION_SEC <= duration <= MAX_DURATION_SEC):
                dst.unlink(missing_ok=True)
                skipped += 1
                continue

            rows.append((stem, text))

    _write_metadata(output_dir, rows)
    print(f"\nDone: {len(rows)} samples, {skipped} skipped")
    _print_stats(rows, wavs_dir)


def _write_metadata(output_dir: Path, rows: list):
    """Write LJSpeech-format metadata.csv: filename|text|text"""
    meta_path = output_dir / "metadata.csv"
    with open(meta_path, "w", encoding="utf-8") as f:
        for stem, text in rows:
            f.write(f"{stem}|{text}|{text}\n")
    print(f"Metadata written: {meta_path}")


def _print_stats(rows: list, wavs_dir: Path):
    total_files = len(list(wavs_dir.glob("*.wav")))
    total_size_mb = sum(f.stat().st_size for f in wavs_dir.glob("*.wav")) / 1024 / 1024
    print(f"  Samples   : {len(rows)}")
    print(f"  WAV files : {total_files}")
    print(f"  Total size: {total_size_mb:.1f} MB")

    # Estimate duration
    sample_wav = next(wavs_dir.glob("*.wav"), None)
    if sample_wav:
        try:
            info = torchaudio.info(str(sample_wav))
            avg_dur = info.num_frames / info.sample_rate
            total_min = len(rows) * avg_dur / 60
            print(f"  Est. total duration: ~{total_min:.0f} min")
        except Exception:
            pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", choices=["common_voice", "generic"], required=True)
    parser.add_argument("--input", required=True, help="Input dataset directory")
    parser.add_argument("--output", required=True, help="Output directory for prepared dataset")
    args = parser.parse_args()

    input_dir = Path(args.input)
    output_dir = Path(args.output)
    output_dir.mkdir(parents=True, exist_ok=True)

    print(f"Source : {args.source}")
    print(f"Input  : {input_dir}")
    print(f"Output : {output_dir}")
    print()

    if args.source == "common_voice":
        prepare_common_voice(input_dir, output_dir)
    elif args.source == "generic":
        prepare_generic(input_dir, output_dir)


if __name__ == "__main__":
    main()
