#!/bin/bash
# Start GPU services on RunPod
# Runs Demucs, WhisperX, and OpenVoice on GPU
#
# Usage:
#   ./start_runpod_services.sh                # All services
#   ./start_runpod_services.sh --skip-deps    # Skip dependency installation
#   ./start_runpod_services.sh --deps-only    # Only install dependencies, don't start services

# Do NOT use set -e — individual failures are handled inline
set -o pipefail

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
echo "Pulling latest code..."
OLD_HEAD=$(git rev-parse HEAD)
if ! git pull --ff-only 2>&1; then
    echo "  Fast-forward pull failed, resetting to origin/main..."
    git fetch origin
    git reset --hard origin/main
fi
echo "  HEAD: $(git log --oneline -1)"

# If the script itself was updated, re-exec so we run the new version
NEW_HEAD=$(git rev-parse HEAD)
if [ "$OLD_HEAD" != "$NEW_HEAD" ] && [ "${_RESTARTED:-false}" = "false" ]; then
    echo "  Script updated — re-executing new version..."
    export _RESTARTED=true
    exec bash /workspace/dubber/start_runpod_services.sh "$@"
fi

# ===========================================
# INSTALL/FIX DEPENDENCIES
# ===========================================
if [ "$SKIP_DEPS" = false ]; then
    echo ""
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
    pip install $PIP_FLAGS "numpy>=2.0.0,<2.3.0" "pandas>=2.2.3,<3" "scipy>=1.12"

    # Step 3: PyTorch 2.8.0 with CUDA 12.6 (pinned to match whisperx ~=2.8.0)
    echo "  [3/5] Installing PyTorch 2.8.0 with CUDA 12.6..."
    pip install $PIP_FLAGS torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu126

    # Step 4: HuggingFace stack + web packages
    echo "  [4/5] Installing transformers/huggingface..."
    pip install $PIP_FLAGS \
        "huggingface_hub>=0.25,<1.0.0" \
        "transformers>=4.48,<4.50" \
        "tokenizers>=0.21,<0.24" \
        uvicorn fastapi python-multipart aiofiles pydantic soundfile librosa

    # Verify torch wasn't changed by transitive dependencies
    TORCH_VER=$(python -c "import torch; print(torch.__version__)" 2>/dev/null || echo "MISSING")
    if [[ ! "$TORCH_VER" == 2.8.0* ]]; then
        echo "  WARNING: torch is $TORCH_VER, reinstalling 2.8.0..."
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

    echo ""
    echo "Dependencies installed! Verifying..."

    # Verify key packages (non-fatal)
    VERIFY_OK=true
    for pkg in torch numpy pandas scipy transformers whisperx demucs soundfile; do
        if python -c "import $pkg" 2>/dev/null; then
            VER=$(python -c "import $pkg; print(getattr($pkg, '__version__', 'OK'))" 2>/dev/null)
            echo "  $pkg: $VER"
        else
            echo "  $pkg: MISSING!"
            VERIFY_OK=false
        fi
    done
    if [ "$VERIFY_OK" = false ]; then
        echo "WARNING: Some packages are missing. Services may fail to start."
    fi
    echo ""
else
    echo "Skipping dependency check (--skip-deps)"
fi

# ===========================================
# SHARED TTS VENV (torch cu126 + f5-tts)
# Shared by F5-TTS service — one venv, one torch install
# Location: /workspace/tts-venv
# To rebuild: rm -rf /workspace/tts-venv && ./start_runpod_services.sh
# ===========================================
TTS_VENV=/workspace/tts-venv
echo "Checking shared TTS venv ($TTS_VENV)..."

TTS_VENV_OK=false
if [ -d "$TTS_VENV" ] && $TTS_VENV/bin/python -c "import transformers; import uvicorn" 2>/dev/null; then
    echo "  TTS venv OK"
    TTS_VENV_OK=true
fi

