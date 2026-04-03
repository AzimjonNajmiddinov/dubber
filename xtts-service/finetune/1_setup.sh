#!/bin/bash
set -e

echo "=== XTTS Uzbek Fine-tuning Setup ==="
echo

VENV="/workspace/venv"

# Venv yaratish (mavjud bo'lsa skip)
if [ ! -f "$VENV/bin/python" ]; then
    echo "Creating venv at $VENV ..."
    python3 -m venv "$VENV"
fi

source "$VENV/bin/activate"

pip install -q --upgrade pip

# Torch (CUDA 12.8 bilan mos)
pip install -q \
    torch==2.2.0+cu121 \
    torchaudio==2.2.0+cu121 \
    --index-url https://download.pytorch.org/whl/cu121

pip install -q "transformers>=4.33.0,<4.46.0" "tokenizers>=0.13.0,<0.16.0"
pip install -q TTS==0.22.0 trainer "datasets==2.20.0" huggingface_hub soundfile

echo
echo "=== Versions ==="
python -c "import torch; print('torch:', torch.__version__, '| CUDA:', torch.cuda.is_available())"
python -c "import TTS; print('TTS:', TTS.__version__)"
echo
echo "Venv: $VENV"
echo "Done."
