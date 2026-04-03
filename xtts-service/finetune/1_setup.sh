#!/bin/bash
set -e

echo "=== XTTS Uzbek Fine-tuning Setup ==="
echo

# RunPod pods come with torch+CUDA pre-installed in system Python.
# We only install missing packages on top of it.

# Check torch
python3 -c "import torch; assert torch.cuda.is_available(), 'CUDA not available!'; print('torch OK:', torch.__version__)"

pip install -q --upgrade pip
pip install -q "transformers>=4.33.0,<4.46.0" "tokenizers>=0.13.0,<0.16.0"
pip install -q TTS==0.22.0 trainer "datasets==2.20.0" huggingface_hub soundfile

echo
echo "=== Versions ==="
python3 -c "import torch; print('torch:', torch.__version__, '| CUDA:', torch.cuda.is_available())"
python3 -c "import TTS; print('TTS:', TTS.__version__)"
echo
echo "Done."
