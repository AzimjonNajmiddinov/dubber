#!/bin/bash
# Start GPU services on RunPod
# Runs Demucs, WhisperX, and OpenVoice on GPU
#
# Usage:
#   ./start_runpod_services.sh                # All services
#   ./start_runpod_services.sh --skip-deps    # Skip dependency installation
#   ./start_runpod_services.sh --deps-only    # Only install dependencies, don't start services

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
pkill -f "uvicorn.*8005" 2>/dev/null || true
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

    # Step 1: Clean up broken state from previous installs
    echo "  [1/5] Cleaning up old packages..."
    pip uninstall -y torch torchvision torchaudio 2>/dev/null || true

    # Step 2: Remove system packages that lack RECORD files (pip can't uninstall them)
    # then reinstall compatible versions fresh
    echo "  [2/5] Fixing system packages (numpy/pandas/scipy/blinker)..."
    rm -rf /usr/local/lib/python3.11/dist-packages/{numpy,pandas,scipy}* 2>/dev/null || true
    rm -rf /usr/lib/python3/dist-packages/{numpy,pandas,scipy}* 2>/dev/null || true
    rm -rf /usr/lib/python3/dist-packages/blinker* 2>/dev/null || true
    pip install $PIP_FLAGS numpy==2.3.0 "pandas>=2.2.3,<3" "scipy>=1.12"

    # Step 3: PyTorch 2.8.0 with CUDA 12.6 (pinned to match whisperx ~=2.8.0)
    echo "  [3/5] Installing PyTorch 2.8.0 with CUDA 12.6..."
    pip install $PIP_FLAGS torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu126

    # Step 4: HuggingFace stack + web packages
    echo "  [4/5] Installing transformers/huggingface..."
    pip install $PIP_FLAGS \
        "huggingface_hub>=0.25,<1.0.0" \
        "transformers>=4.48,<4.50" \
        "tokenizers>=0.21,<0.24" \
        uvicorn fastapi python-multipart aiofiles pydantic

    # Verify torch wasn't changed by transitive dependencies
    TORCH_VER=$(python -c "import torch; print(torch.__version__)" 2>/dev/null)
    if [[ ! "$TORCH_VER" == 2.8.0* ]]; then
        echo "  WARNING: torch changed to $TORCH_VER, reinstalling 2.8.0..."
        pip install $PIP_FLAGS torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu126
    fi

    # Constraints file prevents later packages from upgrading torch
    CONSTRAINTS="/tmp/torch-constraints.txt"
    printf "torch==2.8.0\ntorchaudio==2.8.0\n" > "$CONSTRAINTS"

    # Step 5: WhisperX + speechbrain + demucs
    echo "  [5/5] Installing WhisperX, speechbrain, demucs..."
    pip install $PIP_FLAGS -c "$CONSTRAINTS" whisperx speechbrain
    pip install $PIP_FLAGS --no-deps demucs
    pip install $PIP_FLAGS -c "$CONSTRAINTS" \
        dora-search lameenc julius diffq einops openunmix treetable

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

# Load env vars from .env if present
if [ -f /workspace/dubber/.env ]; then
    set -a
    source <(grep -v '^\s*#' /workspace/dubber/.env | grep '=' | sed 's/\r//g')
    set +a
fi
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

# Start OpenVoice on port 8005 (isolated venv to avoid librosa conflicts)
echo "Starting OpenVoice on port 8005..."
cd /workspace/dubber/openvoice-service
if [ ! -d "venv" ]; then
    echo "  Creating OpenVoice venv..."
    python -m venv venv
    venv/bin/pip install --no-warn-script-location -q -r requirements.txt
fi
nohup venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8005 > /tmp/openvoice.log 2>&1 &

echo ""
echo "Waiting for services to load models (90s)..."
sleep 90

echo ""
echo "=== Service Status ==="
echo -n "Demucs (8000):    " && curl -s http://localhost:8000/health | python -c "import sys,json; d=json.load(sys.stdin); print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')" 2>/dev/null || echo "Still loading..."
echo -n "WhisperX (8002):  " && curl -s http://localhost:8002/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
echo -n "OpenVoice (8005): " && curl -s http://localhost:8005/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('status')=='healthy' else 'Error')" 2>/dev/null || echo "Still loading..."

echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader

echo ""
echo "=== READY ==="
echo "Logs:"
echo "  tail -f /tmp/demucs.log"
echo "  tail -f /tmp/whisperx.log"
echo "  tail -f /tmp/openvoice.log"
echo ""
echo "All logs: tail -f /tmp/*.log"
