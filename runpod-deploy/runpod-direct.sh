#!/bin/bash
# Direct RunPod Deployment (no nested Docker)
# This runs XTTS directly on the RunPod GPU pod

set -e

echo "=========================================="
echo "  Dubber XTTS - Direct RunPod Deployment"
echo "=========================================="

# Check GPU
echo ""
echo "=== Checking GPU ==="
nvidia-smi --query-gpu=name,memory.total --format=csv || { echo "ERROR: No GPU found!"; exit 1; }

# Install dependencies
echo ""
echo "=== Installing System Dependencies ==="
apt-get update
apt-get install -y ffmpeg git curl

# Setup Python environment
echo ""
echo "=== Setting up Python Environment ==="
pip install --upgrade pip

# Clone repository
echo ""
echo "=== Cloning Repository ==="
cd /workspace
if [ -d "dubber" ]; then
    cd dubber
    git pull
else
    git clone https://github.com/AzimjonNajmiddinov/dubber.git
    cd dubber
fi

# Install XTTS dependencies
echo ""
echo "=== Installing XTTS Dependencies ==="
pip install fastapi uvicorn python-multipart pydantic
pip install TTS
pip install torch torchaudio --index-url https://download.pytorch.org/whl/cu121

# Create directories
mkdir -p /workspace/voices
mkdir -p /workspace/storage

# Set environment variables
export COQUI_TOS_AGREED=1
export STORAGE_PATH=/workspace/storage
export VOICES_PATH=/workspace/voices
export PYTHONUNBUFFERED=1

# Start XTTS service
echo ""
echo "=== Starting XTTS Service ==="
cd /workspace/dubber/xtts-service

# Run in background with nohup
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8000 > /workspace/xtts.log 2>&1 &
XTTS_PID=$!
echo "XTTS started with PID: $XTTS_PID"

# Wait for service to be ready
echo ""
echo "=== Waiting for XTTS to load model (this takes 2-3 minutes) ==="
for i in {1..60}; do
    if curl -s http://localhost:8000/health > /dev/null 2>&1; then
        echo "XTTS is ready!"
        break
    fi
    echo "Waiting... ($i/60)"
    sleep 5
done

# Check health
echo ""
echo "=== XTTS Health Check ==="
curl -s http://localhost:8000/health | python3 -m json.tool || echo "Still loading..."

# Get public URL
PUBLIC_IP=$(curl -s ifconfig.me 2>/dev/null || echo "localhost")

echo ""
echo "=========================================="
echo "  XTTS Deployment Complete!"
echo "=========================================="
echo ""
echo "XTTS API: http://localhost:8000"
echo "Health:   http://localhost:8000/health"
echo ""
echo "To connect your local app, update .env:"
echo "  XTTS_SERVICE_URL=https://YOUR_RUNPOD_ID-8000.proxy.runpod.net"
echo ""
echo "View logs: tail -f /workspace/xtts.log"
echo "Check GPU: nvidia-smi"
echo ""
echo "Test voice cloning:"
echo '  curl -X POST http://localhost:8000/clone \'
echo '    -F "audio=@sample.wav" \'
echo '    -F "name=test" \'
echo '    -F "language=en"'
echo "=========================================="
