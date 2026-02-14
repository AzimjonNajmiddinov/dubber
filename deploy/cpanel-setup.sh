#!/bin/bash
# ===========================================
# Dubber - cPanel Shared Hosting Setup Script
# ===========================================
# Run via SSH on your cPanel hosting:
#   cd ~/public_html   (or wherever your site root is)
#   bash deploy/cpanel-setup.sh
# ===========================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BIN_DIR="$HOME/bin"

echo -e "${GREEN}=========================================${NC}"
echo -e "${GREEN} Dubber - cPanel Deployment Setup${NC}"
echo -e "${GREEN}=========================================${NC}"
echo ""
echo "App directory: $APP_DIR"
echo ""

# ------------------------------------------
# 1. Check PHP version and extensions
# ------------------------------------------
echo -e "${YELLOW}[1/9] Checking PHP version and extensions...${NC}"

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")

if [ "$PHP_MAJOR" -lt 8 ] || ([ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -lt 2 ]); then
    echo -e "${RED}ERROR: PHP 8.2+ required. Found PHP $PHP_VERSION${NC}"
    echo "Contact your hosting provider or select PHP 8.2+ in cPanel > MultiPHP Manager"
    exit 1
fi
echo -e "  PHP $PHP_VERSION ${GREEN}OK${NC}"

REQUIRED_EXTENSIONS=(pdo_mysql mbstring openssl tokenizer xml ctype json bcmath fileinfo)
MISSING=()
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        MISSING+=("$ext")
    fi
done

