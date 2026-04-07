"""Check F5TTS model parameter names vs checkpoint keys."""
import torch
import sys
sys.path.insert(0, '/workspace/tts-venv/lib/python3.10/site-packages')

from f5_tts.model.utils import get_tokenizer
from f5_tts.model import DiT

vocab_file = '/workspace/f5tts-uz-data_char/vocab.txt'
vocab_char_map, vocab_size = get_tokenizer(vocab_file, 'custom')
print(f'vocab_size: {vocab_size}')

model_cfg = dict(dim=1024, depth=22, heads=16, ff_mult=2, text_dim=512, conv_layers=4)
model = DiT(**model_cfg, text_num_embeds=vocab_size, mel_dim=100)

# Print first 10 parameter names from model
print('\nModel parameter names (first 10):')
for i, (k, v) in enumerate(model.state_dict().items()):
    print(f'  {k}: {v.shape}')
    if i >= 9:
        break

# Print total param count
total = sum(p.numel() for p in model.parameters())
print(f'\nTotal model params: {total:,}')

# Load checkpoint and check key match
print('\n--- Checking key match with checkpoint ---')
ckpt = torch.load(
    '/workspace/tts-venv/lib/python3.10/ckpts/f5tts-uz-data/model_last.pt',
    map_location='cpu', weights_only=False
)
ema_sd = ckpt['ema_model_state_dict']
ema_keys = {k[len('ema_model.'):] for k in ema_sd if k.startswith('ema_model.')}
model_keys = set(model.state_dict().keys())

matched = ema_keys & model_keys
unmatched_ema = ema_keys - model_keys
unmatched_model = model_keys - ema_keys

print(f'Checkpoint keys: {len(ema_keys)}')
print(f'Model keys:      {len(model_keys)}')
print(f'Matched:         {len(matched)}')
print(f'Unmatched in checkpoint (first 5): {list(unmatched_ema)[:5]}')
print(f'Unmatched in model (first 5):      {list(unmatched_model)[:5]}')
