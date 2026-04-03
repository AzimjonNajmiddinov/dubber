#!/bin/bash
set -e

VENV="/workspace/venv"
if [ -f "$VENV/bin/activate" ]; then
    source "$VENV/bin/activate"
fi

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
import os, json, torch
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

_orig = torch.load
def _p(*a, **kw): kw.setdefault("weights_only", False); return _orig(*a, **kw)
torch.load = _p

dataset_dir = Path("$DATASET_DIR")
output_dir  = Path("$OUTPUT_DIR")
epochs      = int("$EPOCHS")
batch_size  = int("$BATCH_SIZE")

# 0. Tokenizer ni runtime monkey-patch qilish ("uz" → "tr" kabi ishlaydi)
from TTS.tts.layers.xtts import tokenizer as _xtts_tok

_orig_preprocess = _xtts_tok.VoiceBpeTokenizer.preprocess_text
def _uz_preprocess(self, txt, lang):
    return _orig_preprocess(self, txt, "tr" if lang == "uz" else lang)
_xtts_tok.VoiceBpeTokenizer.preprocess_text = _uz_preprocess

_orig_encode = _xtts_tok.VoiceBpeTokenizer.encode
def _uz_encode(self, txt, lang):
    return _orig_encode(self, txt, "tr" if lang == "uz" else lang)
_xtts_tok.VoiceBpeTokenizer.encode = _uz_encode

print("Tokenizer monkey-patched: uz → tr")

# 1. Base model config ga "uz" qo'shish
model_dir = Path.home() / ".local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2"
if not model_dir.exists():
    model_dir = Path("/root/.local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2")

config_path = model_dir / "config.json"
with open(config_path, encoding="utf-8") as f:
    cfg = json.load(f)

if "uz" not in cfg.get("languages", []):
    cfg["languages"].append("uz")
    with open(config_path, "w", encoding="utf-8") as f:
        json.dump(cfg, f, indent=2, ensure_ascii=False)
    print("'uz' tili base model config ga qo'shildi")
else:
    print("'uz' allaqachon config da bor")

print(f"Supported languages: {cfg['languages']}")

# 2. CSV tayyorlash — language="uz"
print("\nCSV tayyorlanmoqda...")
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

split = int(len(all_lines) * 0.85)
train_lines = all_lines[:split]
eval_lines  = all_lines[split:]

train_csv = output_dir / "train.csv"
eval_csv  = output_dir / "eval.csv"

header = "audio_file|text|speaker_name|language"
with open(train_csv, "w", encoding="utf-8") as f:
    f.write(header + "\n")
    f.write("\n".join(f"{l}|uz" for l in train_lines))
with open(eval_csv, "w", encoding="utf-8") as f:
    f.write(header + "\n")
    f.write("\n".join(f"{l}|uz" for l in eval_lines))

print(f"Train: {len(train_lines)}, Eval: {len(eval_lines)}")

device = "cuda" if torch.cuda.is_available() else "cpu"
print(f"Device: {device}")
if device == "cuda":
    print(f"GPU   : {torch.cuda.get_device_name(0)}")
    print(f"VRAM  : {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")

# 3. Fine-tune
from TTS.demos.xtts_ft_demo.utils.gpt_train import train_gpt

print("\nFine-tuning boshlandi (language=uz)...")
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
