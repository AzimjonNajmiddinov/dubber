FROM runpod/pytorch:2.1.0-py3.10-cuda11.8.0-devel-ubuntu22.04

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies
RUN pip install --no-cache-dir \
    runpod \
    demucs \
    boto3

COPY handler.py /app/handler.py

# Pre-download the htdemucs model
RUN python -c "import demucs.pretrained; demucs.pretrained.get_model('htdemucs')"

CMD ["python", "-u", "/app/handler.py"]
