FROM python:3.11-slim

WORKDIR /app

# Install system dependencies including Rust for TTS build
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libsndfile1 \
    git \
    curl \
    build-essential \
    rustc \
    cargo \
    && rm -rf /var/lib/apt/lists/*

# Upgrade pip
RUN pip install --upgrade pip setuptools wheel

# Install PyTorch CPU first (lighter)
RUN pip install --no-cache-dir \
    --index-url https://download.pytorch.org/whl/cpu \
    torch==2.2.2 \
    torchaudio==2.2.2

# Install other dependencies
RUN pip install --no-cache-dir \
    fastapi==0.109.0 \
    uvicorn[standard]==0.27.0 \
    python-multipart==0.0.6 \
    pydantic==2.5.3

# Install TTS (this needs Rust for some deps)
RUN pip install --no-cache-dir TTS>=0.22.0

# Copy application
COPY app.py .

# Create directories
RUN mkdir -p /app/voices /app/cache

# Environment
ENV PYTHONUNBUFFERED=1
ENV STORAGE_PATH=/var/www/storage/app
ENV VOICES_PATH=/app/voices
ENV CACHE_PATH=/app/cache

EXPOSE 8000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
