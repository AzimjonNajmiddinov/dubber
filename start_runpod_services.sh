#!/bin/bash
# Start GPU services on RunPod (assumes setup already done)
# Just pulls latest code and starts XTTS + WhisperX

set -e

echo "=== Starting RunPod GPU Services ==="

# Kill any existing services
pkill -f "uvicorn.*8004" 2>/dev/null || true
pkill -f "uvicorn.*8002" 2>/dev/null || true
sleep 2

# Pull latest code
cd /workspace/dubber
git pull --ff-only 2>/dev/null || git fetch && git reset --hard origin/main

# Ensure cuDNN libraries are on the path
CUDNN_PATH=$(python -c "import nvidia.cudnn; print(nvidia.cudnn.__path__[0] + '/lib')" 2>/dev/null || true)
if [ -n "$CUDNN_PATH" ]; then
    export LD_LIBRARY_PATH="${CUDNN_PATH}:${LD_LIBRARY_PATH}"
fi

# HF token for WhisperX
export HF_TOKEN="${HF_TOKEN:-}"

# Start XTTS on port 8004
cd /workspace/dubber/xtts-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &
echo "XTTS starting on port 8004..."

# Start WhisperX on port 8002
cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &
echo "WhisperX starting on port 8002..."

echo ""
echo "Waiting for services to load models (30s)..."
sleep 30

echo ""
echo "=== Service Status ==="
echo -n "XTTS:     " && curl -s http://localhost:8004/health || echo "Still loading..."
echo -n "WhisperX: " && curl -s http://localhost:8002/health || echo "Still loading..."
echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader

echo ""
echo "=== READY ==="
echo "Logs: tail -f /tmp/xtts.log /tmp/whisperx.log"
