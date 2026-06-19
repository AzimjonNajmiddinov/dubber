#!/bin/bash
# ===========================================
# Dubber - Deploy Latest Version (cPanel)
# ===========================================
# Usage (run via SSH on cPanel server):
#   cd ~/dubbing.uz && bash deploy.sh
#
# First time? Run setup first:
#   bash deploy/cpanel-setup.sh
# ===========================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"

export PATH="$HOME/bin:$HOME/.local/bin:$PATH"

echo -e "${CYAN}=========================================${NC}"
echo -e "${CYAN} Dubber - Deploying Latest Version${NC}"
echo -e "${CYAN} $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "${CYAN}=========================================${NC}"
echo ""

# ------------------------------------------
# 1. Maintenance mode ON
# ------------------------------------------
echo -e "${YELLOW}[1/6] Enabling maintenance mode...${NC}"
php artisan down --refresh=30 --retry=60 2>/dev/null || true
echo -e "  Maintenance mode ${GREEN}ON${NC}"

# ------------------------------------------
# 2. Pull latest code (includes public/build)
# ------------------------------------------
echo -e "${YELLOW}[2/6] Pulling latest code from git...${NC}"

# Backup .env before git reset
cp .env /tmp/.env.bak 2>/dev/null || true

git fetch origin
git reset --hard origin/main

# Restore .env
if [ -f /tmp/.env.bak ]; then
    cp /tmp/.env.bak .env
    rm -f /tmp/.env.bak
fi

echo -e "  Latest code pulled ${GREEN}OK${NC}"

# Check .env exists
if [ ! -f .env ]; then
    echo -e "${RED}ERROR: .env file not found! Create it manually first.${NC}"
    php artisan up 2>/dev/null || true
    exit 1
fi

# ------------------------------------------
# 4. Install PHP dependencies
# ------------------------------------------
echo -e "${YELLOW}[3/6] Installing PHP dependencies...${NC}"
COMPOSER_CMD=$(which composer 2>/dev/null || echo "$HOME/bin/composer")
php -d allow_url_fopen=1 "$COMPOSER_CMD" install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --ignore-platform-reqs 2>&1 | tail -5
echo -e "  Composer install ${GREEN}OK${NC}"

# ------------------------------------------
# 5. Database migrations
# ------------------------------------------
echo -e "${YELLOW}[4/6] Running database migrations...${NC}"
php artisan migrate --force
echo -e "  Migrations ${GREEN}OK${NC}"

# ------------------------------------------
# 6. Clear & rebuild caches
# ------------------------------------------
echo -e "${YELLOW}[5/6] Rebuilding caches...${NC}"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear 2>/dev/null || true

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link --force 2>/dev/null || true
echo -e "  Caches rebuilt ${GREEN}OK${NC}"

# ------------------------------------------
# 7. Restart queue workers
# ------------------------------------------
echo -e "${YELLOW}[6/6] Restarting queue workers...${NC}"

# Signal existing workers to stop gracefully after current job
php artisan queue:restart 2>/dev/null || true

# Kill any lingering queue worker processes
PID_FILE="$APP_DIR/storage/framework/queue.pid"
AUDIO_PID_FILE="$APP_DIR/storage/framework/audio-downloads-queue.pid"
BG_PID_FILE="$APP_DIR/storage/framework/bg-mix-queue.pid"
SEGMENT_WORKERS="${SEGMENT_WORKERS:-3}"
for WORKER_PID_FILE in "$PID_FILE" "$AUDIO_PID_FILE" "$BG_PID_FILE"; do
    if [ -f "$WORKER_PID_FILE" ]; then
        OLD_PID=$(cat "$WORKER_PID_FILE")
        if kill -0 "$OLD_PID" 2>/dev/null; then
            kill "$OLD_PID" 2>/dev/null || true
            sleep 2
            kill -9 "$OLD_PID" 2>/dev/null || true
        fi
        rm -f "$WORKER_PID_FILE"
    fi
done
for i in $(seq 1 "$SEGMENT_WORKERS"); do
    WORKER_PID_FILE="$APP_DIR/storage/framework/segment-generation-queue-$i.pid"
    if [ -f "$WORKER_PID_FILE" ]; then
        OLD_PID=$(cat "$WORKER_PID_FILE")
        if kill -0 "$OLD_PID" 2>/dev/null; then
            kill "$OLD_PID" 2>/dev/null || true
            sleep 2
            kill -9 "$OLD_PID" 2>/dev/null || true
        fi
        rm -f "$WORKER_PID_FILE"
    fi
done

# Also kill by process name
pkill -f "queue:work" 2>/dev/null || true
sleep 1

