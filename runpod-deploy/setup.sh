#!/bin/bash
# RunPod GPU Deployment Script for Dubber

set -e

echo "=== Dubber GPU Deployment Setup ==="

# Check if running on GPU machine
if ! command -v nvidia-smi &> /dev/null; then
    echo "WARNING: nvidia-smi not found. Make sure you're on a GPU instance!"
fi

# Show GPU info
echo ""
echo "=== GPU Info ==="
nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv 2>/dev/null || echo "No GPU detected"

# Install Docker if not present
if ! command -v docker &> /dev/null; then
    echo ""
    echo "=== Installing Docker ==="
    curl -fsSL https://get.docker.com | sh
fi

# Install Docker Compose if not present
if ! command -v docker-compose &> /dev/null; then
    echo ""
    echo "=== Installing Docker Compose ==="
    pip install docker-compose
fi

# Install NVIDIA Container Toolkit if not present
if ! dpkg -l | grep -q nvidia-container-toolkit; then
    echo ""
    echo "=== Installing NVIDIA Container Toolkit ==="
    distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
    curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | apt-key add -
    curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | tee /etc/apt/sources.list.d/nvidia-docker.list
    apt-get update
    apt-get install -y nvidia-container-toolkit
    systemctl restart docker
fi

# Clone repository if not present
if [ ! -d "/workspace/dubber" ]; then
    echo ""
    echo "=== Cloning Repository ==="
    cd /workspace
    git clone https://github.com/YOUR_USERNAME/dubber.git
    cd dubber
else
    cd /workspace/dubber
    git pull
fi

# Copy environment file
if [ ! -f ".env" ]; then
    echo ""
    echo "=== Creating .env file ==="
    cp .env.example .env

    # Update for GPU deployment
    sed -i 's/TTS_DRIVER=.*/TTS_DRIVER=xtts/' .env
    sed -i 's/TTS_AUTO_CLONE=.*/TTS_AUTO_CLONE=true/' .env
fi

# Build and start with GPU docker-compose
echo ""
echo "=== Building and Starting Services ==="
cd runpod-deploy
docker-compose -f docker-compose.gpu.yml up -d --build

echo ""
echo "=== Waiting for services to start ==="
sleep 30

# Check XTTS GPU status
echo ""
echo "=== Checking XTTS GPU Status ==="
curl -s http://localhost:8004/health | python3 -m json.tool || echo "XTTS not ready yet"

# Run migrations
echo ""
echo "=== Running Migrations ==="
docker exec dubber_app php artisan migrate --force

echo ""
echo "=========================================="
echo "Deployment Complete!"
echo ""
echo "Access your app at: http://$(curl -s ifconfig.me):8080"
echo ""
echo "XTTS API: http://localhost:8004"
echo "Check GPU status: curl http://localhost:8004/health"
echo "=========================================="
