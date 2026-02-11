#!/bin/bash
# Start GPU services on RunPod
# Runs Demucs, WhisperX, XTTS, and Lipsync on GPU
#
# Usage:
#   ./start_runpod_services.sh           # Full start with dependency check
#   ./start_runpod_services.sh --skip-deps  # Skip dependency installation
#   ./start_runpod_services.sh --deps-only  # Only install dependencies, don't start services

set -e

SKIP_DEPS=false
DEPS_ONLY=false

for arg in "$@"; do
    case $arg in
        --skip-deps) SKIP_DEPS=true ;;
        --deps-only) DEPS_ONLY=true ;;
    esac
done

echo "=== Starting RunPod GPU Services ==="

# Kill any existing services
pkill -f "uvicorn.*8000" 2>/dev/null || true
pkill -f "uvicorn.*8002" 2>/dev/null || true
pkill -f "uvicorn.*8004" 2>/dev/null || true
pkill -f "uvicorn.*8006" 2>/dev/null || true
sleep 2

# Pull latest code
cd /workspace/dubber
git pull --ff-only 2>/dev/null || git fetch && git reset --hard origin/main

# ===========================================
# INSTALL/FIX DEPENDENCIES (run once or after pod restart)
# ===========================================
if [ "$SKIP_DEPS" = false ]; then
    echo "Checking and installing dependencies..."

    # Use --ignore-installed to bypass distutils uninstall issues (e.g., blinker)
    PIP_FLAGS="--ignore-installed --no-warn-script-location"

    # Check if uvicorn is installed
    if ! python -c "import uvicorn" 2>/dev/null; then
        echo "Installing uvicorn and fastapi..."
        pip install $PIP_FLAGS uvicorn fastapi python-multipart aiofiles
    fi

    # Check torch version and CUDA compatibility
    TORCH_VERSION=$(python -c "import torch; print(torch.__version__)" 2>/dev/null || echo "none")
    if [[ "$TORCH_VERSION" == "none" ]] || [[ ! "$TORCH_VERSION" == *"cu"* ]]; then
        echo "Installing PyTorch with CUDA support..."
        pip install $PIP_FLAGS torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121
    fi

    # Check transformers for WhisperX
    if ! python -c "from transformers import GPT2PreTrainedModel" 2>/dev/null; then
        echo "Installing transformers..."
        pip install $PIP_FLAGS transformers==4.38.0
    fi

    # Fix numpy/numba compatibility (required for WhisperX)
    NUMPY_VERSION=$(python -c "import numpy; print(numpy.__version__)" 2>/dev/null || echo "none")
    if [[ "$NUMPY_VERSION" == "2."* ]] || [[ "$NUMPY_VERSION" == "none" ]]; then
        echo "Fixing numpy/numba versions for compatibility..."
        pip install $PIP_FLAGS numpy==1.26.4 numba==0.59.0 pandas==1.5.3
    fi

    # Install WhisperX dependencies
    if ! python -c "import whisperx" 2>/dev/null; then
        echo "Installing WhisperX..."
        pip install $PIP_FLAGS whisperx
    fi

    # Install TTS for XTTS
    if ! python -c "from TTS.api import TTS" 2>/dev/null; then
        echo "Installing TTS (Coqui)..."
        pip install $PIP_FLAGS TTS
    fi

    echo "Dependencies OK"
else
    echo "Skipping dependency check (--skip-deps)"
fi

# Exit early if only installing dependencies
if [ "$DEPS_ONLY" = true ]; then
    echo "Dependencies installed. Exiting (--deps-only mode)."
    exit 0
fi

# ===========================================
# ENVIRONMENT SETUP
# ===========================================

# Ensure cuDNN libraries are on the path
CUDNN_PATH=$(python -c "import nvidia.cudnn; print(nvidia.cudnn.__path__[0] + '/lib')" 2>/dev/null || true)
if [ -n "$CUDNN_PATH" ]; then
    export LD_LIBRARY_PATH="${CUDNN_PATH}:${LD_LIBRARY_PATH}"
fi

# HF token for WhisperX (set in RunPod secrets or env)
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

# Start Lipsync on port 8006
echo "Starting Lipsync on port 8006..."
cd /workspace/dubber/lipsync-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8006 > /tmp/lipsync.log 2>&1 &

echo ""
echo "Waiting for services to load models (60s)..."
sleep 60

echo ""
echo "=== Service Status ==="
echo -n "Demucs (8000):   " && curl -s http://localhost:8000/health | python -c "import sys,json; d=json.load(sys.stdin); print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')" 2>/dev/null || echo "Still loading..."
echo -n "WhisperX (8002): " && curl -s http://localhost:8002/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
echo -n "XTTS (8004):     " && curl -s http://localhost:8004/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
echo -n "Lipsync (8006):  " && curl -s http://localhost:8006/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."

echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader

echo ""
echo "=== READY ==="
echo "Logs:"
echo "  tail -f /tmp/demucs.log"
echo "  tail -f /tmp/whisperx.log"
echo "  tail -f /tmp/xtts.log"
echo "  tail -f /tmp/lipsync.log"
echo ""
echo "All logs: tail -f /tmp/*.log"
