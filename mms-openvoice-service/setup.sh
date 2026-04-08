#!/bin/bash
# MMS TTS + OpenVoice v2 setup
# Run once on RunPod pod

set -e
VENV=/workspace/tts-venv

echo "=== Installing MMS TTS + OpenVoice v2 ==="

# Install Python packages into existing tts-venv
$VENV/bin/pip install --quiet transformers accelerate

# Install OpenVoice v2 from source
if [ ! -d /workspace/openvoice-v2 ]; then
    echo "Cloning OpenVoice v2..."
    git clone https://github.com/myshell-ai/OpenVoice /workspace/openvoice-v2
fi
cd /workspace/openvoice-v2
$VENV/bin/pip install --quiet -e .

# Download OpenVoice v2 checkpoints
mkdir -p /workspace/openvoice-v2/checkpoints_v2
if [ ! -f /workspace/openvoice-v2/checkpoints_v2/converter/checkpoint.pth ]; then
    echo "Downloading OpenVoice v2 checkpoints..."
    $VENV/bin/python -c "
from huggingface_hub import snapshot_download
snapshot_download(
    repo_id='myshell-ai/openvoice',
    local_dir='/workspace/openvoice-v2/checkpoints_v2',
    ignore_patterns=['*.md']
)
print('Checkpoints downloaded')
"
fi

# Download MMS TTS model (cached automatically on first use, but pre-download here)
echo "Pre-downloading MMS TTS (facebook/mms-tts-uzb)..."
$VENV/bin/python -c "
from transformers import VitsModel, AutoTokenizer
AutoTokenizer.from_pretrained('facebook/mms-tts-uzb')
VitsModel.from_pretrained('facebook/mms-tts-uzb')
print('MMS TTS downloaded')
"

echo ""
echo "=== Setup complete ==="
echo "Start service: cd /workspace/dubber/mms-openvoice-service && /workspace/tts-venv/bin/uvicorn app:app --host 0.0.0.0 --port 8005"
