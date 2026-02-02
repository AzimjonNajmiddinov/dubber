#!/bin/bash
# RunPod Full Setup Script - Run this once

set -e

echo "=== STEP 1: Installing system packages ==="
apt-get update
apt-get install -y redis-server nginx ffmpeg curl unzip git software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-gd php8.2-bcmath
apt-get install -y mysql-server

echo "=== STEP 2: Starting databases ==="
service mysql start
service redis-server start
sleep 3
mysql -e "CREATE DATABASE IF NOT EXISTS dubber; CREATE USER IF NOT EXISTS 'dubber'@'localhost' IDENTIFIED BY 'dubber123'; GRANT ALL ON dubber.* TO 'dubber'@'localhost'; FLUSH PRIVILEGES;"

echo "=== STEP 3: Cloning app ==="
cd /workspace
rm -rf dubber
git clone https://github.com/AzimjonNajmiddinov/dubber.git
cd dubber

echo "=== STEP 4: Installing Composer ==="
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
composer install --no-dev --no-interaction

echo "=== STEP 5: Configuring Laravel ==="
cp .env.example .env
sed -i 's/DB_HOST=.*/DB_HOST=127.0.0.1/' .env
sed -i 's/DB_DATABASE=.*/DB_DATABASE=dubber/' .env
sed -i 's/DB_USERNAME=.*/DB_USERNAME=dubber/' .env
sed -i 's/DB_PASSWORD=.*/DB_PASSWORD=dubber123/' .env
sed -i 's/TTS_DRIVER=.*/TTS_DRIVER=xtts/' .env
sed -i 's|XTTS_SERVICE_URL=.*|XTTS_SERVICE_URL=http://127.0.0.1:8004|' .env
sed -i 's|WHISPERX_SERVICE_URL=.*|WHISPERX_SERVICE_URL=http://127.0.0.1:8001|' .env
php artisan key:generate --force
php artisan migrate --force
chmod -R 777 storage bootstrap/cache

echo "=== STEP 6: Installing Python GPU packages ==="
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121
pip install TTS faster-whisper whisperx fastapi uvicorn python-multipart

echo "=== STEP 7: Configuring Nginx ==="
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/sites-available/dubber << 'NGINXCONF'
server {
    listen 8888;
    root /workspace/dubber/public;
    index index.php;
    client_max_body_size 2G;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 600;
    }
}
NGINXCONF
ln -sf /etc/nginx/sites-available/dubber /etc/nginx/sites-enabled/
service php8.2-fpm start
service nginx restart

echo "=== STEP 8: Starting services ==="
export HF_TOKEN=YOUR_HF_TOKEN_HERE

cd /workspace/dubber/xtts-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /tmp/xtts.log 2>&1 &
echo "XTTS starting..."

cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8001 > /tmp/whisperx.log 2>&1 &
echo "WhisperX starting..."

cd /workspace/dubber
nohup php artisan queue:work --sleep=3 --tries=3 > /tmp/queue1.log 2>&1 &
nohup php artisan queue:work --sleep=3 --tries=3 > /tmp/queue2.log 2>&1 &
echo "Queue workers starting..."

echo "=== Waiting for services to start ==="
sleep 20

echo ""
echo "=== CHECKING SERVICES ==="
echo "XTTS:" && curl -s http://localhost:8004/health
echo ""
echo "WhisperX:" && curl -s http://localhost:8001/health
echo ""
echo "GPU:" && nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv
echo ""
echo "=========================================="
echo "  SETUP COMPLETE!"
echo "  Access your app at port 8888"
echo "=========================================="
