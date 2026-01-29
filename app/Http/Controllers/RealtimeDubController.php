<?php

namespace App\Http\Controllers;

use App\Contracts\TtsDriverInterface;
use App\Models\Video;
use App\Services\Tts\TtsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RealtimeDubController extends Controller
{
    public function __construct(
        protected TtsManager $ttsManager
    ) {}

    /**
     * Initialize a real-time dubbing session for a URL.
     */
    public function initSession(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'target_language' => 'required|string|max:10',
            'tts_driver' => 'nullable|string',
            'auto_clone' => 'nullable|boolean',
        ]);

        $sessionId = Str::uuid()->toString();
        $url = $request->input('url');
        $targetLanguage = $request->input('target_language', 'uz');
        $ttsDriver = $request->input('tts_driver', config('dubber.tts.default'));
        $autoClone = $request->boolean('auto_clone', true);

        // Create session record
        $session = [
            'id' => $sessionId,
            'url' => $url,
            'target_language' => $targetLanguage,
            'tts_driver' => $ttsDriver,
            'auto_clone' => $autoClone,
            'status' => 'initializing',
            'created_at' => now()->toIso8601String(),
        ];

        // Store session in cache
        cache()->put("dub_session:{$sessionId}", $session, now()->addHours(2));

        // Start background processing to fetch and analyze the video
        // This would typically dispatch a job, but for real-time we'll return immediately
        // and let the client poll or use WebSocket for updates

        return response()->json([
            'session_id' => $sessionId,
            'status' => 'initializing',
            'message' => 'Dubbing session created',
        ]);
    }

    /**
     * Process audio chunk for real-time dubbing.
     */
    public function processChunk(Request $request, string $sessionId): JsonResponse
    {
        $session = cache()->get("dub_session:{$sessionId}");

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $request->validate([
            'audio' => 'required|string', // Base64 encoded audio
            'timestamp' => 'required|numeric',
            'duration' => 'required|numeric',
        ]);

        $audioBase64 = $request->input('audio');
        $timestamp = $request->input('timestamp');
        $duration = $request->input('duration');

        // Decode audio
        $audioData = base64_decode($audioBase64);
        $tempPath = storage_path("app/temp/chunk_{$sessionId}_{$timestamp}.wav");
        @mkdir(dirname($tempPath), 0777, true);
        file_put_contents($tempPath, $audioData);

        try {
            // Send to WhisperX for transcription
            $transcription = $this->transcribeChunk($tempPath);

            if (empty($transcription['text'])) {
                return response()->json([
                    'status' => 'no_speech',
                    'timestamp' => $timestamp,
                ]);
            }

            // Translate if needed
            $translated = $this->translateText(
                $transcription['text'],
                $session['target_language']
            );

            // Generate TTS
            $driver = $this->ttsManager->driver($session['tts_driver']);
            $ttsAudioPath = $this->generateTts($driver, $translated, $session);

            // Read and encode the TTS audio
            $ttsAudio = base64_encode(file_get_contents($ttsAudioPath));

            return response()->json([
                'status' => 'success',
                'timestamp' => $timestamp,
                'original_text' => $transcription['text'],
                'translated_text' => $translated,
                'speaker' => $transcription['speaker'] ?? null,
                'audio' => $ttsAudio,
                'duration' => $this->getAudioDuration($ttsAudioPath),
            ]);

        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Get available voices for a language.
     */
    public function getVoices(Request $request): JsonResponse
    {
        $language = $request->input('language', 'uz');
        $voices = [];

        foreach ($this->ttsManager->getDrivers() as $name => $driver) {
            $voices[$name] = [
                'name' => $name,
                'supports_cloning' => $driver->supportsVoiceCloning(),
                'supports_emotions' => $driver->supportsEmotions(),
                'cost_per_char' => $driver->getCostPerCharacter(),
                'voices' => $driver->getVoices($language),
            ];
        }

        return response()->json(['voices' => $voices]);
    }

    /**
     * Clone a voice for a session.
     */
    public function cloneVoice(Request $request, string $sessionId): JsonResponse
    {
        $session = cache()->get("dub_session:{$sessionId}");

        if (!$session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $request->validate([
            'audio' => 'required|string', // Base64 audio sample
            'name' => 'required|string|max:100',
            'speaker_id' => 'nullable|string',
        ]);

        $audioData = base64_decode($request->input('audio'));
        $name = $request->input('name');

        $tempPath = storage_path("app/temp/voice_sample_{$sessionId}.wav");
        file_put_contents($tempPath, $audioData);

        try {
            $driver = $this->ttsManager->driver($session['tts_driver']);

            if (!$driver->supportsVoiceCloning()) {
                return response()->json([
                    'error' => 'Selected TTS driver does not support voice cloning'
                ], 400);
            }

            $voiceId = $driver->cloneVoice($tempPath, $name, [
                'language' => $session['target_language'],
            ]);

            return response()->json([
                'success' => true,
                'voice_id' => $voiceId,
                'name' => $name,
            ]);

        } finally {
            @unlink($tempPath);
        }
    }

    protected function transcribeChunk(string $audioPath): array
    {
        // Use WhisperX for transcription
        $audioRel = str_replace(storage_path('app/'), '', $audioPath);

        try {
            $response = Http::timeout(30)
                ->post('http://whisperx:8000/analyze', [
                    'audio_path' => $audioRel,
                ]);

            if ($response->failed()) {
                Log::warning('WhisperX chunk transcription failed', [
                    'status' => $response->status(),
                ]);
                return ['text' => '', 'speaker' => null];
            }

            $data = $response->json();
            $segments = $data['segments'] ?? [];

            // Combine all segment texts
            $text = collect($segments)
                ->pluck('text')
                ->filter()
                ->join(' ');

            $speaker = $segments[0]['speaker'] ?? null;

            return [
                'text' => trim($text),
                'speaker' => $speaker,
            ];

        } catch (\Throwable $e) {
            Log::error('Transcription error', ['error' => $e->getMessage()]);
            return ['text' => '', 'speaker' => null];
        }
    }

    protected function translateText(string $text, string $targetLanguage): string
    {
        if (empty($text)) return '';

        $apiKey = config('services.openai.key');
        if (!$apiKey) {
            return $text; // Return original if no API key
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "Translate to {$targetLanguage}. Output ONLY the translation, nothing else. Keep it natural for spoken dialogue."
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if ($response->failed()) {
                return $text;
            }

            return trim($response->json('choices.0.message.content') ?? $text);

        } catch (\Throwable $e) {
            Log::error('Translation error', ['error' => $e->getMessage()]);
            return $text;
        }
    }

    protected function generateTts(TtsDriverInterface $driver, string $text, array $session): string
    {
        // Create a minimal speaker/segment for TTS
        $tempDir = storage_path('app/temp/tts');
        @mkdir($tempDir, 0777, true);

        $outputPath = "{$tempDir}/" . Str::uuid() . '.wav';

        // For real-time, we use a simplified synthesis
        // The driver's synthesize method expects Speaker and VideoSegment models
        // So we'll call the underlying service directly for real-time use

        if ($driver->name() === 'xtts') {
            $response = Http::timeout(60)
                ->post(config('services.xtts.url') . '/synthesize', [
                    'text' => $text,
                    'voice_id' => $session['voice_id'] ?? 'default',
                    'language' => $session['target_language'],
                    'emotion' => 'neutral',
                    'speed' => 1.0,
                    'output_path' => 'temp/tts/' . basename($outputPath),
                ]);

            if ($response->successful()) {
                return $outputPath;
            }
        }

        // Fallback to Edge TTS
        $tmpTxt = "/tmp/tts_realtime_" . Str::random(8) . ".txt";
        file_put_contents($tmpTxt, $text);

        $cmd = sprintf(
            'edge-tts -f %s --voice uz-UZ-SardorNeural --write-media %s 2>&1',
            escapeshellarg($tmpTxt),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $code);
        @unlink($tmpTxt);

        if ($code !== 0 || !file_exists($outputPath)) {
            throw new \RuntimeException('TTS generation failed');
        }

        return $outputPath;
    }

    protected function getAudioDuration(string $path): float
    {
        $cmd = sprintf(
            'ffprobe -hide_banner -loglevel error -show_entries format=duration -of default=nw=1:nk=1 %s',
            escapeshellarg($path)
        );

        $output = shell_exec($cmd);
        return (float) trim($output ?: '0');
    }
}
