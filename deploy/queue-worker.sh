#!/bin/bash
# ===========================================
# Dubber - Queue Worker Watchdog
# ===========================================
# Add to crontab:
#   */5 * * * * /path/to/dubber/deploy/queue-worker.sh
# ===========================================

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$HOME/logs"
LOG_FILE="$LOG_DIR/queue.log"
PID_FILE="$APP_DIR/storage/framework/queue.pid"

mkdir -p "$LOG_DIR"

export PATH="$HOME/bin:$HOME/.local/bin:$PATH"

# Check if worker is already running
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        # Worker is running
        exit 0
    else
        # Stale PID file
        rm -f "$PID_FILE"
    fi
fi

# Also check by process name to avoid duplicates
if pgrep -f "queue:work database.*queue=chunks" >/dev/null 2>&1; then
    exit 0
fi

# Start queue worker via nohup
echo "[$(date)] Starting queue worker..." >> "$LOG_FILE"

cd "$APP_DIR"
nohup php artisan queue:work database \
    --queue=chunks,segment-processing,segment-generation,default \
    --sleep=3 \
    --tries=3 \
    --timeout=3600 \
    --max-jobs=100 \
    --max-time=3600 \
    >> "$LOG_FILE" 2>&1 &

echo $! > "$PID_FILE"
echo "[$(date)] Queue worker started with PID $!" >> "$LOG_FILE"
