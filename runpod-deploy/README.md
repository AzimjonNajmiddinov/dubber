# RunPod GPU Deployment Guide

Deploy your dubbing app with GPU-accelerated XTTS voice cloning.

## Quick Start (Test XTTS Only)

The fastest way to test GPU performance is to deploy just the XTTS service:

### 1. Create RunPod Account
1. Go to [runpod.io](https://runpod.io) and sign up
2. Add credits ($10-20 is enough for testing)

### 2. Deploy GPU Pod
1. Click "Deploy" → "GPU Pods"
2. Select a GPU:
   - **RTX 4090** ($0.34/hr) - Best value for testing
   - **RTX 3090** ($0.22/hr) - Cheaper option
   - **A100** ($1.19/hr) - Production quality
3. Select template: **RunPod Pytorch 2.1**
4. Set container disk: **20GB** (for model storage)
5. Click "Deploy"

### 3. Connect and Setup

SSH into your pod or use the web terminal:

```bash
# Clone your repo
cd /workspace
git clone https://github.com/YOUR_USERNAME/dubber.git
cd dubber

# Build and run XTTS with GPU
cd xtts-service
docker build -f Dockerfile.gpu -t xtts-gpu .
docker run -d --gpus all -p 8000:8000 \
  -v /workspace/voices:/app/voices \
  -v /workspace/storage:/var/www/storage/app \
  -e COQUI_TOS_AGREED=1 \
  --name xtts \
  xtts-gpu

# Wait for model to download (2-3 minutes)
sleep 180

# Check GPU status
curl http://localhost:8000/health
```

### 4. Test Speed

```bash
# Clone a voice
curl -X POST http://localhost:8000/clone \
  -F "audio=@/path/to/sample.wav" \
  -F "name=test_voice" \
  -F "language=uz"

# Synthesize (should be 10-20x faster than CPU!)
curl -X POST http://localhost:8000/synthesize \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Salom, bu GPU tezligi testi.",
    "voice_id": "YOUR_VOICE_ID",
    "language": "uz",
    "output_path": "test_output.wav"
  }'
```

## Expected Performance

| Hardware | Time per Segment | Real-time Factor |
|----------|------------------|------------------|
| CPU (M1) | 3-5 minutes | 3-5x slower |
| RTX 3090 | 10-15 seconds | ~1x real-time |
| RTX 4090 | 5-10 seconds | 2x faster |
| A100 | 3-5 seconds | 3-4x faster |

## Full Stack Deployment

For full app deployment with GPU XTTS:

```bash
cd /workspace/dubber/runpod-deploy
chmod +x setup.sh
./setup.sh
```

## Connect Local App to RunPod XTTS

You can run your PHP app locally but use RunPod for XTTS:

1. Get your RunPod pod's public IP
2. Update your local `.env`:
```
XTTS_SERVICE_URL=http://YOUR_RUNPOD_IP:8000
TTS_DRIVER=xtts
TTS_AUTO_CLONE=true
```

## Cost Estimate

- RTX 4090: $0.34/hr
- 27 segments × 10 seconds = ~5 minutes per video
- Cost per video: ~$0.03

## Troubleshooting

### Check GPU is detected
```bash
docker exec xtts nvidia-smi
curl http://localhost:8000/health
# Should show "cuda_available": true
```

### View logs
```bash
docker logs -f xtts
```

### Restart XTTS
```bash
docker restart xtts
```
