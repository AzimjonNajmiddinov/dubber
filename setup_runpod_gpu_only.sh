#!/bin/bash
# RunPod GPU-Only Setup - Just XTTS and WhisperX services
# Run this on RunPod, then connect from your local Laravel app

set -e

echo "=== GPU Services Only Setup ==="

echo "[1/3] Installing Python GPU packages..."
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121
pip install --ignore-installed blinker
pip install TTS faster-whisper whisperx fastapi uvicorn python-multipart

echo "[2/3] Cloning app (for Python services only)..."
cd /workspace
rm -rf dubber
git clone https://github.com/AzimjonNajmiddinov/dubber.git
cd dubber

echo "[3/3] Starting GPU services..."
export HF_TOKEN="${HF_TOKEN:-YOUR_HF_TOKEN_HERE}"

# Start XTTS on port 8004
cd /workspace/dubber/xtts-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &
echo "XTTS starting on port 8004..."

# Start WhisperX on port 8002
cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &
echo "WhisperX starting on port 8002..."

echo ""
echo "Waiting for services to load models..."
sleep 30

echo ""
echo "=== Checking Services ==="
echo "XTTS:" && curl -s http://localhost:8004/health || echo "Still loading..."
echo ""
echo "WhisperX:" && curl -s http://localhost:8002/health || echo "Still loading..."
echo ""
echo "GPU:" && nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv

echo ""
echo "==========================================="
echo "  GPU SERVICES READY!"
echo "==========================================="
echo ""
echo "Your RunPod public URL should be something like:"
echo "  https://<pod-id>-8004.proxy.runpod.net  (XTTS)"
echo "  https://<pod-id>-8002.proxy.runpod.net  (WhisperX)"
echo ""
echo "Or use the direct pod IP with exposed ports."
echo ""
echo "Update your LOCAL .env file:"
echo "  XTTS_SERVICE_URL=https://<pod-id>-8004.proxy.runpod.net"
echo "  WHISPERX_SERVICE_URL=https://<pod-id>-8002.proxy.runpod.net"
echo ""
echo "To check logs:"
echo "  tail -f /tmp/xtts.log"
echo "  tail -f /tmp/whisperx.log"