if [ ${#MISSING[@]} -gt 0 ]; then
    echo -e "${RED}ERROR: Missing PHP extensions: ${MISSING[*]}${NC}"
    echo "Enable them in cPanel > Select PHP Version"
    exit 1
fi
echo -e "  Extensions ${GREEN}OK${NC}"

# ------------------------------------------
# 2. Install Composer (if not present)
# ------------------------------------------
echo -e "${YELLOW}[2/9] Checking Composer...${NC}"

if command -v composer &>/dev/null; then
    echo -e "  Composer found: $(composer --version 2>/dev/null | head -1) ${GREEN}OK${NC}"
elif [ -f "$HOME/bin/composer" ]; then
    echo -e "  Composer found at ~/bin/composer ${GREEN}OK${NC}"
else
    echo "  Installing Composer to ~/bin/..."
    mkdir -p "$BIN_DIR"
    curl -sS https://getcomposer.org/installer | php -d allow_url_fopen=1 -- --install-dir="$BIN_DIR" --filename=composer
    echo -e "  Composer installed ${GREEN}OK${NC}"
fi

# Ensure ~/bin is in PATH
export PATH="$BIN_DIR:$HOME/.local/bin:$PATH"

# ------------------------------------------
# 3. Install PHP dependencies
# ------------------------------------------
echo -e "${YELLOW}[3/9] Installing PHP dependencies...${NC}"

cd "$APP_DIR"
php -d allow_url_fopen=1 $(which composer 2>/dev/null || echo "$BIN_DIR/composer") install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs 2>&1 | tail -5
echo -e "  Composer install ${GREEN}OK${NC}"

# ------------------------------------------
# 4. Install FFmpeg (static binary)
# ------------------------------------------
echo -e "${YELLOW}[4/9] Checking FFmpeg...${NC}"

if command -v ffmpeg &>/dev/null; then
    echo -e "  FFmpeg found: $(ffmpeg -version 2>/dev/null | head -1) ${GREEN}OK${NC}"
elif [ -f "$BIN_DIR/ffmpeg" ]; then
    echo -e "  FFmpeg found at ~/bin/ffmpeg ${GREEN}OK${NC}"
else
    echo "  Downloading static FFmpeg binary..."
    mkdir -p "$BIN_DIR"

    cd /tmp
    curl -L -o ffmpeg.tar.gz "https://www.johnvansickle.com/ffmpeg/old-releases/ffmpeg-6.0.1-amd64-static.tar.xz" 2>/dev/null \
        && tar xf ffmpeg.tar.gz 2>/dev/null

    # If xz failed, try downloading a pre-built gzip version
    if [ ! -d ffmpeg-*-static ] 2>/dev/null; then
        rm -f ffmpeg.tar.gz
        curl -L -o ffmpeg-linux-64.zip "https://github.com/ffbinaries/ffbinaries-prebuilt/releases/download/v6.1/ffmpeg-6.1-linux-64.zip" 2>/dev/null
        if command -v unzip &>/dev/null && [ -f ffmpeg-linux-64.zip ]; then
            unzip -o ffmpeg-linux-64.zip -d ffmpeg-extracted
            cp ffmpeg-extracted/ffmpeg "$BIN_DIR/ffmpeg"
            chmod +x "$BIN_DIR/ffmpeg"
            rm -rf ffmpeg-linux-64.zip ffmpeg-extracted
        else
            # Last resort: try system package or manual
            echo -e "${YELLOW}  Could not auto-install FFmpeg. Trying ffmpeg from system...${NC}"
        fi
    else
        cp ffmpeg-*-static/ffmpeg "$BIN_DIR/ffmpeg"
        cp ffmpeg-*-static/ffprobe "$BIN_DIR/ffprobe"
        chmod +x "$BIN_DIR/ffmpeg" "$BIN_DIR/ffprobe"
        rm -rf ffmpeg.tar.gz ffmpeg-*-static
    fi
    cd "$APP_DIR"
    echo -e "  FFmpeg installed ${GREEN}OK${NC}"
fi

# ------------------------------------------
# 5. Install edge-tts and yt-dlp
# ------------------------------------------
echo -e "${YELLOW}[5/9] Installing Python tools (edge-tts, yt-dlp)...${NC}"

if command -v pip3 &>/dev/null; then
    pip3 install --user --quiet edge-tts yt-dlp 2>&1 | tail -3
    echo -e "  edge-tts and yt-dlp installed ${GREEN}OK${NC}"
else
    echo -e "${YELLOW}  WARNING: pip3 not found. Install edge-tts and yt-dlp manually.${NC}"
    echo "  Try: python3 -m pip install --user edge-tts yt-dlp"
fi

# ------------------------------------------
# 6. Update PATH in ~/.bashrc
# ------------------------------------------
echo -e "${YELLOW}[6/9] Configuring PATH...${NC}"

BASHRC="$HOME/.bashrc"
PATH_LINE='export PATH="$HOME/bin:$HOME/.local/bin:$PATH"'

if [ -f "$BASHRC" ] && grep -qF 'HOME/bin' "$BASHRC"; then
    echo -e "  PATH already configured ${GREEN}OK${NC}"
else
    echo "" >> "$BASHRC"
    echo "# Added by Dubber setup" >> "$BASHRC"
    echo "$PATH_LINE" >> "$BASHRC"
    echo -e "  PATH added to ~/.bashrc ${GREEN}OK${NC}"
fi

# ------------------------------------------
# 7. Create storage directories & permissions
# ------------------------------------------
echo -e "${YELLOW}[7/9] Setting up storage...${NC}"

cd "$APP_DIR"
mkdir -p storage/app/public
mkdir -p storage/framework/{cache,sessions,views}
mkdir -p storage/logs
mkdir -p bootstrap/cache
mkdir -p "$HOME/logs"

chmod -R 775 storage bootstrap/cache 2>/dev/null || true
echo -e "  Storage directories ${GREEN}OK${NC}"

# ------------------------------------------
# 8. Laravel setup
# ------------------------------------------
echo -e "${YELLOW}[8/9] Setting up Laravel...${NC}"

cd "$APP_DIR"

# Generate app key if not set
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    php artisan key:generate --force
    echo "  App key generated"
fi

# Run migrations
php artisan migrate --force
echo "  Migrations complete"

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true
echo "  Storage linked"

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo -e "  Config/routes/views cached ${GREEN}OK${NC}"

# ------------------------------------------
# 9. Make deploy scripts executable
# ------------------------------------------
echo -e "${YELLOW}[9/9] Finalizing...${NC}"

chmod +x "$APP_DIR/deploy/queue-worker.sh" 2>/dev/null || true
echo -e "  Scripts configured ${GREEN}OK${NC}"

# ------------------------------------------
# Done! Print crontab instructions
# ------------------------------------------
echo ""
echo -e "${GREEN}=========================================${NC}"
echo -e "${GREEN} Setup Complete!${NC}"
echo -e "${GREEN}=========================================${NC}"
echo ""
echo -e "${YELLOW}Add these crontab entries (run: crontab -e):${NC}"
echo ""
echo "# Laravel scheduler (runs every minute)"
echo "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
echo ""
echo "# Queue worker watchdog (checks every 5 minutes)"
echo "*/5 * * * * $APP_DIR/deploy/queue-worker.sh"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "1. Copy .env.production to .env and fill in your database credentials and RunPod URLs"
echo "2. Build frontend assets locally: npm install && npm run build"
echo "3. Upload the public/build/ directory to the server"
echo "4. Add the crontab entries above"
echo "5. Visit your site to verify it works"
echo ""
