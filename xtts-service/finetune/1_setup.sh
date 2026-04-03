#!/bin/bash
set -e

echo "=== XTTS Uzbek Fine-tuning Setup ==="
echo

pip install -q --upgrade pip
pip install -q --ignore-installed TTS==0.22.0 trainer torchaudio datasets huggingface_hub soundfile

echo
echo "=== Versions ==="
python -c "import torch; print('torch:', torch.__version__, '| CUDA:', torch.cuda.is_available())"
python -c "import TTS; print('TTS:', TTS.__version__)"
echo
echo "Done. Run 2_download_dataset.sh next."
