#!/bin/bash
# Start GPU services on RunPod (assumes setup already done)
# Runs Demucs, WhisperX, and XTTS on GPU

set -e

echo "=== Starting RunPod GPU Services ==="

# Kill any existing services
pkill -f "uvicorn.*8000" 2>/dev/null || true
pkill -f "uvicorn.*8002" 2>/dev/null || true
pkill -f "uvicorn.*8004" 2>/dev/null || true
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

# Start Demucs on port 8000
echo "Starting Demucs on port 8000..."
cd /workspace/dubber/demucs-service
nohup python -m uvicorn app_runpod:app --host 0.0.0.0 --port 8000 > /tmp/demucs.log 2>&1 &

# Start WhisperX on port 8002
echo "Starting WhisperX on port 8002..."
cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &

# Start XTTS on port 8004
echo "Starting XTTS on port 8004..."
cd /workspace/dubber/xtts-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &

echo ""
echo "Waiting for services to load models (45s)..."
sleep 45

echo ""
echo "=== Service Status ==="
echo -n "Demucs (8000):   " && curl -s http://localhost:8000/health | python -c "import sys,json; d=json.load(sys.stdin); print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')" 2>/dev/null || echo "Still loading..."
echo -n "WhisperX (8002): " && curl -s http://localhost:8002/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
echo -n "XTTS (8004):     " && curl -s http://localhost:8004/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."

echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader

echo ""
echo "=== READY ==="
echo "Logs:"
echo "  tail -f /tmp/demucs.log"
echo "  tail -f /tmp/whisperx.log"
echo "  tail -f /tmp/xtts.log"
echo ""
echo "All logs: tail -f /tmp/demucs.log /tmp/whisperx.log /tmp/xtts.log"
