#!/bin/bash
set -e

echo "=== XTTS Uzbek Fine-tuning Setup ==="
echo

pip install -q --upgrade pip

# Install torch 2.2.0+cu121 (compatible with CUDA driver 12.x)
echo "Installing torch (this downloads ~2GB, takes 3-5 min)..."
pip install \
    torch==2.2.0+cu121 \
    torchaudio==2.2.0+cu121 \
    --index-url https://download.pytorch.org/whl/cu121

pip install -q --force-reinstall "tokenizers>=0.13.0,<0.16.0"
pip install -q "transformers>=4.33.0,<4.46.0"
pip install -q TTS==0.22.0 trainer "datasets==2.20.0" huggingface_hub soundfile

echo
echo "=== Versions ==="
python3 -c "import torch; print('torch:', torch.__version__, '| CUDA:', torch.cuda.is_available())"
python3 -c "import TTS; print('TTS:', TTS.__version__)"
echo
echo "Done."
