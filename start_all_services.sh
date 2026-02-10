#!/bin/bash
# =============================================================================
# RunPod Full Services Startup Script
# Starts: Demucs (8000), WhisperX (8002), XTTS (8004), Lipsync (8006)
# =============================================================================

set -e

echo "=== RunPod Full Services Setup ==="
echo "Started at: $(date)"

# -----------------------------------------------------------------------------
# 1. Install Python packages (run once, skip if already installed)
# -----------------------------------------------------------------------------
install_packages() {
    echo ""
    echo "[1/6] Installing Python packages..."

    # GPU PyTorch
    pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121

    # cuDNN for faster-whisper CTranslate2
    pip install nvidia-cudnn-cu11==8.9.6.50

    # Fix blinker conflict
    pip install --ignore-installed blinker

    # Core ML packages
    pip install TTS faster-whisper whisperx demucs

    # API packages
    pip install fastapi "uvicorn[standard]" python-multipart pydantic

    # WhisperX dependencies
    pip install --upgrade pyannote.audio huggingface_hub transformers speechbrain

    # Lipsync/Wav2Lip dependencies
    pip install numpy==1.26.4 scipy==1.11.4 numba==0.59.1 llvmlite==0.42.0
    pip install librosa==0.10.2 opencv-python-headless matplotlib pillow scikit-image
    pip install face-alignment==1.3.5

    echo "[1/6] Package installation complete"
}

# -----------------------------------------------------------------------------
# 2. Setup Wav2Lip for Lipsync (run once)
# -----------------------------------------------------------------------------
setup_wav2lip() {
    echo ""
    echo "[2/6] Setting up Wav2Lip..."

    if [ ! -d "/app/Wav2Lip" ]; then
        echo "Cloning Wav2Lip..."
        mkdir -p /app
        git clone --depth 1 https://github.com/Rudrabha/Wav2Lip.git /app/Wav2Lip

        # Patch for librosa 0.10+ compatibility
        echo "Patching audio.py for librosa 0.10+..."
        sed -i 's/librosa\.filters\.mel(hp\.sample_rate, hp\.n_fft,/librosa.filters.mel(sr=hp.sample_rate, n_fft=hp.n_fft,/g' /app/Wav2Lip/audio.py
        sed -i 's/librosa\.filters\.mel(hparams\.sample_rate, hparams\.n_fft,/librosa.filters.mel(sr=hparams.sample_rate, n_fft=hparams.n_fft,/g' /app/Wav2Lip/audio.py

        # Download S3FD face detector weights
        echo "Downloading face detector weights..."
        mkdir -p /app/Wav2Lip/face_detection/detection/sfd
        wget -q -O /app/Wav2Lip/face_detection/detection/sfd/s3fd.pth \
            https://www.adrianbulat.com/downloads/python-fan/s3fd-619a316812.pth

        # Download Wav2Lip checkpoint
        echo "Downloading Wav2Lip checkpoint..."
        mkdir -p /app/Wav2Lip/checkpoints
        wget -q -O /app/Wav2Lip/checkpoints/wav2lip.pth \
            https://huggingface.co/numz/wav2lip_studio/resolve/main/Wav2lip/wav2lip.pth

        echo "Wav2Lip setup complete"
    else
        echo "Wav2Lip already installed, skipping..."
    fi
}

# -----------------------------------------------------------------------------
# 3. Kill existing services
# -----------------------------------------------------------------------------
kill_services() {
    echo ""
    echo "[3/6] Stopping existing services..."

    pkill -f "uvicorn.*8000" 2>/dev/null || true
    pkill -f "uvicorn.*8002" 2>/dev/null || true
    pkill -f "uvicorn.*8004" 2>/dev/null || true
    pkill -f "uvicorn.*8006" 2>/dev/null || true
    sleep 2

    echo "Services stopped"
}

# -----------------------------------------------------------------------------
# 4. Pull latest code
# -----------------------------------------------------------------------------
pull_code() {
    echo ""
    echo "[4/6] Pulling latest code..."

    cd /workspace/dubber
    git fetch origin
    git reset --hard origin/main

    echo "Code updated"
}

# -----------------------------------------------------------------------------
# 5. Setup environment
# -----------------------------------------------------------------------------
setup_env() {
    echo ""
    echo "[5/6] Setting up environment..."

    # cuDNN library path for faster-whisper
    CUDNN_PATH=$(python -c "import nvidia.cudnn; print(nvidia.cudnn.__path__[0] + '/lib')" 2>/dev/null || true)
    if [ -n "$CUDNN_PATH" ]; then
        export LD_LIBRARY_PATH="${CUDNN_PATH}:${LD_LIBRARY_PATH}"
        echo "cuDNN path: $CUDNN_PATH"
    fi

    # HuggingFace token for WhisperX diarization
    export HF_TOKEN="${HF_TOKEN:-}"

    # Storage path for services
    export STORAGE_PATH="/workspace/dubber/storage/app"
    export VOICES_PATH="/workspace/dubber/storage/app/voices"
    export CACHE_PATH="/workspace/dubber/storage/app/cache"

    # Create directories
    mkdir -p "$STORAGE_PATH" "$VOICES_PATH" "$CACHE_PATH"
    mkdir -p /tmp/demucs_cache

    echo "Environment configured"
}

