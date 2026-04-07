"""
Convert finetune_cli training checkpoint to inference-ready format.
Strips 'ema_model.' prefix from EMA state dict keys so F5TTS can load it.

Usage:
    python convert_ckpt.py <input_ckpt> <output_ckpt>

Example:
    python convert_ckpt.py \
        /workspace/tts-venv/lib/python3.10/ckpts/f5tts-uz-data/model_last.pt \
        /root/f5tts-uz-finetuned/model_last_converted.pt
"""

import sys
import torch

if len(sys.argv) != 3:
    print(__doc__)
    sys.exit(1)

src, dst = sys.argv[1], sys.argv[2]
print(f'Loading {src}...')
ckpt = torch.load(src, map_location='cpu', weights_only=False)

ema_sd = ckpt.get('ema_model_state_dict', {})
print(f'EMA state dict: {len(ema_sd)} keys')

# Strip 'ema_model.' prefix
converted = {}
for k, v in ema_sd.items():
    if k.startswith('ema_model.'):
        converted[k[len('ema_model.'):]] = v
    elif k in ('initted', 'step'):
        pass  # skip EMA bookkeeping keys
    else:
        converted[k] = v

print(f'Converted: {len(converted)} keys')
print('Sample keys:')
for k in list(converted.keys())[:5]:
    print(f'  {k}')

torch.save(converted, dst)
print(f'Saved to {dst}')
