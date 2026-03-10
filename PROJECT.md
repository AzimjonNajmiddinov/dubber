# Dubber — Video Dubbing Platform

## What It Does

Dubber is a video dubbing/localization platform. It takes videos, transcribes speech, translates to a target language, generates dubbed audio with TTS, and mixes it back. It supports real-time HLS streaming of dubbed content.

### Main Features
- **Video Upload & Dubbing** — upload video, auto-transcribe (WhisperX), translate, TTS, mix
- **Instant Dub** — paste SRT + video URL → immediate TTS playback via HLS (no transcription needed)
- **Stream Dub** — dub a video from URL (API-based)
- **Speaker Management** — identify speakers, assign voices, clone voices
- **HLS Streaming** — progressive playback while dubbing is still in progress

---

## Architecture

### Stack
- **Backend:** Laravel (PHP 8.4) on Docker
- **Queue:** Redis + Laravel Horizon
- **Database:** MySQL 8.0
- **Frontend:** Blade + Tailwind CSS + Vite
- **GPU Services:** WhisperX, Demucs, OpenVoice (on RunPod)

### Docker Services (local)
| Service | Container | Port |
|---------|-----------|------|
| App | movie_dubber_app | 9000 (FPM) |
| Horizon | movie_dubber_horizon | — |
| Nginx | movie_dubber_nginx | 8080 |
| Redis | movie_dubber_redis | 6380 |
| MySQL | movie_dubber_mysql | 3307 |

### Docker Services (production — dubbing.uz)
Same services at `/var/www/dubber`, Docker Compose.

### GPU Services (RunPod)
| Service | Port | Purpose |
|---------|------|---------|
| WhisperX | 8002 | Speech-to-text + speaker diarization |
| Demucs | 8000 | Vocal/music stem separation |
| OpenVoice | 8005 | Voice cloning & conversion |

---

## TTS Drivers

Configured via `TTS_DRIVER` env var (config: `dubber.tts.default`).

| Driver | Description | Voice Cloning |
|--------|-------------|---------------|
| `edge` | Microsoft Edge TTS (free) | No |
| `aisha` | Aisha.group API (Uzbek) | No |
| `elevenlabs` | ElevenLabs API (multilingual) | Yes (instant clone) |
| `uzbekvoice` | uzbekvoice.ai API | No |
| `hybrid_uzbek` | Edge + EmotionDSP + OpenVoice | Yes (OpenVoice) |

---

## Key File Locations

### Config
| File | Purpose |
|------|---------|
| `config/dubber.php` | TTS driver, voices, emotion presets |
| `config/services.php` | API keys (OpenAI, Anthropic, ElevenLabs, etc.) |
| `.env` | Environment variables |

### Controllers
| Controller | Purpose |
|------------|---------|
| `VideoController` | Video upload, speaker management, download |
| `InstantDubController` | Instant dub sessions, HLS endpoints |
| `StreamDubController` | URL-based dubbing API |
| `OnlineDubController` | Chunked upload dubbing |
| `SegmentPlayerController` | Segment-by-segment HLS player |

### Jobs (Processing Pipeline)
```
DownloadVideoFromUrlJob → ExtractAudioJob → TranscribeWithWhisperXJob
  → RefineSpeakerAssignmentJob → SeparateStemsJob
  → GenerateTtsSegmentsJobV2 → ProcessSegmentTtsJob
  → MixDubbedAudioJob → ReplaceVideoAudioJob
```

### Instant Dub Jobs
```
PrepareInstantDubJob → TranslateInstantDubBatchJob (batches of 15)
                     → ProcessInstantDubSegmentJob (per segment TTS)
```

### Services
| Path | Purpose |
|------|---------|
| `app/Services/Tts/Drivers/` | TTS driver implementations |
| `app/Services/Tts/TtsManager.php` | TTS driver registry |
| `app/Services/ElevenLabs/` | ElevenLabs client + sample extractor |
| `app/Services/TextNormalizer.php` | Text prep for TTS |
| `app/Services/SpeakerTuning.php` | Pitch/rate/gain per speaker |
| `app/Services/ActingDirector.php` | Emotion/delivery analysis |
| `app/Services/SrtParser.php` | SRT subtitle parser |

### Models
| Model | Key Fields |
|-------|------------|
| `Video` | status, target_language, source_url, dubbed_path |
| `Speaker` | gender, tts_voice, tts_driver, voice_profile |
| `VideoSegment` | text, translated_text, start_time, end_time, emotion |

