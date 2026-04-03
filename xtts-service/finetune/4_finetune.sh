#!/bin/bash
set -e

DATASET_DIR="/workspace/uz_tts"
OUTPUT_DIR="/workspace/xtts-uz-finetuned"
EPOCHS=10
BATCH_SIZE=4   # A100/H100 uchun 8 ga ko'taring, 16GB GPU uchun 4

echo "=== XTTS v2 Uzbek Fine-tuning ==="
echo "Dataset: $DATASET_DIR"
echo "Output : $OUTPUT_DIR"
echo "Epochs : $EPOCHS"
echo "Batch  : $BATCH_SIZE"
echo

mkdir -p "$OUTPUT_DIR"

# Accept Coqui TOS
export COQUI_TOS_AGREED=1

python3 - <<EOF
import os, torch
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"

# Patch torch.load for TTS compatibility
_orig = torch.load
def _p(*a, **kw): kw.setdefault("weights_only", False); return _orig(*a, **kw)
torch.load = _p

from TTS.utils.manage import ModelManager
from TTS.tts.configs.xtts_config import XttsConfig
from TTS.tts.models.xtts import Xtts
from TTS.tts.datasets import load_tts_samples
from TTS.config.shared_configs import BaseDatasetConfig
from trainer import Trainer, TrainerArgs

dataset_dir = "$DATASET_DIR"
output_dir  = "$OUTPUT_DIR"
epochs      = int("$EPOCHS")
batch_size  = int("$BATCH_SIZE")

# Download base model
print("Base XTTS v2 model yuklanmoqda...")
manager = ModelManager()
manager.download_model("tts_models/multilingual/multi-dataset/xtts_v2")

model_dir = Path.home() / ".local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2"
if not model_dir.exists():
    model_dir = Path("/root/.local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2")
print(f"Model dir: {model_dir}")

# Config
config = XttsConfig()
config.load_json(str(model_dir / "config.json"))

# Uzbek tilini qo'shish
if "uz" not in config.languages:
    config.languages.append("uz")
    print("'uz' tili qo'shildi")

# Training params
config.epochs           = epochs
config.batch_size       = batch_size
config.eval_batch_size  = max(1, batch_size // 2)
config.num_loader_workers = 4
config.lr               = 5e-6
config.optimizer        = "AdamW"
config.optimizer_params = {"betas": [0.9, 0.96], "eps": 1e-8, "weight_decay": 1e-2}
config.lr_scheduler     = "MultiStepLR"
config.lr_scheduler_params = {"milestones": [50000, 150000, 300000], "gamma": 0.5}
config.run_eval         = True
config.test_delay_epochs = -1
config.output_path      = output_dir
config.run_name         = "xtts_uzbek"

# Dataset
dataset_config = BaseDatasetConfig(
    formatter="ljspeech",
    dataset_name="uz_tts",
    path=dataset_dir,
    meta_file_train="metadata.csv",
    language="uz",
)
config.datasets = [dataset_config]

# Load samples
print("Datasetdan namunalar yuklanmoqda...")
train_samples, eval_samples = load_tts_samples(
    dataset_config,
    eval_split=True,
    eval_split_max_size=256,
    eval_split_size=0.01,
)
print(f"  Train: {len(train_samples)}, Eval: {len(eval_samples)}")

# Model
model = Xtts.init_from_config(config)
model.load_checkpoint(
    config,
    checkpoint_dir=str(model_dir),
    vocab_path=str(model_dir / "vocab.json"),
    eval=False,
    strict=False,
)

device = "cuda" if torch.cuda.is_available() else "cpu"
print(f"Device: {device}")
if device == "cuda":
    print(f"GPU: {torch.cuda.get_device_name(0)}")
    print(f"VRAM: {torch.cuda.get_device_properties(0).total_memory / 1024**3:.1f} GB")

# Train
trainer = Trainer(
    TrainerArgs(restore_path=None, skip_train_epoch=False, start_with_eval=False, grad_accum_steps=1),
    config,
    output_path=output_dir,
    model=model,
    train_samples=train_samples,
    eval_samples=eval_samples,
)

print("\nFine-tuning boshlandi...")
trainer.fit()
print(f"\nTayor! Model: {output_dir}")
EOF

echo
echo "=== Fine-tuning tugadi ==="
echo "Model saved: $OUTPUT_DIR"
echo "Run 5_test.sh to verify quality."
