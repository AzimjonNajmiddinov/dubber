FROM python:3.12-slim

# System deps required for torchaudio audio backends + demucs
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libsndfile1 \
    libgomp1 \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Python deps
RUN pip install --no-cache-dir -U pip setuptools wheel
RUN pip install --no-cache-dir "numpy<2"

# Torch CPU wheels - optimized for inference
RUN pip install --no-cache-dir \
    --index-url https://download.pytorch.org/whl/cpu \
    torch==2.2.2 \
    torchaudio==2.2.2

# Required so torchaudio can save wav/flac via libsndfile
RUN pip install --no-cache-dir soundfile

# Demucs + API
RUN pip install --no-cache-dir \
    demucs==4.0.1 \
    fastapi==0.115.0 \
    uvicorn[standard]==0.30.6

# Limit CPU usage - single thread for lighter processing
ENV OMP_NUM_THREADS=1
ENV MKL_NUM_THREADS=1
ENV NUMEXPR_NUM_THREADS=1
ENV OPENBLAS_NUM_THREADS=1

# Pre-download the model to avoid first-request delay
RUN python -c "import demucs.pretrained; demucs.pretrained.get_model('htdemucs')" || true

WORKDIR /app
COPY . /app

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

EXPOSE 8000

# Single worker to limit CPU usage
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
