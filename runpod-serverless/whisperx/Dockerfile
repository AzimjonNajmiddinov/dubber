FROM runpod/pytorch:2.1.0-py3.10-cuda11.8.0-devel-ubuntu22.04

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

# Install Python dependencies
RUN pip install --no-cache-dir \
    runpod \
    faster-whisper \
    whisperx \
    pyannote.audio \
    librosa \
    soundfile \
    numpy \
    boto3

COPY handler.py /app/handler.py

# Pre-download whisper model
RUN python -c "from faster_whisper import WhisperModel; WhisperModel('large-v3', device='cpu', compute_type='int8')"

CMD ["python", "-u", "/app/handler.py"]
