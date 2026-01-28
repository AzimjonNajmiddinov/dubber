# Movie Dubber

AI-powered video dubbing application that automatically translates and dubs videos into different languages with natural-sounding voices.

## Features

- **Automatic Speech Recognition** - Transcribes video audio using WhisperX
- **Speaker Diarization** - Detects and separates multiple speakers
- **Gender Recognition** - Automatically detects speaker gender for voice matching
- **AI Translation** - Translates transcripts using GPT-4
- **Text-to-Speech** - Generates dubbed audio using Edge TTS with emotion support
- **Audio Stem Separation** - Separates vocals from background music/effects using Demucs
- **Professional Audio Mixing** - Blends dubbed voice with original background
- **Lip Sync** (Optional) - Synchronizes lip movements with dubbed audio using Wav2Lip
- **Web Interface** - Easy-to-use web UI for uploading and managing videos

## Supported Languages

- **Target Languages**: Uzbek, Russian, English (more can be added)
- **Source Languages**: Any language supported by Whisper

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Upload    │────▶│  WhisperX   │────▶│   Demucs    │
│   Video     │     │ Transcribe  │     │   Stems     │
└─────────────┘     └─────────────┘     └─────────────┘
                           │                    │
                           ▼                    ▼
                    ┌─────────────┐     ┌─────────────┐
                    │   GPT-4     │     │  Mix Audio  │
                    │  Translate  │     │  (TTS+Bed)  │
                    └─────────────┘     └─────────────┘
                           │                    │
                           ▼                    ▼
                    ┌─────────────┐     ┌─────────────┐
                    │  Edge TTS   │────▶│  Final MP4  │
                    │  Generate   │     │   Output    │
                    └─────────────┘     └─────────────┘
```

## Requirements

- Docker & Docker Compose
- 8GB+ RAM (16GB recommended)
- Hugging Face account (for speaker diarization models)
- OpenAI API key (for translation)

## Quick Start

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/movie-dubber.git
   cd movie-dubber
   ```

2. **Configure environment**
   ```bash
   cp .env.example .env
   ```

   Edit `.env` and add your API keys:
   - `HF_TOKEN` - Get from https://huggingface.co/settings/tokens
   - `OPENAI_API_KEY` - Get from https://platform.openai.com/api-keys

3. **Accept Hugging Face model terms**

   Visit and accept the terms for these models:
   - https://huggingface.co/pyannote/speaker-diarization-3.1
   - https://huggingface.co/pyannote/segmentation-3.0

4. **Start the application**
   ```bash
   docker compose up -d
   ```

5. **Run database migrations**
   ```bash
   docker compose exec app php artisan migrate
   ```

6. **Generate app key**
   ```bash
   docker compose exec app php artisan key:generate
   ```

7. **Access the application**

   Open http://localhost:8080 in your browser

## Configuration

### Speaker Voice Settings

After uploading a video, you can customize TTS voices for each detected speaker:
- Voice selection (Uzbek, Russian, English voices)
- Speech rate adjustment
- Pitch adjustment
- Emotion override (happy, sad, angry, etc.)

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `HF_TOKEN` | Hugging Face API token | Yes |
| `OPENAI_API_KEY` | OpenAI API key for translation | Yes |
| `DB_*` | Database configuration | Yes |
| `REDIS_*` | Redis configuration | Yes |

## Services

| Service | Port | Description |
|---------|------|-------------|
| nginx | 8080 | Web server |
| whisperx | 8001 | Speech recognition & diarization |
| demucs | 8002 | Audio stem separation |
| lipsync | 8003 | Lip synchronization (Wav2Lip) |

## Development

### Running Queue Worker

The queue worker processes video dubbing jobs:
```bash
docker compose exec queue php artisan queue:work
```

### Viewing Logs

```bash
# Application logs
docker compose logs -f app

# Queue worker logs
docker compose logs -f queue

# WhisperX logs
docker compose logs -f whisperx
```

### Rebuilding Services

```bash
# Rebuild all services
docker compose build

# Rebuild specific service
docker compose build whisperx
```

## Troubleshooting

### Out of Memory

If services crash with OOM errors, try:
- Reduce `mem_limit` in docker-compose.yml
- Process shorter videos
- Ensure no other memory-intensive apps are running

### Speaker Detection Issues

If speakers are incorrectly assigned:
- Ensure audio quality is good
- Check that HF_TOKEN has access to pyannote models
- Review whisperx logs for diarization output

## License

MIT License

## Acknowledgments

- [WhisperX](https://github.com/m-bain/whisperX) - Speech recognition
- [Demucs](https://github.com/facebookresearch/demucs) - Audio source separation
- [Wav2Lip](https://github.com/Rudrabha/Wav2Lip) - Lip synchronization
- [Edge TTS](https://github.com/rany2/edge-tts) - Text-to-speech
- [pyannote.audio](https://github.com/pyannote/pyannote-audio) - Speaker diarization
