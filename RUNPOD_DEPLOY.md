# RunPod GPU Deployment Guide

## Step 1: Create RunPod Pod

1. Go to [RunPod.io](https://runpod.io)
2. Create a new GPU Pod:
   - **Template**: `RunPod Pytorch 2.1` or `RunPod Ubuntu`
   - **GPU**: RTX 3090/4090 (24GB) or A100 (best)
   - **Volume**: 50GB+ persistent storage
   - **Expose ports**: 8888 (HTTP), 8001, 8004

## Step 2: Upload Project

Upload the deployment archive to RunPod:

```bash
# Option A: From your local machine
scp /tmp/dubber-deploy.zip root@<pod-ip>:/workspace/

# Option B: Using RunPod web terminal
# Upload via the file browser or use wget from a cloud storage URL
```

## Step 3: Run Deployment Script

Connect to your RunPod via SSH or web terminal, then run:

```bash
cd /workspace
unzip dubber-deploy.zip -d dubber
cd dubber

# Make deploy script executable and run
chmod +x runpod-deploy/full-stack-direct.sh
./runpod-deploy/full-stack-direct.sh
```

Or run these commands manually:

```bash
# === QUICK DEPLOY COMMANDS ===

# 1. Update system
apt-get update && apt-get install -y nginx redis-server supervisor ffmpeg curl unzip

# 2. Install PHP 8.2
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-curl \
    php8.2-xml php8.2-mbstring php8.2-zip php8.2-gd php8.2-bcmath

# 3. Install MySQL
apt-get install -y mysql-server
service mysql start
mysql -e "CREATE DATABASE dubber; CREATE USER 'dubber'@'localhost' IDENTIFIED BY 'dubber123'; GRANT ALL ON dubber.* TO 'dubber'@'localhost';"

# 4. Start Redis
service redis-server start

# 5. Install Composer & dependencies
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
cd /workspace/dubber
composer install --no-dev

# 6. Configure Laravel
cp .env.example .env
# Edit .env with your API keys:
nano .env
# Set:
#   DB_HOST=127.0.0.1
#   DB_DATABASE=dubber
#   DB_USERNAME=dubber
#   DB_PASSWORD=dubber123
#   TTS_DRIVER=xtts
#   XTTS_SERVICE_URL=http://127.0.0.1:8004
#   WHISPERX_SERVICE_URL=http://127.0.0.1:8001
#   OPENAI_API_KEY=your_key
#   HF_TOKEN=your_huggingface_token

php artisan key:generate
php artisan migrate --force
chmod -R 777 storage bootstrap/cache

# 7. Configure Nginx
cat > /etc/nginx/sites-available/dubber << 'EOF'
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
EOF

ln -sf /etc/nginx/sites-available/dubber /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
service php8.2-fpm start
service nginx restart

# 8. Install Python services (GPU)
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cu121
pip install TTS faster-whisper whisperx fastapi uvicorn python-multipart

# 9. Start services
export HF_TOKEN="your_huggingface_token"

# Start XTTS (GPU)
cd /workspace/dubber/xtts-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8004 > /var/log/xtts.log 2>&1 &

# Start WhisperX (GPU)
cd /workspace/dubber/whisperx-service
nohup python -m uvicorn app:app --host 0.0.0.0 --port 8001 > /var/log/whisperx.log 2>&1 &

# Start Laravel Queue
cd /workspace/dubber
nohup php artisan queue:work --sleep=3 --tries=3 > /var/log/queue.log 2>&1 &

# 10. Check services
curl http://localhost:8004/health  # XTTS
curl http://localhost:8001/health  # WhisperX
curl http://localhost:8888         # Laravel App
```

## Step 4: Access Your App

Your app will be available at:
- **Web UI**: `http://<pod-ip>:8888`
- **XTTS API**: `http://<pod-ip>:8004`
- **WhisperX API**: `http://<pod-ip>:8001`

## Environment Variables

Make sure to set these in `.env`:

```env
APP_URL=http://<pod-ip>:8888

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=dubber
DB_USERNAME=dubber
DB_PASSWORD=dubber123

REDIS_HOST=127.0.0.1

TTS_DRIVER=xtts
XTTS_SERVICE_URL=http://127.0.0.1:8004
WHISPERX_SERVICE_URL=http://127.0.0.1:8001
DEMUCS_SERVICE_URL=http://127.0.0.1:8002

OPENAI_API_KEY=sk-your-key
HF_TOKEN=hf_your-token
```

## Monitoring

```bash
# Check logs
tail -f /var/log/xtts.log
tail -f /var/log/whisperx.log
tail -f /var/log/queue.log
tail -f /workspace/dubber/storage/logs/laravel.log

# Check GPU usage
nvidia-smi

# Restart services
pkill -f uvicorn
pkill -f "queue:work"
# Then start them again
```

## Troubleshooting

### XTTS not loading
```bash
# Check CUDA
python -c "import torch; print(torch.cuda.is_available())"

# Reinstall with CUDA
pip install torch --index-url https://download.pytorch.org/whl/cu121 --force-reinstall
```

### WhisperX HF_TOKEN error
```bash
export HF_TOKEN="hf_your_token"
# Restart whisperx service
```

### PHP errors
```bash
service php8.2-fpm restart
service nginx restart
php artisan config:clear
php artisan cache:clear
```
