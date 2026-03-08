<?php

namespace App\Providers;

use App\Services\Tts\Drivers\AishaTtsDriver;
use App\Services\Tts\Drivers\EdgeTtsDriver;
use App\Services\Tts\Drivers\HybridUzbekDriver;
use App\Services\Tts\Drivers\UzbekVoiceDriver;
use App\Services\Tts\TtsManager;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TtsManager::class, function ($app) {
            $manager = new TtsManager();

            // Register available TTS drivers
            $manager->register('aisha', new AishaTtsDriver());
            $manager->register('edge', new EdgeTtsDriver());
            $manager->register('hybrid_uzbek', new HybridUzbekDriver());
            $manager->register('uzbekvoice', new UzbekVoiceDriver());

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
