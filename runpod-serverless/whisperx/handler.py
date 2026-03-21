"""
RunPod Serverless handler for WhisperX transcription + diarization.

Input:  {"audio_url": "https://...", "min_speakers": null, "max_speakers": null}
Output: {"language": "ru", "segments": [...], "speakers": {...}}

Audio downloaded from presigned S3 URL. Results returned as JSON (small payload).
"""
import os
import tempfile
import traceback
import urllib.request

import numpy as np
import soundfile as sf
import librosa
import torch
import runpod

from faster_whisper import WhisperModel
import whisperx
from whisperx.diarize import DiarizationPipeline

# Config
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
HF_TOKEN = os.environ.get("HF_TOKEN", "")
WHISPER_MODEL = os.environ.get("WHISPER_MODEL", "large-v3")
WHISPER_COMPUTE_TYPE = os.environ.get("WHISPER_COMPUTE_TYPE", "float16" if DEVICE == "cuda" else "int8")

# Lazy-loaded models
_whisper_model = None
_diarize_pipeline = None
_align_model = None
_align_metadata = None
_align_language = None


def get_whisper_model():
    global _whisper_model
    if _whisper_model is None:
        print(f"Loading Whisper model '{WHISPER_MODEL}' on {DEVICE}...")
        _whisper_model = WhisperModel(WHISPER_MODEL, device=DEVICE, compute_type=WHISPER_COMPUTE_TYPE)
        print("Whisper model loaded.")
    return _whisper_model


def get_diarize_pipeline():
    global _diarize_pipeline
    if not HF_TOKEN:
        return None
    if _diarize_pipeline is None:
        print("Loading diarization pipeline...")
        try:
            from huggingface_hub import login as hf_login
            hf_login(token=HF_TOKEN, add_to_git_credential=False)
        except Exception:
            pass
        try:
            _diarize_pipeline = DiarizationPipeline(
                model_name="pyannote/speaker-diarization-3.1",
                use_auth_token=HF_TOKEN,
                device=DEVICE,
            )
        except TypeError:
            from pyannote.audio import Pipeline as PyannotePipeline
            pipe = PyannotePipeline.from_pretrained("pyannote/speaker-diarization-3.1")
            pipe = pipe.to(torch.device(DEVICE))
            dp = object.__new__(DiarizationPipeline)
            dp.model = pipe
            dp.device = DEVICE
            _diarize_pipeline = dp
        print("Diarization pipeline loaded.")
    return _diarize_pipeline


def get_align_model(language_code):
    global _align_model, _align_metadata, _align_language
    if _align_model is not None and _align_language == language_code:
        return _align_model, _align_metadata
    try:
        _align_model, _align_metadata = whisperx.load_align_model(
            language_code=language_code, device=DEVICE,
        )
        _align_language = language_code
    except Exception as e:
        print(f"Alignment model failed for '{language_code}': {e}")
        _align_model = None
        _align_metadata = None
    return _align_model, _align_metadata


def merge_segments(segments, max_gap=0.3):
    if not segments:
        return segments
    merged = [dict(segments[0])]
    for seg in segments[1:]:
        prev = merged[-1]
        gap = seg["start"] - prev["end"]
        prev_duration = prev["end"] - prev["start"]
        if gap > max_gap or prev_duration > 6.0:
            merged.append(dict(seg))
        else:
            prev["end"] = seg["end"]
            prev["text"] = prev["text"] + " " + seg["text"]
    return merged


def estimate_age_group(y_16k, sr=16000):
    try:
        f0, _, _ = librosa.pyin(y_16k, fmin=50, fmax=500, sr=sr, frame_length=2048, hop_length=256)
        f0v = f0[~np.isnan(f0)]
        if f0v.size < 20:
            return "unknown", None
        med = float(np.median(f0v))
        if med >= 250:
            return "child", med
        if 180 <= med < 250:
            return "young_adult", med
        if 120 <= med < 180:
            return "adult", med
        return "senior", med
    except Exception:
        return "unknown", None


def concat_speaker_audio(full_y, sr, speaker_rows, max_total_sec=25.0):
    clips = []
    total = 0.0
    for r in speaker_rows:
        st, en = float(r["start"]), float(r["end"])
        if en <= st:
            continue
        a, b = int(st * sr), min(int(en * sr), len(full_y))
        if a >= len(full_y):
            continue
        clip = full_y[a:b]
        if clip.size == 0:
            continue
        clips.append(clip)
        total += (b - a) / sr
        if total >= max_total_sec:
            break
    if total < 1.2:
        return None
    return np.concatenate(clips)