# -----------------------------------------------------------------------------
# 6. Start all services
# -----------------------------------------------------------------------------
start_services() {
    echo ""
    echo "[6/6] Starting services..."

    cd /workspace/dubber

    # Demucs on port 8000
    echo "Starting Demucs on port 8000..."
    cd /workspace/dubber/demucs-service
    nohup python -m uvicorn app_runpod:app --host 0.0.0.0 --port 8000 > /tmp/demucs.log 2>&1 &

    # WhisperX on port 8002
    echo "Starting WhisperX on port 8002..."
    cd /workspace/dubber/whisperx-service
    nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &

    # XTTS on port 8004
    echo "Starting XTTS on port 8004..."
    cd /workspace/dubber/xtts-service
    nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &

    # Lipsync on port 8006
    echo "Starting Lipsync on port 8006..."
    cd /workspace/dubber/lipsync-service
    nohup python -m uvicorn app:app --host 0.0.0.0 --port 8006 > /tmp/lipsync.log 2>&1 &

    echo "All services started"
}

# -----------------------------------------------------------------------------
# 7. Wait and check health
# -----------------------------------------------------------------------------
check_health() {
    echo ""
    echo "Waiting for services to load models (60s)..."
    sleep 60

    echo ""
    echo "=== Service Status ==="

    echo -n "Demucs (8000):   "
    curl -s http://localhost:8000/health 2>/dev/null | python -c "
import sys,json
try:
    d=json.load(sys.stdin)
    print(f'OK - GPU: {d.get(\"gpu_name\", \"N/A\")}')
except:
    print('Still loading...')
" || echo "Still loading..."

    echo -n "WhisperX (8002): "
    curl -s http://localhost:8002/health 2>/dev/null | python -c "
import sys,json
try:
    d=json.load(sys.stdin)
    print('OK' if d.get('ok') else 'Error')
except:
    print('Still loading...')
" || echo "Still loading..."

    echo -n "XTTS (8004):     "
    curl -s http://localhost:8004/health 2>/dev/null | python -c "
import sys,json
try:
    d=json.load(sys.stdin)
    print(f'OK - Cached voices: {d.get(\"cached_voices\", 0)}')
except:
    print('Still loading...')
" || echo "Still loading..."

    echo -n "Lipsync (8006):  "
    curl -s http://localhost:8006/health 2>/dev/null | python -c "
import sys,json
try:
    d=json.load(sys.stdin)
    print('OK' if d.get('ok') else 'Error')
except:
    print('Still loading...')
" || echo "Still loading..."

    echo ""
    echo "=== GPU Status ==="
    nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader 2>/dev/null || echo "GPU info unavailable"
}

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
main() {
    # Parse arguments
    INSTALL_PACKAGES=false
    SETUP_WAV2LIP=false

    while [[ $# -gt 0 ]]; do
        case $1 in
            --install)
                INSTALL_PACKAGES=true
                shift
                ;;
            --setup-wav2lip)
                SETUP_WAV2LIP=true
                shift
                ;;
            --full)
                INSTALL_PACKAGES=true
                SETUP_WAV2LIP=true
                shift
                ;;
            *)
                shift
                ;;
        esac
    done

    # Run installation if requested
    if [ "$INSTALL_PACKAGES" = true ]; then
        install_packages
    fi

    if [ "$SETUP_WAV2LIP" = true ]; then
        setup_wav2lip
    fi

    # Always run these
    kill_services
    pull_code
    setup_env
    start_services
    check_health

    echo ""
    echo "=========================================="
    echo "  ALL SERVICES READY!"
    echo "=========================================="
    echo ""
    echo "Ports:"
    echo "  Demucs:   8000"
    echo "  WhisperX: 8002"
    echo "  XTTS:     8004"
    echo "  Lipsync:  8006"
    echo ""
    echo "Logs:"
    echo "  tail -f /tmp/demucs.log"
    echo "  tail -f /tmp/whisperx.log"
    echo "  tail -f /tmp/xtts.log"
    echo "  tail -f /tmp/lipsync.log"
    echo ""
    echo "All logs: tail -f /tmp/*.log"
    echo ""
}

# Run
main "$@"
