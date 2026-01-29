FROM python:3.11-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libsndfile1 \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Upgrade pip
RUN pip install --upgrade pip

# Install Python dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

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
