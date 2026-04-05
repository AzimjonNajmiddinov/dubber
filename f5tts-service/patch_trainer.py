"""
Patch F5-TTS trainer.py to use strict=False when loading checkpoints.

This allows fine-tuning with a custom vocab (e.g., Uzbek 67 chars) on top of
the pretrained F5-TTS model (2546-char vocab). All acoustic model weights are
loaded; only text_embed is randomly initialized for the new vocab size.

Usage:
    python patch_trainer.py
"""
import re
from pathlib import Path
import site

# Find trainer.py in the active venv/site-packages
candidates = [
    Path("/workspace/tts-venv/lib/python3.10/site-packages/f5_tts/model/trainer.py"),
]
for sp in site.getsitepackages():
    candidates.append(Path(sp) / "f5_tts/model/trainer.py")

trainer_path = next((p for p in candidates if p.exists()), None)
if trainer_path is None:
    raise FileNotFoundError("trainer.py not found")

text = trainer_path.read_text()

# Patch load_state_dict calls to use strict=False
patched = text.replace(
    'self.ema_model.load_state_dict(checkpoint["ema_model_state_dict"])',
    'self.ema_model.load_state_dict(checkpoint["ema_model_state_dict"], strict=False)',
)
patched = patched.replace(
    'self.model.load_state_dict(checkpoint["model_state_dict"])',
    'self.model.load_state_dict(checkpoint["model_state_dict"], strict=False)',
)

if patched == text:
    print("WARNING: No changes made — patterns not found. Check trainer.py manually.")
else:
    trainer_path.write_text(patched)
    n = patched.count("strict=False") - text.count("strict=False")
    print(f"Patched {trainer_path}: added strict=False to {n} load_state_dict call(s)")