### Storage
```
storage/app/
  audio/stt/{id}.wav          — extracted mono 16kHz audio
  audio/stt/chunks/{id}/      — WhisperX chunks
  audio/tts/{video_id}/       — TTS segment audio
  audio/stems/{video_id}/     — vocals.wav, music.wav
  videos/originals/{id}       — uploaded videos
  videos/dubbed/{id}.mp4      — final dubbed video
  instant-dub/{session}/      — instant dub temp files
```

---

## API Endpoints

### Instant Dub
```
POST /api/instant-dub/start              — start session (srt, video_url, language)
GET  /api/instant-dub/{id}/poll?after=N  — poll for new chunks
GET  /api/instant-dub/{id}/events        — SSE event stream
POST /api/instant-dub/{id}/stop          — stop session

# HLS (for PlayerKit)
GET  /api/instant-dub/{id}/master.m3u8
GET  /api/instant-dub/{id}/dub-audio.m3u8
GET  /api/instant-dub/{id}/dub-segment/{n}.aac
GET  /api/instant-dub/{id}/dub-subtitles.m3u8
GET  /api/instant-dub/{id}/dub-subtitles.vtt
```

### Stream Dub
```
POST /api/stream/dub                     — start dubbing from URL
GET  /api/stream/{video}/status          — check progress
GET  /api/stream/{video}/watch           — stream dubbed video
```

### Video Management
```
GET  /videos/{video}                     — video detail page
GET  /videos/{video}/status              — status JSON
PUT  /videos/{video}/speakers/{id}       — update speaker voice
POST /videos/{video}/regenerate          — regenerate dubbing
```

---

## How to Check / Debug

### Check service status
```bash
# On server
ssh root@dubbing.uz

# App location
cd /var/www/dubber

# Docker status
docker compose ps

# Queue worker logs
docker compose logs horizon --tail=100 -f

# App logs
docker compose exec app tail -f storage/logs/laravel.log

# Redis check
docker compose exec redis redis-cli PING
docker compose exec redis redis-cli DBSIZE

# Check a specific instant-dub session
docker compose exec redis redis-cli GET "instant-dub:{session-id}"
```

### Check instant dub voice map
```bash
docker compose exec redis redis-cli GET "instant-dub:{session-id}:voices"
```

### Reset a video for reprocessing
```bash
docker compose exec app php artisan video:reset {id} --keep-original
docker compose exec redis redis-cli FLUSHALL
docker compose exec app php artisan tinker --execute="App\Jobs\DownloadVideoFromUrlJob::dispatch({id});"
```

### Check TTS driver in use
```bash
docker compose exec app php artisan tinker --execute="echo config('dubber.tts.default');"
```

### Verify ElevenLabs is NOT active (Edge mode)
```bash
docker compose exec app php artisan tinker --execute="echo config('services.elevenlabs.api_key') ?: 'NOT SET';"
```

---

## How to Update on Server

### Standard deploy (git pull)
```bash
ssh root@dubbing.uz
cd /var/www/dubber
git pull
docker compose exec app php artisan config:clear
docker compose restart horizon
```

### If migrations are needed
```bash
ssh root@dubbing.uz
cd /var/www/dubber
git pull
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:clear
docker compose restart horizon
```

### If composer dependencies changed
```bash
ssh root@dubbing.uz
cd /var/www/dubber
git pull
docker compose exec app composer install --no-dev
docker compose exec app php artisan config:clear
docker compose restart horizon
```

### If frontend assets changed
```bash
ssh root@dubbing.uz
cd /var/www/dubber
git pull
docker compose exec app npm install
docker compose exec app npm run build
docker compose exec app php artisan config:clear
docker compose restart horizon
```

### Quick one-liner (most common — code changes only)
```bash
ssh root@dubbing.uz "cd /var/www/dubber && git pull && docker compose exec app php artisan config:clear && docker compose restart horizon"
```

---

## Environment Variables (Key Ones)

```env
# TTS
TTS_DRIVER=edge                    # edge | aisha | elevenlabs | uzbekvoice

# API Keys
OPENAI_API_KEY=                    # GPT-4 translation
ANTHROPIC_API_KEY=                 # Claude translation (faster)
ELEVENLABS_API_KEY=                # Voice cloning (only when TTS_DRIVER=elevenlabs)
AISHA_API_KEY=                     # Aisha TTS
UZBEKVOICE_API_KEY=                # UzbekVoice TTS

# GPU Services (RunPod)
WHISPERX_SERVICE_URL=              # WhisperX endpoint
DEMUCS_SERVICE_URL=                # Demucs endpoint
OPENVOICE_SERVICE_URL=             # OpenVoice endpoint

# Infrastructure
REDIS_HOST=redis
DB_HOST=mysql
QUEUE_CONNECTION=redis
```
