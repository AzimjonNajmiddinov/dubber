import torch

ckpt_path = '/workspace/tts-venv/lib/python3.10/ckpts/f5tts-uz-data/model_last.pt'
print(f'Loading {ckpt_path}...')
ckpt = torch.load(ckpt_path, map_location='cpu', weights_only=False)
print('Top keys:', list(ckpt.keys()))

def find_text(d, prefix=''):
    if isinstance(d, dict):
        for k, v in d.items():
            find_text(v, prefix + '.' + k if prefix else k)
    elif hasattr(d, 'shape') and 'text' in prefix.lower():
        print(f'{prefix}: {d.shape}')

find_text(ckpt)
