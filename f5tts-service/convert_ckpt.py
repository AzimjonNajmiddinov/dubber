"""
Convert finetune_cli training checkpoint to safetensors inference format.
Strips 'ema_model.' prefix from EMA state dict keys and saves as safetensors,
which F5TTS loads directly without the ema_model_state_dict key lookup.

Usage:
    python convert_ckpt.py <input.pt> <output.safetensors>

Example:
    python convert_ckpt.py \
        /workspace/tts-venv/lib/python3.10/ckpts/f5tts-uz-data/model_last.pt \
        /root/f5tts-uz-finetuned/model_last.safetensors
"""

import sys
import torch
from safetensors.torch import save_file

if len(sys.argv) != 3:
    print(__doc__)
    sys.exit(1)

src, dst = sys.argv[1], sys.argv[2]
print(f'Loading {src}...')
ckpt = torch.load(src, map_location='cpu', weights_only=False)

ema_sd = ckpt.get('ema_model_state_dict', {})
print(f'EMA state dict: {len(ema_sd)} keys')

# Strip 'ema_model.' prefix; skip EMA bookkeeping scalars
converted = {}
for k, v in ema_sd.items():
    if k.startswith('ema_model.'):
        converted[k[len('ema_model.'):]] = v.float()
    # skip 'initted', 'step', etc.

print(f'Converted: {len(converted)} keys')
print('Sample keys:')
for k in list(converted.keys())[:5]:
    print(f'  {k}')

save_file(converted, dst)
print(f'Saved to {dst}')
