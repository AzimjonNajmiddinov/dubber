<?php

namespace App\Providers;

use App\Services\Tts\Drivers\EdgeTtsDriver;
use App\Services\Tts\Drivers\ElevenLabsDriver;
use App\Services\Tts\Drivers\OpenAiTtsDriver;
use App\Services\Tts\Drivers\XttsDriver;
use App\Services\Tts\TtsManager;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TtsManager::class, function ($app) {
            $manager = new TtsManager();

            // Register all available TTS drivers
            $manager->register('edge', new EdgeTtsDriver());
            $manager->register('elevenlabs', new ElevenLabsDriver());
            $manager->register('openai', new OpenAiTtsDriver());
            $manager->register('xtts', new XttsDriver());

            return $manager;
        });

        // Alias for convenience
        $this->app->alias(TtsManager::class, 'tts');
    }

    public function boot(): void
    {
        //
    }
}
