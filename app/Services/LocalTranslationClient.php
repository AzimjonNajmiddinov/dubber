<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LocalTranslationClient
{
    public function enabled(): bool
    {
        return (bool) config('services.local_translation.enabled')
            && (string) config('services.local_translation.url') !== '';
    }

    public function allowPaidFallback(): bool
    {
        return (bool) config('services.local_translation.allow_paid_fallback', false);
    }

    public function chat(array $messages, int $timeout = 90, int $maxTokens = 4096): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $driver = (string) config('services.local_translation.driver', 'ollama');
        $url = rtrim((string) config('services.local_translation.url'), '/');
        $model = (string) config('services.local_translation.model', 'qwen2.5:7b-instruct');

        return match ($driver) {
            'openai_compatible', 'lmstudio' => $this->chatOpenAiCompatible($url, $model, $messages, $timeout, $maxTokens),
            default => $this->chatOllama($url, $model, $messages, $timeout, $maxTokens),
        };
    }

    private function chatOllama(string $url, string $model, array $messages, int $timeout, int $maxTokens): ?string
    {
        try {
            $response = Http::timeout($timeout)->post("{$url}/api/chat", [
                'model' => $model,
                'stream' => false,
                'messages' => $messages,
                'options' => [
                    'temperature' => 0.2,
                    'num_predict' => $maxTokens,
                ],
            ]);

            if ($response->successful()) {
                $content = trim((string) $response->json('message.content'));
                return $content !== '' ? $content : null;
            }

            Log::warning('[DUB] Local Ollama translation failed', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DUB] Local Ollama translation exception: ' . $e->getMessage());
        }

        return null;
    }

    private function chatOpenAiCompatible(string $url, string $model, array $messages, int $timeout, int $maxTokens): ?string
    {
        $endpoint = str_ends_with($url, '/v1')
            ? "{$url}/chat/completions"
            : "{$url}/v1/chat/completions";

        try {
            $response = Http::timeout($timeout)->post($endpoint, [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ]);

            if ($response->successful()) {
                $content = trim((string) $response->json('choices.0.message.content'));
                return $content !== '' ? $content : null;
            }

            Log::warning('[DUB] Local OpenAI-compatible translation failed', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 300),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[DUB] Local OpenAI-compatible translation exception: ' . $e->getMessage());
        }

        return null;
    }
}
