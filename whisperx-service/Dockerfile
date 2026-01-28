FROM python:3.10-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    ffmpeg \
    git \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

ENV TORCH_LOAD_WEIGHTS_ONLY=0
ENV TORCHAUDIO_USE_SOUNDFILE=1
ENV PIP_NO_BUILD_ISOLATION=1

COPY requirements.txt .

RUN pip install --upgrade pip \
 && pip install --no-cache-dir --prefer-binary -r requirements.txt

COPY app.py /app/app.py

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD python -c "import requests; import sys; sys.exit(0) if requests.get('http://127.0.0.1:8000/health', timeout=3).status_code==200 else sys.exit(1)"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--timeout-keep-alive", "300"]
