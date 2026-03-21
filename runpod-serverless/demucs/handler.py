"""
RunPod Serverless handler for Demucs stem separation.

Input:  {"audio_url": "https://..."}
Output: {"vocals_url": "https://...", "no_vocals_url": "https://..."}

Files are transferred via presigned S3 URLs.
"""
import os
import subprocess
import tempfile
import time
import urllib.request

import runpod
import boto3
from botocore.config import Config as BotoConfig

# S3 config from environment
S3_BUCKET = os.environ.get("S3_BUCKET", "dubber-runpod")
S3_ENDPOINT = os.environ.get("S3_ENDPOINT", "")
S3_ACCESS_KEY = os.environ.get("S3_ACCESS_KEY", "")
S3_SECRET_KEY = os.environ.get("S3_SECRET_KEY", "")
S3_REGION = os.environ.get("S3_REGION", "auto")

def get_s3_client():
    kwargs = {
        "aws_access_key_id": S3_ACCESS_KEY,
        "aws_secret_access_key": S3_SECRET_KEY,
        "region_name": S3_REGION,
        "config": BotoConfig(signature_version="s3v4"),
    }
    if S3_ENDPOINT:
        kwargs["endpoint_url"] = S3_ENDPOINT
    return boto3.client("s3", **kwargs)


def download_file(url: str, dest: str):
    """Download a file from URL to local path."""
    urllib.request.urlretrieve(url, dest)


def upload_to_s3(local_path: str, s3_key: str) -> str:
    """Upload file to S3 and return a presigned URL (valid 1 hour)."""
    s3 = get_s3_client()
    s3.upload_file(local_path, S3_BUCKET, s3_key)
    url = s3.generate_presigned_url(
        "get_object",
        Params={"Bucket": S3_BUCKET, "Key": s3_key},
        ExpiresIn=3600,
    )
    return url


def handler(job):
    job_input = job["input"]
    audio_url = job_input.get("audio_url")
    model = job_input.get("model", "htdemucs")
    job_id = job["id"]

    if not audio_url:
        return {"error": "audio_url is required"}

    work_dir = tempfile.mkdtemp(prefix="demucs_")

    try:
        # 1. Download audio
        runpod.serverless.progress_update(job, "Downloading audio...")
        input_path = os.path.join(work_dir, "input.wav")
        download_file(audio_url, input_path)

        file_size = os.path.getsize(input_path)
        if file_size < 1000:
            return {"error": f"Audio file too small ({file_size} bytes)"}

        # 2. Run Demucs
        runpod.serverless.progress_update(job, "Separating stems with Demucs...")
        output_dir = os.path.join(work_dir, "output")
        os.makedirs(output_dir)

        import torch
        device = "cuda" if torch.cuda.is_available() else "cpu"

        cmd = [
            "python", "-m", "demucs.separate",
            "-n", model,
            "--two-stems=vocals",
            "-o", output_dir,
            "--device", device,
            input_path,
        ]

        start_time = time.time()
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
        elapsed = time.time() - start_time

        if result.returncode != 0:
            return {"error": f"Demucs failed: {result.stderr[-500:]}"}

        # 3. Find output files
        model_dir = os.path.join(output_dir, model, "input")
        vocals_path = None
        no_vocals_path = None

        for ext in (".wav", ".flac"):
            v = os.path.join(model_dir, f"vocals{ext}")
            nv = os.path.join(model_dir, f"no_vocals{ext}")
            if os.path.exists(v):
                vocals_path = v
            if os.path.exists(nv):
                no_vocals_path = nv

        if not no_vocals_path:
            return {"error": "no_vocals not generated"}

        # 4. Upload results to S3
        runpod.serverless.progress_update(job, "Uploading results...")
        s3_prefix = f"jobs/{job_id}"

        no_vocals_url = upload_to_s3(no_vocals_path, f"{s3_prefix}/no_vocals.wav")
        vocals_url = upload_to_s3(vocals_path, f"{s3_prefix}/vocals.wav") if vocals_path else None

        return {
            "no_vocals_url": no_vocals_url,
            "vocals_url": vocals_url,
            "elapsed_seconds": round(elapsed, 2),
            "device": device,
        }

    except Exception as e:
        return {"error": str(e)}

    finally:
        # Cleanup
        import shutil
        shutil.rmtree(work_dir, ignore_errors=True)


runpod.serverless.start({"handler": handler})
