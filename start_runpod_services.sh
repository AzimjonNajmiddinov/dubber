#!/bin/bash
# Start GPU services on RunPod
# Runs Demucs, WhisperX, XTTS, OpenVoice, Fish Speech, and Lipsync on GPU
#
# Usage:
#   ./start_runpod_services.sh                # All services
#   ./start_runpod_services.sh --test-fish    # Only Demucs + WhisperX + Fish Speech (saves GPU memory)
#   ./start_runpod_services.sh --skip-deps    # Skip dependency installation
#   ./start_runpod_services.sh --deps-only    # Only install dependencies, don't start services

set -e

SKIP_DEPS=false
DEPS_ONLY=false
TEST_FISH=false

for arg in "$@"; do
    case $arg in
        --skip-deps) SKIP_DEPS=true ;;
        --deps-only) DEPS_ONLY=true ;;
        --test-fish) TEST_FISH=true ;;
    esac
done

echo "=== Starting RunPod GPU Services ==="
if [ "$TEST_FISH" = true ]; then
    echo "Mode: --test-fish (Demucs + WhisperX + Fish Speech only)"
fi

# Kill any existing services
pkill -f "uvicorn.*8000" 2>/dev/null || true
pkill -f "uvicorn.*8002" 2>/dev/null || true
pkill -f "uvicorn.*8004" 2>/dev/null || true
pkill -f "uvicorn.*8005" 2>/dev/null || true
pkill -f "uvicorn.*8006" 2>/dev/null || true
pkill -f "tools.api_server" 2>/dev/null || true
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
    echo "  [1/7] Cleaning up old packages..."
    pip uninstall -y torch torchvision torchaudio 2>/dev/null || true

    # Step 2: Remove system packages that lack RECORD files (pip can't uninstall them)
    # then reinstall compatible versions fresh
    echo "  [2/7] Fixing system packages (numpy/pandas/scipy/blinker)..."
    rm -rf /usr/local/lib/python3.11/dist-packages/{numpy,pandas,scipy}* 2>/dev/null || true
    rm -rf /usr/lib/python3/dist-packages/{numpy,pandas,scipy}* 2>/dev/null || true
    rm -rf /usr/lib/python3/dist-packages/blinker* 2>/dev/null || true
    pip install $PIP_FLAGS numpy==2.3.0 "pandas>=2.2.3,<3" "scipy>=1.12"

    # Step 3: PyTorch 2.8.0 with CUDA 12.6 (pinned to match whisperx ~=2.8.0)
    echo "  [3/7] Installing PyTorch 2.8.0 with CUDA 12.6..."
    pip install $PIP_FLAGS torch==2.8.0 torchaudio==2.8.0 --index-url https://download.pytorch.org/whl/cu126

    # Step 4: HuggingFace stack + web packages
    echo "  [4/7] Installing transformers/huggingface..."
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

    # Step 5: WhisperX + speechbrain (used by whisperx-service for speaker diarization)
    echo "  [5/7] Installing WhisperX..."
    pip install $PIP_FLAGS -c "$CONSTRAINTS" whisperx speechbrain

    if [ "$TEST_FISH" = false ]; then
        # Step 6: Demucs + TTS with --no-deps to avoid conflicts
        # TTS 0.22.0 requires pandas<2 and gruut needs numpy<2, which conflict
        # with whisperx. XTTS v2 works fine with modern numpy/pandas in practice.
        echo "  [6/7] Installing Demucs & TTS..."
        pip install $PIP_FLAGS --no-deps demucs TTS==0.22.0

        # Step 7: Install demucs/TTS dependencies (non-conflicting ones only)
        echo "  [7/7] Installing demucs/TTS dependencies..."
        pip install $PIP_FLAGS -c "$CONSTRAINTS" \
            dora-search lameenc julius diffq einops openunmix treetable \
            trainer coqpit librosa soundfile pysbd pypinyin umap-learn \
            encodec inflect anyascii \
            bangla bnnumerizer bnunicodenormalizer \
            g2pkk hangul-romanize jamo jieba num2words unidecode \
            flask "spacy>=3"
    else
        echo "  [6/7] Skipping Demucs & TTS deps (--test-fish mode)"
        echo "  [7/7] Skipping demucs/TTS sub-deps (--test-fish mode)"
    fi

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
    if [ "$TEST_FISH" = false ]; then
        python -c "import demucs; print(f'  demucs: OK')"
        python -c "from TTS.tts.models.xtts import Xtts; print(f'  TTS/XTTS: OK')"
    fi
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

