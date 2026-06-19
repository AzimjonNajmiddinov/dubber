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
SEGMENT_LOG_FILE="$LOG_DIR/segment-generation.log"
AUDIO_LOG_FILE="$LOG_DIR/audio-downloads.log"
AUDIO_PID_FILE="$APP_DIR/storage/framework/audio-downloads-queue.pid"
BG_LOG_FILE="$LOG_DIR/bg-mix.log"
BG_PID_FILE="$APP_DIR/storage/framework/bg-mix-queue.pid"
SEGMENT_WORKERS="${SEGMENT_WORKERS:-3}"

mkdir -p "$LOG_DIR"

export PATH="$HOME/bin:$HOME/.local/bin:$PATH"

is_worker_running() {
    local PID_PATH="$1"
    local QUEUES="$2"

    if [ -f "$PID_PATH" ]; then
        PID=$(cat "$PID_PATH")
        if kill -0 "$PID" 2>/dev/null; then
            return 0
        fi
        rm -f "$PID_PATH"
    fi

    pgrep -f "queue:work.*--queue=${QUEUES}" >/dev/null 2>&1
}

cd "$APP_DIR"

if ! is_worker_running "$PID_FILE" "chunks,segment-processing,default"; then
    echo "[$(date)] Starting main queue worker..." >> "$LOG_FILE"
    nohup php artisan queue:work \
        --queue=chunks,segment-processing,default \
        --sleep=3 \
        --tries=3 \
        --timeout=3600 \
        --max-jobs=100 \
        --max-time=3600 \
        >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    echo "[$(date)] Main queue worker started with PID $!" >> "$LOG_FILE"
fi

RUNNING_SEGMENT_WORKERS=$(pgrep -fc "queue:work.*--queue=segment-generation" 2>/dev/null || true)
while [ "$RUNNING_SEGMENT_WORKERS" -lt "$SEGMENT_WORKERS" ]; do
    WORKER_INDEX=$((RUNNING_SEGMENT_WORKERS + 1))
    SEGMENT_PID_FILE="$APP_DIR/storage/framework/segment-generation-queue-$WORKER_INDEX.pid"
    echo "[$(date)] Starting segment-generation queue worker #$WORKER_INDEX..." >> "$SEGMENT_LOG_FILE"
    nohup php artisan queue:work \
        --queue=segment-generation \
        --sleep=1 \
        --tries=3 \
        --timeout=3600 \
        --max-jobs=100 \
        --max-time=3600 \
        >> "$SEGMENT_LOG_FILE" 2>&1 &
    echo $! > "$SEGMENT_PID_FILE"
    echo "[$(date)] Segment-generation queue worker #$WORKER_INDEX started with PID $!" >> "$SEGMENT_LOG_FILE"
    RUNNING_SEGMENT_WORKERS=$((RUNNING_SEGMENT_WORKERS + 1))
done

if ! is_worker_running "$AUDIO_PID_FILE" "audio-downloads"; then
    echo "[$(date)] Starting audio-downloads queue worker..." >> "$AUDIO_LOG_FILE"
    nohup php artisan queue:work \
        --queue=audio-downloads \
        --sleep=1 \
        --tries=3 \
        --timeout=7200 \
        --max-jobs=100 \
        --max-time=7200 \
        >> "$AUDIO_LOG_FILE" 2>&1 &
    echo $! > "$AUDIO_PID_FILE"
    echo "[$(date)] Audio-downloads queue worker started with PID $!" >> "$AUDIO_LOG_FILE"
fi

if ! is_worker_running "$BG_PID_FILE" "bg-mix"; then
    echo "[$(date)] Starting bg-mix queue worker..." >> "$BG_LOG_FILE"
    nohup php artisan queue:work \
        --queue=bg-mix \
        --sleep=1 \
        --tries=3 \
        --timeout=3600 \
        --max-jobs=100 \
        --max-time=3600 \
        >> "$BG_LOG_FILE" 2>&1 &
    echo $! > "$BG_PID_FILE"
    echo "[$(date)] BG-mix queue worker started with PID $!" >> "$BG_LOG_FILE"
fi

exit 0