if [ "$TTS_VENV_OK" = false ]; then
    echo "  Creating TTS venv (torch cu126 + MMS deps, ~3GB, takes 5-10 min)..."
    rm -rf "$TTS_VENV"
    python -m venv "$TTS_VENV"

    $TTS_VENV/bin/pip install -q --upgrade pip

    echo "    Installing torch (cu126, matches system)..."
    $TTS_VENV/bin/pip install -q \
        torch torchaudio \
        --index-url https://download.pytorch.org/whl/cu126

    echo "    Installing MMS+OpenVoice deps..."
    $TTS_VENV/bin/pip install -q \
        transformers uvicorn fastapi python-multipart soundfile scipy \
        "av" --prefer-binary

    if $TTS_VENV/bin/python -c "import transformers; import torch; print(f'TTS venv OK - torch {torch.__version__}')" 2>/dev/null; then
        echo "  TTS venv created successfully (run mms-openvoice-service/setup.sh for full MMS install)"
    else
        echo "  ERROR: TTS venv creation failed! Check manually."
    fi
fi


# Exit early if only installing dependencies
if [ "$DEPS_ONLY" = true ]; then
    echo ""
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
    echo ""
    echo "WARNING: HF_TOKEN not set. WhisperX will fail!"
    echo "Set it with: export HF_TOKEN='your_token'"
fi

# ===========================================
# START SERVICES
# ===========================================
echo ""
echo "Starting services..."

# Start Demucs on port 8000 (system Python — demucs installed there)
echo "  Starting Demucs on port 8000..."
cd /workspace/dubber/demucs-service
nohup python -m uvicorn app_runpod:app --host 0.0.0.0 --port 8000 > /tmp/demucs.log 2>&1 &

# Start MMS+OpenVoice on port 8005 (tts-venv — MMS deps installed there)
echo "  Starting MMS+OpenVoice on port 8005..."
cd /workspace/dubber/mms-openvoice-service
nohup $TTS_VENV/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8005 > /tmp/mms.log 2>&1 &

# Start WhisperX on port 8002 (system Python — whisperx installed there)
echo "  Starting WhisperX on port 8002..."
cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &


echo ""
echo "Waiting for services to load models..."

# Poll for health instead of blind sleep
check_health() {
    local name=$1 port=$2 check=$3
    for i in $(seq 1 30); do
        if curl -s --max-time 2 "http://localhost:${port}/health" | python -c "$check" 2>/dev/null; then
            return 0
        fi
        sleep 3
    done
    return 1
}

echo -n "  Demucs (8000):    "
if check_health "Demucs" 8000 "import sys,json; d=json.load(sys.stdin); assert d"; then
    curl -s http://localhost:8000/health | python -c "import sys,json; d=json.load(sys.stdin); print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')" 2>/dev/null
else
    echo "FAILED (check: tail /tmp/demucs.log)"
fi

echo -n "  MMS+OV  (8005):   "
if check_health "MMS" 8005 "import sys,json; d=json.load(sys.stdin); assert d.get('status')=='healthy'"; then
    echo "OK"
else
    echo "FAILED (check: tail /tmp/mms.log)"
fi

echo -n "  WhisperX (8002):  "
if check_health "WhisperX" 8002 "import sys,json; d=json.load(sys.stdin); assert d.get('ok')"; then
    echo "OK"
    # Trigger /ready in background to preload gender/emotion models (~3-5 min first run)
    echo "  WhisperX /ready: preloading gender/emotion models in background..."
    curl -s --max-time 600 "http://localhost:8002/ready" >> /tmp/whisperx.log 2>&1 &
else
    echo "FAILED (check: tail /tmp/whisperx.log)"
fi


echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader 2>/dev/null || echo "nvidia-smi not available"

echo ""
echo "=== READY ==="
echo "Logs:"
echo "  tail -f /tmp/demucs.log"
echo "  tail -f /tmp/mms.log"
echo "  tail -f /tmp/whisperx.log"
echo ""
echo "All logs: tail -f /tmp/*.log"
