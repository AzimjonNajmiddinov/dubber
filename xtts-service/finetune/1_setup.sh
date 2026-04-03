#!/bin/bash
set -e

echo "=== XTTS Uzbek Fine-tuning Setup ==="
echo

pip install -q --upgrade pip

# Eski torch ni o'chirish
pip uninstall -y torch torchaudio torchvision 2>/dev/null || true

# CUDA 12.8 driver bilan mos torch (cu121 = forward compatible)
pip install -q \
    torch==2.2.0+cu121 \
    torchaudio==2.2.0+cu121 \
    --index-url https://download.pytorch.org/whl/cu121

pip install -q TTS==0.22.0 trainer "datasets==2.20.0" huggingface_hub soundfile

echo
echo "=== Versions ==="
python -c "import torch; print('torch:', torch.__version__, '| CUDA:', torch.cuda.is_available())"
python -c "import TTS; print('TTS:', TTS.__version__)"
echo
echo "Done. Run 2_download_dataset.sh next."
