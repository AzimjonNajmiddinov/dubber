FROM python:3.10-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    git \
    wget \
    ca-certificates \
    libgl1 \
    libglib2.0-0 \
    libgomp1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Keep pip tooling sane + avoid setuptools warning spam
RUN python -m pip install --no-cache-dir -U "pip<26" "setuptools<81" wheel

# API deps
RUN pip install --no-cache-dir \
    fastapi \
    "uvicorn[standard]" \
    requests \
    tqdm \
    soundfile

# Torch CPU (ONLY torch packages from PyTorch CPU index)
RUN pip install --no-cache-dir \
    torch torchvision torchaudio \
    --index-url https://download.pytorch.org/whl/cpu

# Wav2Lip repo
RUN git clone --depth 1 https://github.com/Rudrabha/Wav2Lip.git /app/Wav2Lip

# ---- Patch Wav2Lip audio.py for librosa 0.10+ compatibility ----
# librosa 0.10+ changed mel() from positional to keyword-only arguments
RUN sed -i 's/librosa\.filters\.mel(hp\.sample_rate, hp\.n_fft,/librosa.filters.mel(sr=hp.sample_rate, n_fft=hp.n_fft,/g' /app/Wav2Lip/audio.py \
 && sed -i 's/librosa\.filters\.mel(hparams\.sample_rate, hparams\.n_fft,/librosa.filters.mel(sr=hparams.sample_rate, n_fft=hparams.n_fft,/g' /app/Wav2Lip/audio.py

# ---- Wav2Lip compatible scientific stack ----
# Scientific stack for Wav2Lip
# Using librosa 0.10.2 with keyword args (patched in audio.py above)
RUN pip install --no-cache-dir \
    "numpy==1.26.4" \
    "scipy==1.11.4" \
    "numba==0.59.1" \
    "llvmlite==0.42.0" \
    "librosa==0.10.2" \
    "opencv-python-headless>=4.8.0.76" \
    matplotlib \
    pillow \
    scikit-image \
    "face-alignment==1.3.5"

# --- Required face detector weights (S3FD) ---
RUN mkdir -p /app/Wav2Lip/face_detection/detection/sfd \
 && wget -q -O /app/Wav2Lip/face_detection/detection/sfd/s3fd.pth \
    https://www.adrianbulat.com/downloads/python-fan/s3fd-619a316812.pth \
 && python - <<'PY'
import os
p="/app/Wav2Lip/face_detection/detection/sfd/s3fd.pth"
sz=os.path.getsize(p)
assert sz > 1_000_000, f"s3fd.pth too small ({sz})"
print("s3fd OK:", sz)
PY

# --- Wav2Lip checkpoint ---
RUN mkdir -p /app/Wav2Lip/checkpoints \
 && wget -q -O /app/Wav2Lip/checkpoints/wav2lip.pth \
    https://huggingface.co/numz/wav2lip_studio/resolve/main/Wav2lip/wav2lip.pth \
 && python - <<'PY'
import os
p="/app/Wav2Lip/checkpoints/wav2lip.pth"
sz=os.path.getsize(p)
assert sz > 10_000_000, f"wav2lip.pth too small ({sz})"
with open(p,"rb") as f:
    head=f.read(200).lower()
assert b"<html" not in head, "wav2lip.pth looks like HTML"
print("wav2lip checkpoint OK:", sz)
PY

# Sanity check: verify librosa mel filter works with keyword args (0.10+ compatible)
RUN python - <<'PY'
import librosa
print("librosa:", librosa.__version__)
import librosa.filters
# Use keyword args for librosa 0.10+ compatibility
m = librosa.filters.mel(sr=16000, n_fft=800, n_mels=80)
print("mel ok:", m.shape)
PY

# Verify Wav2Lip audio.py was patched correctly
RUN grep -q "sr=hp.sample_rate" /app/Wav2Lip/audio.py && echo "Wav2Lip audio.py patched successfully"

COPY app.py /app/app.py

ENV OMP_NUM_THREADS=1
ENV MKL_NUM_THREADS=1
ENV OPENBLAS_NUM_THREADS=1
ENV NUMEXPR_NUM_THREADS=1

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
