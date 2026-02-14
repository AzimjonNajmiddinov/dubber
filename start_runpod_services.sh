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
# INSTALL/FIX DEPENDENCIES
# ===========================================
if [ "$SKIP_DEPS" = false ]; then
    echo "Installing/fixing dependencies..."

    PIP_FLAGS="--no-warn-script-location -q"

    # Step 1: Core web packages
    echo "  [1/5] Installing uvicorn/fastapi..."
    pip install $PIP_FLAGS uvicorn fastapi python-multipart aiofiles pydantic

    # Step 2: PyTorch 2.8.0 with CUDA 12.4 (pinned to match whisperx ~=2.8.0)
    echo "  [2/5] Installing PyTorch 2.8.0 with CUDA 12.4..."
    pip install $PIP_FLAGS torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu124

    # Constraints file prevents later packages from upgrading/downgrading torch
    CONSTRAINTS="/tmp/torch-constraints.txt"
    printf "torch==2.8.0\ntorchaudio==2.8.0\n" > "$CONSTRAINTS"

    # Step 3: Data & HuggingFace stack (modern versions for whisperx/pyannote)
    echo "  [3/5] Installing numpy/pandas/scipy/transformers..."
    pip install $PIP_FLAGS -c "$CONSTRAINTS" \
        "numpy>=2.1,<2.5" \
        "pandas>=2.2.3" \
        "scipy>=1.12" \
        "huggingface_hub>=0.25,<1.0.0" \
        "transformers>=4.48" \
        "tokenizers>=0.22,<0.24"

    # Step 4: WhisperX (strictest torch requirement: ~=2.8.0)
    echo "  [4/5] Installing WhisperX..."
    pip install $PIP_FLAGS -c "$CONSTRAINTS" whisperx

    # Step 5: Demucs + TTS (constrained to keep torch 2.8.0)
    echo "  [5/5] Installing Demucs & TTS..."
    pip install $PIP_FLAGS -c "$CONSTRAINTS" demucs TTS

    echo "Dependencies installed!"

    # Verify key packages
    echo ""
    echo "Package versions:"
    python -c "import torch; print(f'  torch: {torch.__version__}')"
    python -c "import numpy; print(f'  numpy: {numpy.__version__}')"
    python -c "import pandas; print(f'  pandas: {pandas.__version__}')"
    python -c "import scipy; print(f'  scipy: {scipy.__version__}')"
    python -c "import transformers; print(f'  transformers: {transformers.__version__}')"
    python -c "import whisperx; print(f'  whisperx: OK')"
    python -c "import demucs; print(f'  demucs: OK')"
    python -c "from TTS.api import TTS; print(f'  TTS: OK')"
    echo ""
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
export DEVICE="cuda"

# Verify HF_TOKEN is set
if [ -z "$HF_TOKEN" ]; then
    echo "WARNING: HF_TOKEN not set. WhisperX will fail!"
    echo "Set it with: export HF_TOKEN='your_token'"
fi

# ===========================================
# START SERVICES
# ===========================================

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
echo -n "XTTS (8004):     " && curl -s http://localhost:8004/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('status')=='healthy' else 'Error')" 2>/dev/null || echo "Still loading..."
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