def analyze_audio(audio_path, min_speakers=None, max_speakers=None):
    # 1. Transcribe
    whisper_model = get_whisper_model()
    whisper_segments, info = whisper_model.transcribe(audio_path, vad_filter=True)
    segments = [
        {"start": float(s.start), "end": float(s.end), "text": s.text.strip()}
        for s in whisper_segments
    ]

    language = getattr(info, "language", "en") or "en"

    # 2. Align
    if segments:
        try:
            align_model, align_metadata = get_align_model(language)
            if align_model is not None:
                audio_array = whisperx.load_audio(audio_path)
                aligned = whisperx.align(segments, align_model, align_metadata, audio_array, DEVICE)
                aligned_segs = aligned.get("segments", [])
                if aligned_segs:
                    raw = [
                        {"start": float(s["start"]), "end": float(s["end"]), "text": s.get("text", "").strip()}
                        for s in aligned_segs if s.get("text", "").strip()
                    ]
                    segments = merge_segments(raw)
        except Exception as e:
            print(f"Alignment failed: {e}")

    # 3. Diarize
    diar_rows = []
    try:
        diarize = get_diarize_pipeline()
        if diarize is not None:
            kwargs = {}
            if min_speakers is not None:
                kwargs["min_speakers"] = min_speakers
            if max_speakers is not None:
                kwargs["max_speakers"] = max_speakers
            diarization_df = diarize(audio_path, **kwargs)
            diar_rows = diarization_df.to_dict("records")
    except Exception as e:
        print(f"Diarization failed: {e}")

    # 4. Assign speakers
    final_segments = []
    if diar_rows:
        for seg in segments:
            seg_start, seg_end = float(seg["start"]), float(seg["end"])
            best_speaker, best_overlap = "SPEAKER_0", 0.0
            for row in diar_rows:
                overlap = max(0.0, min(seg_end, float(row["end"])) - max(seg_start, float(row["start"])))
                if overlap > best_overlap:
                    best_overlap = overlap
                    best_speaker = str(row["speaker"])
            if best_overlap == 0.0:
                seg_mid = (seg_start + seg_end) / 2
                min_dist = float("inf")
                for row in diar_rows:
                    dist = abs(seg_mid - (float(row["start"]) + float(row["end"])) / 2)
                    if dist < min_dist:
                        min_dist = dist
                        best_speaker = str(row["speaker"])
            final_segments.append({**seg, "speaker": best_speaker})
    else:
        for seg in segments:
            final_segments.append({**seg, "speaker": "SPEAKER_0"})

    # 5. Speaker metadata (gender from pitch, age group)
    speakers = {}
    if diar_rows:
        full_y, sr = librosa.load(audio_path, sr=16000, mono=True)
        full_y = full_y.astype(np.float32)

        by_spk = {}
        for r in diar_rows:
            spk = str(r.get("speaker", "SPEAKER_0"))
            by_spk.setdefault(spk, []).append({"start": float(r["start"]), "end": float(r["end"])})

        for spk, rows in by_spk.items():
            y_spk = concat_speaker_audio(full_y, 16000, rows)
            if y_spk is None:
                speakers[spk] = {"gender": "unknown", "age_group": "unknown", "pitch_median_hz": None}
                continue
            age_group, pitch_med = estimate_age_group(y_spk)
            # Infer gender from pitch
            gender = "unknown"
            if pitch_med:
                if pitch_med >= 250:
                    gender = "child"
                elif pitch_med >= 165:
                    gender = "female"
                else:
                    gender = "male"
            speakers[spk] = {"gender": gender, "age_group": age_group, "pitch_median_hz": pitch_med}
    else:
        speakers["SPEAKER_0"] = {"gender": "unknown", "age_group": "unknown", "pitch_median_hz": None}

    return {
        "language": language,
        "segments": final_segments,
        "speakers": speakers,
        "speakers_detected": len(speakers),
    }


def handler(job):
    job_input = job["input"]
    audio_url = job_input.get("audio_url")
    min_speakers = job_input.get("min_speakers")
    max_speakers = job_input.get("max_speakers")

    if not audio_url:
        return {"error": "audio_url is required"}

    tmp_path = None
    try:
        # Download audio
        runpod.serverless.progress_update(job, "Downloading audio...")
        tmp_fd, tmp_path = tempfile.mkstemp(suffix=".wav")
        os.close(tmp_fd)
        urllib.request.urlretrieve(audio_url, tmp_path)

        if os.path.getsize(tmp_path) < 1000:
            return {"error": "Audio file too small"}

        # Analyze
        runpod.serverless.progress_update(job, "Transcribing and analyzing...")
        result = analyze_audio(tmp_path, min_speakers=min_speakers, max_speakers=max_speakers)
        return result

    except Exception as e:
        traceback.print_exc()
        return {"error": str(e)}

    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.remove(tmp_path)


runpod.serverless.start({"handler": handler})
