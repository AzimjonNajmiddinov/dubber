#!/bin/bash
# Full Dubber Stack - Direct RunPod Deployment (no Docker)
# Installs: MySQL, Redis, PHP, Nginx, XTTS - all on one GPU pod

set -e

echo "=========================================="
echo "  Dubber Full Stack - RunPod GPU Deploy"
echo "=========================================="

# Check GPU
echo ""
echo "=== Checking GPU ==="
nvidia-smi --query-gpu=name,memory.total --format=csv || { echo "ERROR: No GPU!"; exit 1; }

# Update system
echo ""
echo "=== Updating System ==="
apt-get update
apt-get install -y software-properties-common curl wget git unzip ffmpeg supervisor

# ============================================
# Install MySQL
# ============================================
echo ""
echo "=== Installing MySQL ==="
DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server

# Start MySQL
service mysql start

# Setup database
mysql -e "CREATE DATABASE IF NOT EXISTS dubber;"
mysql -e "CREATE USER IF NOT EXISTS 'dubber'@'localhost' IDENTIFIED BY 'dubber123';"
mysql -e "GRANT ALL PRIVILEGES ON dubber.* TO 'dubber'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
echo "MySQL ready!"

# ============================================
# Install Redis
# ============================================
echo ""
echo "=== Installing Redis ==="
apt-get install -y redis-server
service redis-server start
echo "Redis ready!"

# ============================================
# Install PHP 8.2
# ============================================
echo ""
echo "=== Installing PHP 8.2 ==="

# Fix broken apt_pkg on RunPod
apt-get install -y python3-apt 2>/dev/null || true

# Add PHP repository manually (without add-apt-repository)
echo "deb https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main" > /etc/apt/sources.list.d/ondrej-php.list
apt-key adv --keyserver keyserver.ubuntu.com --recv-keys 4F4EA0AAE5267A6C 2>/dev/null || \
    curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x4F4EA0AAE5267A6C" | apt-key add -

apt-get update
apt-get install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-curl \
    php8.2-gd php8.2-mbstring php8.2-xml php8.2-zip php8.2-bcmath php8.2-intl || {
    # Fallback: try PHP 8.1 from default repos
    echo "PHP 8.2 failed, trying default PHP..."
    apt-get install -y php-fpm php-cli php-mysql php-redis php-curl \
        php-gd php-mbstring php-xml php-zip php-bcmath php-intl
}

# Find PHP version installed
PHP_VERSION=$(php -v | head -1 | cut -d' ' -f2 | cut -d'.' -f1,2)
echo "PHP ${PHP_VERSION} installed"

# Configure PHP-FPM
PHP_FPM_CONF=$(find /etc/php -name "www.conf" 2>/dev/null | head -1)
if [ -n "$PHP_FPM_CONF" ]; then
    sed -i 's/listen = .*/listen = 127.0.0.1:9000/' "$PHP_FPM_CONF"
fi

# Start PHP-FPM
service php${PHP_VERSION}-fpm start 2>/dev/null || service php-fpm start 2>/dev/null || true
echo "PHP ready!"

# ============================================
# Install Nginx
# ============================================
echo ""
echo "=== Installing Nginx ==="
apt-get install -y nginx

