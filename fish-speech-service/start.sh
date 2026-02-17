#!/bin/bash
# Setup and start Fish Speech on RunPod for Uzbek TTS testing
#
# Usage:
#   ./start.sh              # Full install + start
#   ./start.sh --skip-deps  # Start only (deps already installed)

set -e

SKIP_DEPS=false
for arg in "$@"; do
    case $arg in
        --skip-deps) SKIP_DEPS=true ;;
    esac
done

FISH_DIR="/workspace/fish-speech"
CHECKPOINT_DIR="${FISH_DIR}/checkpoints/openaudio-s1-mini"
PORT=8080

echo "=== Fish Speech Setup ==="

# Kill any existing Fish Speech server
pkill -f "tools.api_server" 2>/dev/null || true
sleep 2

if [ "$SKIP_DEPS" = false ]; then
    # Step 1: Clone repo
    if [ ! -d "$FISH_DIR" ]; then
        echo "[1/3] Cloning Fish Speech..."
        git clone https://github.com/fishaudio/fish-speech.git "$FISH_DIR"
    else
        echo "[1/3] Repo exists, pulling latest..."
        cd "$FISH_DIR" && git pull --ff-only 2>/dev/null || true
    fi

    # Step 2: Install in isolated venv
    cd "$FISH_DIR"
    if [ ! -d "venv" ]; then
        echo "[2/3] Creating venv and installing dependencies..."
        python -m venv venv
        source venv/bin/activate
        pip install --no-warn-script-location -q -e ".[cu126]"
    else
        echo "[2/3] Venv exists, activating..."
        source venv/bin/activate
    fi

    # Step 3: Download model
    if [ ! -d "$CHECKPOINT_DIR" ]; then
        echo "[3/3] Downloading OpenAudio S1-mini model..."
        huggingface-cli download fishaudio/openaudio-s1-mini --local-dir "$CHECKPOINT_DIR"
    else
        echo "[3/3] Model already downloaded."
    fi
else
    echo "Skipping dependency install (--skip-deps)"
    cd "$FISH_DIR"
    source venv/bin/activate
fi

echo ""
echo "Starting Fish Speech API server on port ${PORT}..."
echo "Docs will be at: http://localhost:${PORT}/docs"
echo ""

# Start server with compile optimization for speed
nohup python -m tools.api_server \
    --listen "0.0.0.0:${PORT}" \
    --llama-checkpoint-path "$CHECKPOINT_DIR" \
    --decoder-checkpoint-path "${CHECKPOINT_DIR}/codec.pth" \
    --decoder-config-name modded_dac_vq \
    --compile \
    > /tmp/fish-speech.log 2>&1 &

echo "Server starting in background. Log: /tmp/fish-speech.log"
echo ""
echo "Waiting for model to load (this may take 60-120s on first start due to torch.compile)..."

# Wait for health check
for i in $(seq 1 60); do
    if curl -s "http://localhost:${PORT}/v1/health" | python -c "import sys,json; d=json.load(sys.stdin); print('OK') if d.get('status')=='ok' else sys.exit(1)" 2>/dev/null; then
        echo ""
        echo "=== Fish Speech READY on port ${PORT} ==="
        echo "Health: http://localhost:${PORT}/v1/health"
        echo "Docs:   http://localhost:${PORT}/docs"
        echo "Log:    tail -f /tmp/fish-speech.log"
        exit 0
    fi
    sleep 3
    echo -n "."
done

echo ""
echo "WARNING: Server not responding after 3 minutes. Check logs:"
echo "  tail -f /tmp/fish-speech.log"
