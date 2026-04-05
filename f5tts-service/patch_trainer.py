"""
Patch F5-TTS trainer.py to skip shape-mismatched tensors when loading checkpoints.

strict=False in PyTorch skips missing/extra keys but still raises on shape mismatches.
We need to filter out mismatching keys (text_embed: 2546 vs 67) before loading.

Usage:
    python patch_trainer.py
"""
from pathlib import Path
import site

candidates = [
    Path("/workspace/tts-venv/lib/python3.10/site-packages/f5_tts/model/trainer.py"),
]
for sp in site.getsitepackages():
    candidates.append(Path(sp) / "f5_tts/model/trainer.py")

trainer_path = next((p for p in candidates if p.exists()), None)
if trainer_path is None:
    raise FileNotFoundError("trainer.py not found")

text = trainer_path.read_text()

# Already fully patched (both ema and unwrap_model calls)?
if "_shape_filtered_load(self.accelerator.unwrap_model" in text:
    print(f"Already patched: {trainer_path}")
    exit(0)

# Revert any previous strict=False-only patch first
text = text.replace(
    'self.ema_model.load_state_dict(checkpoint["ema_model_state_dict"], strict=False)',
    'self.ema_model.load_state_dict(checkpoint["ema_model_state_dict"])',
)
text = text.replace(
    'self.model.load_state_dict(checkpoint["model_state_dict"], strict=False)',
    'self.model.load_state_dict(checkpoint["model_state_dict"])',
)

# Replace ema_model load with shape-filtered version
text = text.replace(
    'self.ema_model.load_state_dict(checkpoint["ema_model_state_dict"])',
    '_shape_filtered_load(self.ema_model, checkpoint["ema_model_state_dict"])',
)
# Replace model load with shape-filtered version (direct and unwrapped)
text = text.replace(
    'self.model.load_state_dict(checkpoint["model_state_dict"])',
    '_shape_filtered_load(self.model, checkpoint["model_state_dict"])',
)
text = text.replace(
    'self.accelerator.unwrap_model(self.model).load_state_dict(checkpoint["model_state_dict"])',
    '_shape_filtered_load(self.accelerator.unwrap_model(self.model), checkpoint["model_state_dict"])',
)

# Inject the helper function near the top of the file (after imports)
helper = '''
def _shape_filtered_load(model, state_dict):
    """Load state_dict, skipping tensors with mismatched shapes (e.g. text_embed vocab size)."""
    cur = model.state_dict()
    filtered = {k: v for k, v in state_dict.items() if k not in cur or v.shape == cur[k].shape}
    skipped = [k for k in state_dict if k in cur and state_dict[k].shape != cur[k].shape]
    if skipped:
        import logging
        logging.getLogger(__name__).warning(
            f"Skipping {len(skipped)} shape-mismatched keys: {skipped[:5]}{'...' if len(skipped)>5 else ''}"
        )
    model.load_state_dict(filtered, strict=False)

'''

# Insert after the last import line
lines = text.splitlines(keepends=True)
insert_at = 0
for i, line in enumerate(lines):
    if line.startswith("import ") or line.startswith("from "):
        insert_at = i + 1
lines.insert(insert_at, helper)
patched = "".join(lines)

trainer_path.write_text(patched)
print(f"Patched {trainer_path}: shape-filtered load_state_dict injected")
