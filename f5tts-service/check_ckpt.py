import torch

ckpt_path = '/workspace/tts-venv/lib/python3.10/ckpts/f5tts-uz-data/model_last.pt'
print(f'Loading {ckpt_path}...')
ckpt = torch.load(ckpt_path, map_location='cpu', weights_only=False)
print('Top keys:', list(ckpt.keys()))

# Check EMA model state dict
ema = ckpt.get('ema_model_state_dict', {})
print(f'\nEMA state dict: {len(ema)} keys')
# Show first 5 keys
for i, k in enumerate(list(ema.keys())[:5]):
    print(f'  {k}')

# Check text embed shape in EMA
for k, v in ema.items():
    if 'text_embed.weight' in k or 'text_embed.text_embed' in k:
        print(f'\nEMA text_embed: {k} -> {v.shape}')

# Check model_state_dict text embed (for comparison)
model_sd = ckpt.get('model_state_dict', {})
for k, v in model_sd.items():
    if 'text_embed.text_embed.weight' in k:
        print(f'model text_embed: {k} -> {v.shape}')

# Now try actually loading into F5TTS and see what happens
print('\n--- Trying to load into F5TTS ---')
try:
    import sys
    sys.path.insert(0, '/workspace/tts-venv/lib/python3.10/site-packages')
    from f5_tts.infer.utils_infer import load_checkpoint, load_model
    from f5_tts.model import DiT
    from f5_tts.model.utils import get_tokenizer

    vocab_file = '/workspace/f5tts-uz-data_char/vocab.txt'
    tokenizer = 'custom'
    vocab_char_map, vocab_size = get_tokenizer(vocab_file, tokenizer)
    print(f'Tokenizer vocab_size: {vocab_size}')

    model_cfg = dict(dim=1024, depth=22, heads=16, ff_mult=2, text_dim=512, conv_layers=4)
    model = DiT(**model_cfg, text_num_embeds=vocab_size, mel_dim=100)
    print(f'Model text_embed size: {model.transformer.text_embed.text_embed.weight.shape}')

    model = load_checkpoint(model, ckpt_path, 'cpu', use_ema=True)
    print(f'After load text_embed size: {model.transformer.text_embed.text_embed.weight.shape}')
    print('Load successful!')
except Exception as e:
    print(f'Load failed: {e}')
    import traceback
    traceback.print_exc()