# Configure Nginx for Laravel
cat > /etc/nginx/sites-available/dubber << 'NGINX'
server {
    listen 8080;
    server_name _;
    root /workspace/dubber/public;
    index index.php;

    client_max_body_size 500M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/dubber /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
service nginx start
echo "Nginx ready!"

# ============================================
# Install Composer
# ============================================
echo ""
echo "=== Installing Composer ==="
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ============================================
# Clone and Setup Laravel App
# ============================================
echo ""
echo "=== Setting up Dubber App ==="
cd /workspace

if [ -d "dubber" ]; then
    cd dubber
    git pull
else
    git clone https://github.com/AzimjonNajmiddinov/dubber.git
    cd dubber
fi

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Create .env
cat > .env << 'ENVFILE'
APP_NAME=Dubber
APP_ENV=production
APP_DEBUG=true
APP_URL=http://localhost:8080

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dubber
DB_USERNAME=dubber
DB_PASSWORD=dubber123

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

QUEUE_CONNECTION=redis

# TTS - GPU XTTS
TTS_DRIVER=xtts
TTS_FALLBACK=edge
TTS_AUTO_CLONE=true
XTTS_SERVICE_URL=http://127.0.0.1:8000

# Cleanup
DELETE_AFTER_DUBBING=false
ENVFILE

# Generate app key
php artisan key:generate --force

# Set permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Run migrations
php artisan migrate --force
php artisan config:cache
php artisan route:cache

echo "Laravel app ready!"

# ============================================
# Setup XTTS Service
# ============================================
echo ""
echo "=== Setting up XTTS Service ==="

# Install Python dependencies
pip install --ignore-installed blinker 2>/dev/null || true
pip install fastapi uvicorn python-multipart pydantic
pip install TTS

# Create directories
mkdir -p /workspace/dubber/storage/app/videos
mkdir -p /workspace/dubber/storage/app/audio
mkdir -p /workspace/voices

# ============================================
# Setup Supervisor for Queue Workers
# ============================================
echo ""
echo "=== Setting up Queue Workers ==="

cat > /etc/supervisor/conf.d/dubber-worker.conf << 'SUPERVISOR'
[program:dubber-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /workspace/dubber/artisan queue:work redis --queue=chunks,segment-processing,segment-generation,default --sleep=3 --tries=2 --timeout=1800
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
redirect_stderr=true
stdout_logfile=/workspace/dubber/storage/logs/worker.log
stopwaitsecs=3600
SUPERVISOR

cat > /etc/supervisor/conf.d/xtts.conf << 'SUPERVISOR'
[program:xtts]
command=python -m uvicorn app:app --host 0.0.0.0 --port 8000
directory=/workspace/dubber/xtts-service
environment=COQUI_TOS_AGREED="1",STORAGE_PATH="/workspace/dubber/storage/app",VOICES_PATH="/workspace/voices"
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/workspace/xtts.log
SUPERVISOR

supervisorctl reread
supervisorctl update

echo "Queue workers configured!"

# ============================================
# Start All Services
# ============================================
echo ""
echo "=== Starting All Services ==="

service mysql restart
service redis-server restart
service php${PHP_VERSION}-fpm restart 2>/dev/null || service php-fpm restart 2>/dev/null || true
service nginx restart
supervisorctl restart all

# Wait for XTTS to load
echo ""
echo "=== Waiting for XTTS model to load (2-3 minutes) ==="
for i in {1..60}; do
    if curl -s http://localhost:8000/health > /dev/null 2>&1; then
        echo "XTTS is ready!"
        curl -s http://localhost:8000/health | python3 -m json.tool
        break
    fi
    echo "Loading XTTS model... ($i/60)"
    sleep 5
done

# Get public URL
PUBLIC_IP=$(curl -s ifconfig.me 2>/dev/null || echo "localhost")

echo ""
echo "=========================================="
echo "  DEPLOYMENT COMPLETE!"
echo "=========================================="
echo ""
echo "App URL: http://${PUBLIC_IP}:8080"
echo ""
echo "Or use RunPod proxy URL from dashboard:"
echo "  Connect → HTTP Service [Port 8080]"
echo ""
echo "API Endpoints:"
echo "  POST /api/videos     - Upload video for dubbing"
echo "  GET  /api/videos/{id} - Check status"
echo ""
echo "Logs:"
echo "  App:    tail -f /workspace/dubber/storage/logs/laravel.log"
echo "  Worker: tail -f /workspace/dubber/storage/logs/worker.log"
echo "  XTTS:   tail -f /workspace/xtts.log"
echo ""
echo "Check GPU: nvidia-smi"
echo "Check services: supervisorctl status"
echo "=========================================="
