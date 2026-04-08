#!/bin/bash
# Setup Seed-VC service (MMS TTS + Seed-VC voice conversion)
# Run once on a new pod. Uses /workspace/tts-venv.

set -e
TTS_VENV=/workspace/tts-venv

echo "=== Seed-VC Setup ==="

# 1. Clone Seed-VC repo
if [ ! -d /workspace/seed-vc ]; then
    echo "Cloning Seed-VC..."
    git clone https://github.com/Plachtaa/SEED-VC /workspace/seed-vc
else
    echo "Seed-VC repo already exists, pulling latest..."
    git -C /workspace/seed-vc pull
fi

# 2. Install Seed-VC dependencies into tts-venv
echo "Installing Seed-VC dependencies..."
$TTS_VENV/bin/pip install -q -r /workspace/seed-vc/requirements.txt

# Also install MMS TTS deps if not already there
$TTS_VENV/bin/pip install -q transformers soundfile scipy

# 3. Download Seed-VC model weights via their own hf_utils
# Seed-VC downloads models automatically via hf_utils.py on first run.
# We trigger it here by running a quick import + model load.
echo "Pre-downloading Seed-VC model weights (first run may take 5-10 min)..."
cd /workspace/seed-vc
$TTS_VENV/bin/python -c "
import sys
sys.path.insert(0, '/workspace/seed-vc')
try:
    from hf_utils import load_custom_model_from_hf
    # This triggers model downloads for the default DiT model
    load_custom_model_from_hf('Plachtaa/Seed-VC', 'DiT_uvit_wav2vec2_small.pth', None)
    print('Seed-VC model downloaded')
except Exception as e:
    print(f'Pre-download failed (will download on first request): {e}')
" 2>&1 || echo "Pre-download skipped — models will download on first request"
cd /workspace/dubber

# 4. Pre-download MMS TTS model
echo "Pre-downloading MMS TTS (facebook/mms-tts-uzb-script_cyrillic)..."
$TTS_VENV/bin/python -c "
from transformers import VitsModel, AutoTokenizer
AutoTokenizer.from_pretrained('facebook/mms-tts-uzb-script_cyrillic')
VitsModel.from_pretrained('facebook/mms-tts-uzb-script_cyrillic')
print('MMS TTS downloaded')
"

echo ""
echo "=== Setup complete ==="
echo "Start with:"
echo "  cd /workspace/dubber/seed-vc-service"
echo "  nohup /workspace/tts-venv/bin/uvicorn app:app --host 0.0.0.0 --port 8005 > /tmp/mms.log 2>&1 &"
