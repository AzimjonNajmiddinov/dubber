#!/bin/bash
# Full Dubber Stack Deployment for RunPod GPU
set -e

echo "=========================================="
echo "  Dubber Full Stack GPU Deployment"
echo "=========================================="

# Check GPU
echo ""
echo "=== Checking GPU ==="
nvidia-smi --query-gpu=name,memory.total --format=csv || echo "No GPU found!"

# Update system
echo ""
echo "=== Updating System ==="
apt-get update
apt-get install -y curl git

# Install Docker
if ! command -v docker &> /dev/null; then
    echo ""
    echo "=== Installing Docker ==="
    curl -fsSL https://get.docker.com | sh
fi

# Install Docker Compose
if ! command -v docker-compose &> /dev/null; then
    echo ""
    echo "=== Installing Docker Compose ==="
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# Install NVIDIA Container Toolkit
echo ""
echo "=== Installing NVIDIA Container Toolkit ==="
curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | \
    sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
    tee /etc/apt/sources.list.d/nvidia-container-toolkit.list
apt-get update
apt-get install -y nvidia-container-toolkit
nvidia-ctk runtime configure --runtime=docker
systemctl restart docker || true

# Clone repository
echo ""
echo "=== Cloning Repository ==="
cd /workspace
if [ -d "dubber" ]; then
    cd dubber
    git pull
else
    git clone https://github.com/AzimjonNajmiddinov/dubber.git
    cd dubber
fi

# Create .env file
echo ""
echo "=== Creating Environment File ==="
if [ ! -f ".env" ]; then
    cp .env.example .env
fi

# Update .env for GPU deployment
cat > .env << 'ENVFILE'
APP_NAME=Dubber
APP_ENV=production
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=http://localhost:8080

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=dubber
DB_USERNAME=dubber
DB_PASSWORD=dubber123

REDIS_HOST=redis
REDIS_PORT=6379

QUEUE_CONNECTION=redis

# TTS Configuration - GPU XTTS
TTS_DRIVER=xtts
TTS_FALLBACK=edge
TTS_AUTO_CLONE=true
XTTS_SERVICE_URL=http://xtts:8000

# Cleanup
DELETE_AFTER_DUBBING=true

# OpenAI (for translation)
OPENAI_API_KEY=${OPENAI_API_KEY:-your_openai_key_here}
ENVFILE

# Generate app key
sed -i "s|APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env

# Build and start services
echo ""
echo "=== Building Services ==="
docker-compose -f runpod-deploy/docker-compose.full.yml build

echo ""
echo "=== Starting Services ==="
docker-compose -f runpod-deploy/docker-compose.full.yml up -d

# Wait for MySQL
echo ""
echo "=== Waiting for MySQL ==="
sleep 30

# Run migrations
echo ""
echo "=== Running Migrations ==="
docker exec dubber_app php artisan migrate --force
docker exec dubber_app php artisan key:generate --force

# Get public IP
PUBLIC_IP=$(curl -s ifconfig.me || echo "localhost")

echo ""
echo "=========================================="
echo "  Deployment Complete!"
echo "=========================================="
echo ""
echo "App URL: http://${PUBLIC_IP}:8080"
echo ""
echo "API Endpoints:"
echo "  - Upload video: POST http://${PUBLIC_IP}:8080/api/videos"
echo "  - Check status: GET http://${PUBLIC_IP}:8080/api/videos/{id}"
echo ""
echo "XTTS Health: http://${PUBLIC_IP}:8004/health"
echo ""
echo "Check GPU: docker exec dubber_xtts_gpu nvidia-smi"
echo "View logs: docker-compose -f runpod-deploy/docker-compose.full.yml logs -f"
echo "=========================================="