# Start fresh queue workers
LOG_DIR="$HOME/logs"
LOG_FILE="$LOG_DIR/queue.log"
SEGMENT_LOG_FILE="$LOG_DIR/segment-generation.log"
AUDIO_LOG_FILE="$LOG_DIR/audio-downloads.log"
BG_LOG_FILE="$LOG_DIR/bg-mix.log"
mkdir -p "$LOG_DIR"

echo "[$(date)] Deploy restart — starting main queue worker..." >> "$LOG_FILE"

nohup php -d memory_limit=512M artisan queue:work \
    --queue=chunks,segment-processing,default \
    --sleep=3 \
    --tries=3 \
    --timeout=3600 \
    --max-jobs=500 \
    --max-time=3600 \
    >> "$LOG_FILE" 2>&1 &

echo $! > "$PID_FILE"
echo -e "  Main queue worker started (PID: $!) ${GREEN}OK${NC}"

echo "[$(date)] Deploy restart — starting segment-generation workers..." >> "$SEGMENT_LOG_FILE"

for i in $(seq 1 "$SEGMENT_WORKERS"); do
    SEGMENT_PID_FILE="$APP_DIR/storage/framework/segment-generation-queue-$i.pid"
    nohup php -d memory_limit=768M artisan queue:work \
        --queue=segment-generation \
        --sleep=1 \
        --tries=3 \
        --timeout=3600 \
        --max-jobs=300 \
        --max-time=3600 \
        >> "$SEGMENT_LOG_FILE" 2>&1 &

    echo $! > "$SEGMENT_PID_FILE"
    echo -e "  Segment generation worker #$i started (PID: $!) ${GREEN}OK${NC}"
done

echo "[$(date)] Deploy restart — starting audio-downloads queue worker..." >> "$AUDIO_LOG_FILE"

nohup php -d memory_limit=512M artisan queue:work \
    --queue=audio-downloads \
    --sleep=1 \
    --tries=3 \
    --timeout=7200 \
    --max-jobs=500 \
    --max-time=7200 \
    >> "$AUDIO_LOG_FILE" 2>&1 &

echo $! > "$AUDIO_PID_FILE"
echo -e "  Audio downloads queue worker started (PID: $!) ${GREEN}OK${NC}"

echo "[$(date)] Deploy restart — starting bg-mix queue worker..." >> "$BG_LOG_FILE"

nohup php -d memory_limit=512M artisan queue:work \
    --queue=bg-mix \
    --sleep=1 \
    --tries=3 \
    --timeout=3600 \
    --max-jobs=500 \
    --max-time=3600 \
    >> "$BG_LOG_FILE" 2>&1 &

echo $! > "$BG_PID_FILE"
echo -e "  BG mix queue worker started (PID: $!) ${GREEN}OK${NC}"

# ------------------------------------------
# Maintenance mode OFF & Done
# ------------------------------------------
php artisan up
echo ""
echo -e "${GREEN}=========================================${NC}"
echo -e "${GREEN} Deploy Complete! Site is LIVE${NC}"
echo -e "${GREEN}=========================================${NC}"
echo ""
echo -e "  Site:       ${CYAN}https://dubbing.uz${NC}"
echo -e "  Queue log:  ${CYAN}$LOG_FILE${NC}"
echo -e "  Segment log:${CYAN}$SEGMENT_LOG_FILE${NC}"
echo -e "  Audio log:  ${CYAN}$AUDIO_LOG_FILE${NC}"
echo -e "  BG log:     ${CYAN}$BG_LOG_FILE${NC}"
echo -e "  Queue PID:  ${CYAN}$(cat "$PID_FILE" 2>/dev/null)${NC}"
echo -e "  Segment PIDs:${CYAN} $(for i in $(seq 1 "$SEGMENT_WORKERS"); do cat "$APP_DIR/storage/framework/segment-generation-queue-$i.pid" 2>/dev/null; printf ' '; done)${NC}"
echo -e "  Audio PID:  ${CYAN}$(cat "$AUDIO_PID_FILE" 2>/dev/null)${NC}"
echo -e "  BG PID:     ${CYAN}$(cat "$BG_PID_FILE" 2>/dev/null)${NC}"
echo ""
echo -e "${YELLOW}Useful commands:${NC}"
echo "  tail -f $LOG_FILE            # Watch queue log"
echo "  tail -f $SEGMENT_LOG_FILE    # Watch translation/TTS log"
echo "  tail -f $AUDIO_LOG_FILE      # Watch audio download log"
echo "  tail -f $BG_LOG_FILE         # Watch bg mix log"
echo "  php artisan queue:failed     # View failed jobs"
echo "  php artisan queue:retry all  # Retry failed jobs"
echo ""
