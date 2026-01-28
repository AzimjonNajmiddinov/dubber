from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import logging

import os

# Configure logging to show in uvicorn
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)
import traceback
import threading
from typing import Optional, Dict, Any, Tuple

import numpy as np
import soundfile as sf
import librosa
import torch

# Heavy imports are kept, but model construction is lazy.
from faster_whisper import WhisperModel
from whisperx.diarize import DiarizationPipeline
from transformers import AutoFeatureExtractor, Wav2Vec2ForSequenceClassification
from speechbrain.inference.classifiers import EncoderClassifier

# ---------------- CONFIG ----------------
DEVICE = os.environ.get("DEVICE", "cpu")
BASE_DIR = "/var/www/storage/app"
HF_TOKEN = os.environ.get("HF_TOKEN")
if not HF_TOKEN:
    raise RuntimeError("HF_TOKEN is required")

# You can override these via env if you want
WHISPER_MODEL_NAME = os.environ.get("WHISPER_MODEL", "base")
WHISPER_COMPUTE_TYPE = os.environ.get("WHISPER_COMPUTE_TYPE", "int8")
DIARIZATION_MODEL = os.environ.get("DIARIZATION_MODEL", "pyannote/speaker-diarization-3.1")
GENDER_MODEL_ID = os.environ.get(
    "GENDER_MODEL_ID",
    "alefiury/wav2vec2-large-xlsr-53-gender-recognition-librispeech",
)
EMOTION_MODEL_ID = os.environ.get(
    "EMOTION_MODEL_ID",
    "speechbrain/emotion-recognition-wav2vec2-IEMOCAP",
)

# Optional toggles (to reduce load if needed)
ENABLE_DIARIZATION = os.environ.get("ENABLE_DIARIZATION", "1") == "1"
ENABLE_GENDER = os.environ.get("ENABLE_GENDER", "1") == "1"
ENABLE_EMOTION = os.environ.get("ENABLE_EMOTION", "1") == "1"

app = FastAPI()

# ---------------- STATE (lazy singletons) ----------------
_state_lock = threading.Lock()

_whisper_model: Optional[WhisperModel] = None
_diarize_pipeline: Optional[DiarizationPipeline] = None

_gender_ok: bool = False
_gender_extractor: Optional[Any] = None
_gender_model: Optional[Any] = None

_emotion_ok: bool = False
_emotion_clf: Optional[Any] = None


# ---------------- SCHEMA ----------------
class AnalyzeRequest(BaseModel):
    audio_path: str


# ---------------- HELPERS ----------------
def resolve_audio_path(p: str) -> str:
    if not p:
        raise HTTPException(status_code=400, detail="audio_path is required")

    if os.path.isabs(p):
        audio_path = os.path.abspath(p)
    else:
        audio_path = os.path.abspath(os.path.join(BASE_DIR, p))

    if not audio_path.startswith(os.path.abspath(BASE_DIR)):
        raise HTTPException(status_code=400, detail="Invalid path")

    if not os.path.exists(audio_path):
        raise HTTPException(status_code=404, detail="File not found")

    return audio_path


def load_audio_mono_16k(path: str):
    y, sr = librosa.load(path, sr=16000, mono=True)
    return y.astype(np.float32), 16000


def concat_speaker_audio(full_y, sr, speaker_rows, min_total_sec=1.2, max_total_sec=25.0):
    clips = []
    total = 0.0
    for r in speaker_rows:
        st = float(r["start"])
        en = float(r["end"])
        if en <= st:
            continue
        a = int(st * sr)
        b = int(en * sr)
        if a >= len(full_y):
            continue
        b = min(b, len(full_y))
        clip = full_y[a:b]
        if clip.size == 0:
            continue
        clips.append(clip)
        total += (b - a) / sr
        if total >= max_total_sec:
            break
    if total < min_total_sec:
        return None
    return np.concatenate(clips)


def estimate_age_group_from_pitch(y_16k: np.ndarray, sr: int = 16000):
    try:
        f0, voiced_flag, voiced_prob = librosa.pyin(
            y_16k, fmin=50, fmax=500, sr=sr, frame_length=2048, hop_length=256
        )
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


# ---------------- LAZY MODEL INIT ----------------
def get_whisper_model() -> WhisperModel:
    global _whisper_model
    if _whisper_model is not None:
        return _whisper_model
    with _state_lock:
        if _whisper_model is None:
            _whisper_model = WhisperModel(
                WHISPER_MODEL_NAME,
                device=DEVICE,
                compute_type=WHISPER_COMPUTE_TYPE,
            )
    return _whisper_model


def get_diarize_pipeline() -> Optional[DiarizationPipeline]:
    global _diarize_pipeline
    if not ENABLE_DIARIZATION:
        return None
    if _diarize_pipeline is not None:
        return _diarize_pipeline
    with _state_lock:
        if _diarize_pipeline is None:
            _diarize_pipeline = DiarizationPipeline(
                model_name=DIARIZATION_MODEL,
                use_auth_token=HF_TOKEN,
                device=DEVICE,
            )
    return _diarize_pipeline


