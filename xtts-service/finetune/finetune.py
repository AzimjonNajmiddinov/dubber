#!/usr/bin/env python3
"""
XTTS v2 Fine-tuning on Uzbek Dataset

Usage:
  python finetune.py \
    --dataset /data/uz_tts \
    --output /data/xtts-uz-finetuned \
    --epochs 10 \
    --batch-size 4

After training, copy checkpoint to xtts-service and update MODEL_PATH in app.py.
"""

import argparse
import os
from pathlib import Path

os.environ["COQUI_TOS_AGREED"] = "1"


def run_finetune(dataset_path: str, output_path: str, epochs: int, batch_size: int):
    import torch
    from trainer import Trainer, TrainerArgs
    from TTS.config.shared_configs import BaseDatasetConfig
    from TTS.tts.configs.xtts_config import XttsConfig
    from TTS.tts.datasets import load_tts_samples
    from TTS.tts.models.xtts import Xtts
    from TTS.utils.manage import ModelManager

    # Download base XTTS v2 model
    print("Downloading base XTTS v2 model...")
    manager = ModelManager()
    manager.download_model("tts_models/multilingual/multi-dataset/xtts_v2")

    model_dir = Path.home() / ".local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2"
    if not model_dir.exists():
        model_dir = Path("/root/.local/share/tts/tts_models--multilingual--multi-dataset--xtts_v2")

    config_path = str(model_dir / "config.json")
    checkpoint_dir = str(model_dir)
    vocab_file = str(model_dir / "vocab.json")

    print(f"Base model: {model_dir}")

    # Load base config
    config = XttsConfig()
    config.load_json(config_path)

    # Add Uzbek to supported languages
    if "uz" not in config.languages:
        config.languages.append("uz")
        print("Added 'uz' to supported languages")

    # Training config
    config.epochs = epochs
    config.batch_size = batch_size
    config.eval_batch_size = max(1, batch_size // 2)
    config.num_loader_workers = 4
    config.num_eval_loader_workers = 2
    config.run_eval = True
    config.test_delay_epochs = -1
    config.epochs_between_tests = 5

    # Optimizer
    config.lr = 5e-6
    config.optimizer = "AdamW"
    config.optimizer_params = {"betas": [0.9, 0.96], "eps": 1e-8, "weight_decay": 1e-2}
    config.lr_scheduler = "MultiStepLR"
    config.lr_scheduler_params = {"milestones": [50000, 150000, 300000], "gamma": 0.5}

    # Output
    config.output_path = output_path
    config.run_name = "xtts_uzbek"

    # Dataset
    dataset_config = BaseDatasetConfig(
        formatter="ljspeech",
        dataset_name="uz_tts",
        path=dataset_path,
        meta_file_train="metadata.csv",
        language="uz",
    )
    config.datasets = [dataset_config]

    # Load samples
    print("Loading dataset samples...")
    train_samples, eval_samples = load_tts_samples(
        dataset_config,
        eval_split=True,
        eval_split_max_size=256,
        eval_split_size=0.01,
    )
    print(f"  Train: {len(train_samples)}, Eval: {len(eval_samples)}")

    # Init model
    model = Xtts.init_from_config(config)
    model.load_checkpoint(
        config,
        checkpoint_dir=checkpoint_dir,
        vocab_path=vocab_file,
        eval=False,
        strict=False,
    )

    if torch.cuda.is_available():
        print(f"GPU: {torch.cuda.get_device_name(0)}")
    else:
        print("WARNING: No GPU found. Training will be very slow on CPU.")

    # Trainer
    trainer = Trainer(
        TrainerArgs(
            restore_path=None,
            skip_train_epoch=False,
            start_with_eval=False,
            grad_accum_steps=1,
        ),
        config,
        output_path=output_path,
        model=model,
        train_samples=train_samples,
        eval_samples=eval_samples,
    )

    print("\nStarting fine-tuning...")
    print(f"  Epochs    : {epochs}")
    print(f"  Batch size: {batch_size}")
    print(f"  Output    : {output_path}")
    print()

    trainer.fit()

    print(f"\nFine-tuning complete. Model saved to: {output_path}")
    print("\nNext step: update xtts-service/app.py to load from this checkpoint.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset", required=True, help="Path to prepared dataset (output of prepare_dataset.py)")
    parser.add_argument("--output", required=True, help="Output directory for fine-tuned model")
    parser.add_argument("--epochs", type=int, default=10, help="Number of epochs (default: 10)")
    parser.add_argument("--batch-size", type=int, default=4, help="Batch size (default: 4)")
    args = parser.parse_args()

    Path(args.output).mkdir(parents=True, exist_ok=True)
    run_finetune(args.dataset, args.output, args.epochs, args.batch_size)


if __name__ == "__main__":
    main()