if [ "$TEST_FISH" = false ]; then
    # Start XTTS on port 8004
    echo "Starting XTTS on port 8004..."
    cd /workspace/dubber/xtts-service
    nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &

    # Start OpenVoice on port 8005 (isolated venv to avoid librosa conflicts)
    echo "Starting OpenVoice on port 8005..."
    cd /workspace/dubber/openvoice-service
    if [ ! -d "venv" ]; then
        echo "  Creating OpenVoice venv..."
        python -m venv venv
        venv/bin/pip install --no-warn-script-location -q -r requirements.txt
    fi
    nohup venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8005 > /tmp/openvoice.log 2>&1 &
fi

# Start Fish Speech on port 8080 (isolated venv — separate torch/deps from XTTS)
echo "Starting Fish Speech on port 8080..."
FISH_DIR="/workspace/fish-speech"
FISH_CKPT="${FISH_DIR}/checkpoints/openaudio-s1-mini"
if [ ! -d "$FISH_DIR" ]; then
    echo "  Cloning Fish Speech..."
    git clone https://github.com/fishaudio/fish-speech.git "$FISH_DIR"
fi
cd "$FISH_DIR"
if [ ! -d "venv" ]; then
    echo "  Creating Fish Speech venv and installing deps (this takes a few minutes)..."
    python -m venv venv
    venv/bin/pip install --no-warn-script-location -q -e ".[cu126]"
fi
if [ ! -d "$FISH_CKPT" ]; then
    echo "  Downloading OpenAudio S1-mini model..."
    venv/bin/huggingface-cli download fishaudio/openaudio-s1-mini --local-dir "$FISH_CKPT"
fi
nohup venv/bin/python -m tools.api_server \
    --listen "0.0.0.0:8080" \
    --llama-checkpoint-path "$FISH_CKPT" \
    --decoder-checkpoint-path "${FISH_CKPT}/codec.pth" \
    --decoder-config-name modded_dac_vq \
    --compile \
    > /tmp/fish-speech.log 2>&1 &

if [ "$TEST_FISH" = false ]; then
    # Start Lipsync on port 8006
    echo "Starting Lipsync on port 8006..."
    cd /workspace/dubber/lipsync-service
    nohup python -m uvicorn app:app --host 0.0.0.0 --port 8006 > /tmp/lipsync.log 2>&1 &
fi

echo ""
echo "Waiting for services to load models (90s)..."
echo "(Fish Speech torch.compile may take an extra 60s on first request)"
sleep 90

echo ""
echo "=== Service Status ==="
echo -n "Demucs (8000):    " && curl -s http://localhost:8000/health | python -c "import sys,json; d=json.load(sys.stdin); print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')" 2>/dev/null || echo "Still loading..."
echo -n "WhisperX (8002):  " && curl -s http://localhost:8002/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
if [ "$TEST_FISH" = false ]; then
    echo -n "XTTS (8004):      " && curl -s http://localhost:8004/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('status')=='healthy' else 'Error')" 2>/dev/null || echo "Still loading..."
    echo -n "OpenVoice (8005): " && curl -s http://localhost:8005/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('status')=='healthy' else 'Error')" 2>/dev/null || echo "Still loading..."
fi
echo -n "Fish Speech (8080): " && curl -s http://localhost:8080/v1/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('status')=='ok' else 'Error')" 2>/dev/null || echo "Still loading..."
if [ "$TEST_FISH" = false ]; then
    echo -n "Lipsync (8006):   " && curl -s http://localhost:8006/health | python -c "import sys,json; d=json.load(sys.stdin); print('OK' if d.get('ok') else 'Error')" 2>/dev/null || echo "Still loading..."
fi

echo ""
nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader

echo ""
echo "=== READY ==="
echo "Logs:"
echo "  tail -f /tmp/demucs.log"
echo "  tail -f /tmp/whisperx.log"
if [ "$TEST_FISH" = false ]; then
    echo "  tail -f /tmp/xtts.log"
    echo "  tail -f /tmp/openvoice.log"
fi
echo "  tail -f /tmp/fish-speech.log"
if [ "$TEST_FISH" = false ]; then
    echo "  tail -f /tmp/lipsync.log"
fi
echo ""
echo "All logs: tail -f /tmp/*.log"

if [ "$TEST_FISH" = true ]; then
    echo ""
    echo "=== Run Uzbek test ==="
    echo "  cd /workspace/dubber/fish-speech-service"
    echo "  pip install requests ormsgpack"
    echo "  python test_uzbek.py --server http://localhost:8080"
fi