def init_gender_model_if_needed() -> Tuple[bool, Optional[str]]:
    global _gender_ok, _gender_extractor, _gender_model
    if not ENABLE_GENDER:
        _gender_ok = False
        return False, "disabled"

    if _gender_ok and _gender_extractor is not None and _gender_model is not None:
        return True, None

    with _state_lock:
        if _gender_ok and _gender_extractor is not None and _gender_model is not None:
            return True, None
        try:
            _gender_extractor = AutoFeatureExtractor.from_pretrained(GENDER_MODEL_ID)
            _gender_model = Wav2Vec2ForSequenceClassification.from_pretrained(GENDER_MODEL_ID)
            _gender_model.eval()
            _gender_ok = True
            return True, None
        except Exception as e:
            _gender_ok = False
            _gender_extractor = None
            _gender_model = None
            return False, str(e)


def init_emotion_model_if_needed() -> Tuple[bool, Optional[str]]:
    global _emotion_ok, _emotion_clf
    if not ENABLE_EMOTION:
        _emotion_ok = False
        return False, "disabled"

    if _emotion_ok and _emotion_clf is not None:
        return True, None

    with _state_lock:
        if _emotion_ok and _emotion_clf is not None:
            return True, None
        try:
            _emotion_clf = EncoderClassifier.from_hparams(
                source=EMOTION_MODEL_ID,
                run_opts={"device": DEVICE},
            )
            _emotion_ok = True
            return True, None
        except Exception as e:
            _emotion_ok = False
            _emotion_clf = None
            return False, str(e)


def predict_gender(y_16k: np.ndarray, sr: int = 16000):
    ok, err = init_gender_model_if_needed()
    if not ok:
        return "unknown", None

    try:
        inputs = _gender_extractor(y_16k, sampling_rate=sr, return_tensors="pt", padding=True)
        with torch.no_grad():
            logits = _gender_model(**inputs).logits
            probs = torch.softmax(logits, dim=-1).cpu().numpy()[0]
        best = int(np.argmax(probs))
        conf = float(probs[best])

        id2label = getattr(_gender_model.config, "id2label", {}) or {}
        label = str(id2label.get(best, "unknown")).lower()

        if "female" in label or label in ("f", "woman"):
            return "female", conf
        if "male" in label or label in ("m", "man"):
            return "male", conf

        # fallback
        if best == 0:
            return "female", conf
        if best == 1:
            return "male", conf

        return "unknown", conf
    except Exception:
        return "unknown", None


def predict_emotion(tmp_wav_path: str):
    ok, err = init_emotion_model_if_needed()
    if not ok:
        return "neutral", None

    try:
        out_prob, score, index, text_lab = _emotion_clf.classify_file(tmp_wav_path)
        lab = text_lab[0] if isinstance(text_lab, (list, tuple)) and len(text_lab) else str(text_lab)
        lab = str(lab).lower()

        mapping = {
            "neu": "neutral",
            "hap": "happy",
            "sad": "sad",
            "ang": "angry",
            "fea": "fear",
            "exc": "excited",
            "fru": "frustration",
            "sur": "surprise",
        }
        emotion = mapping.get(lab, lab)

        conf = None
        try:
            if hasattr(out_prob, "max"):
                conf = float(out_prob.max().item())
        except Exception:
            conf = None

        return emotion, conf
    except Exception:
        return "neutral", None


# Optional: prevent concurrent heavy work (useful for CPU + limited RAM)
_analyze_lock = threading.Lock()


@app.get("/health")
def health():
    # Lightweight: do NOT trigger heavy model loads.
    return {
        "ok": True,
        "device": DEVICE,
        "enable_diarization": ENABLE_DIARIZATION,
        "enable_gender": ENABLE_GENDER,
        "enable_emotion": ENABLE_EMOTION,
        "whisper_loaded": _whisper_model is not None,
        "diarize_loaded": _diarize_pipeline is not None,
        "gender_loaded": _gender_ok,
        "emotion_loaded": _emotion_ok,
    }


@app.get("/ready")
def ready():
    # Heavier readiness: ensures core models can load.
    # Use this only if you want "warm" readiness checks.
    try:
        get_whisper_model()
        if ENABLE_DIARIZATION:
            get_diarize_pipeline()
        if ENABLE_GENDER:
            init_gender_model_if_needed()
        if ENABLE_EMOTION:
            init_emotion_model_if_needed()
        return {"ok": True}
    except Exception as e:
        return {"ok": False, "error": str(e)}


@app.post("/analyze")
def analyze(req: AnalyzeRequest):
    # Lock prevents multiple jobs from loading models simultaneously (and spiking RAM).
    with _analyze_lock:
        try:
            audio_path = resolve_audio_path(req.audio_path)

            # 1) TRANSCRIBE
            whisper_model = get_whisper_model()
            whisper_segments, info = whisper_model.transcribe(audio_path, vad_filter=True)
            segments = [
                {"start": float(s.start), "end": float(s.end), "text": s.text.strip()}
                for s in whisper_segments
            ]

            # 2) DIARIZE (optional)
            diar_rows = []
            if ENABLE_DIARIZATION:
                diarize = get_diarize_pipeline()
                diarization_df = diarize(audio_path)
                diar_rows = diarization_df.to_dict("records")

                # Debug: log diarization results
                print(f"[DIARIZE] Found {len(diar_rows)} speaker segments", flush=True)
                for i, row in enumerate(diar_rows[:30]):  # limit to 30 for readability
                    print(f"  Diar[{i}]: {row['start']:.2f}-{row['end']:.2f} -> {row['speaker']}", flush=True)

            # 3) Assign speaker to each segment using overlap-based matching
            final_segments = []
            print(f"[WHISPER] Found {len(segments)} transcription segments", flush=True)
            for i, seg in enumerate(segments[:30]):
                print(f"  Seg[{i}]: {seg['start']:.2f}-{seg['end']:.2f} text='{seg['text'][:50]}...'", flush=True)

            if diar_rows:
                print(f"[ASSIGN] Assigning speakers to {len(segments)} transcription segments", flush=True)
                for seg in segments:
                    seg_start = float(seg["start"])
                    seg_end = float(seg["end"])

                    # Find speaker with maximum overlap
                    best_speaker = "SPEAKER_UNKNOWN"
                    best_overlap = 0.0

                    for row in diar_rows:
                        row_start = float(row["start"])
                        row_end = float(row["end"])

                        # Calculate overlap
                        overlap_start = max(seg_start, row_start)
                        overlap_end = min(seg_end, row_end)
                        overlap = max(0.0, overlap_end - overlap_start)

                        if overlap > best_overlap:
                            best_overlap = overlap
                            best_speaker = str(row["speaker"])

                    # Fallback: if no overlap found, use closest speaker by midpoint
                    if best_overlap == 0.0:
                        seg_mid = (seg_start + seg_end) / 2
                        min_dist = float("inf")
                        for row in diar_rows:
                            row_mid = (float(row["start"]) + float(row["end"])) / 2
                            dist = abs(seg_mid - row_mid)
                            if dist < min_dist:
                                min_dist = dist
                                best_speaker = str(row["speaker"])
                        print(f"  [ASSIGN] Seg[{seg_start:.2f}-{seg_end:.2f}] NO OVERLAP, closest={best_speaker}, min_dist={min_dist:.2f}s", flush=True)
                    else:
                        print(f"  [ASSIGN] Seg[{seg_start:.2f}-{seg_end:.2f}] overlap={best_overlap:.2f}s -> {best_speaker}", flush=True)

                    final_segments.append({**seg, "speaker": best_speaker})
            else:
                print("[ASSIGN] No diarization rows - assigning all to SPEAKER_0", flush=True)
                for seg in segments:
                    final_segments.append({**seg, "speaker": "SPEAKER_0"})

            # 4) Speaker-level meta (optional / safe defaults)
            speakers: Dict[str, Dict[str, Any]] = {}
            if diar_rows:
                full_y, sr = load_audio_mono_16k(audio_path)

                by_spk: Dict[str, list] = {}
                for r in diar_rows:
                    spk = str(r.get("speaker", "SPEAKER_UNKNOWN"))
                    by_spk.setdefault(spk, []).append({
                        "start": float(r["start"]),
                        "end": float(r["end"]),
                    })

                for spk, rows in by_spk.items():
                    y_spk = concat_speaker_audio(full_y, sr, rows)
                    if y_spk is None:
                        speakers[spk] = {
                            "gender": "unknown",
                            "gender_confidence": None,
                            "age_group": "unknown",
                            "pitch_median_hz": None,
                            "emotion": "neutral",
                            "emotion_confidence": None,
                        }
                        continue

                    gender, gconf = predict_gender(y_spk, sr=sr) if ENABLE_GENDER else ("unknown", None)
                    age_group, pitch_med = estimate_age_group_from_pitch(y_spk, sr=sr)

                    emotion, econf = ("neutral", None)
                    if ENABLE_EMOTION:
                        tmp_path = f"/tmp/spk_{spk.replace('/', '_')}.wav"
                        sf.write(tmp_path, y_spk, sr)
                        emotion, econf = predict_emotion(tmp_path)
                        try:
                            os.remove(tmp_path)
                        except Exception:
                            pass

                    speakers[spk] = {
                        "gender": gender,
                        "gender_confidence": gconf,
                        "age_group": age_group,
                        "pitch_median_hz": pitch_med,
                        "emotion": emotion,
                        "emotion_confidence": econf,
                    }
            else:
                speakers["SPEAKER_0"] = {
                    "gender": "unknown",
                    "gender_confidence": None,
                    "age_group": "unknown",
                    "pitch_median_hz": None,
                    "emotion": "neutral",
                    "emotion_confidence": None,
                }

            return {
                "language": getattr(info, "language", None),
                "segments": final_segments,
                "speakers": speakers,
            }

        except HTTPException:
            raise
        except Exception as e:
            traceback.print_exc()
            return {"error": "whisperx_internal_error", "message": str(e)}
