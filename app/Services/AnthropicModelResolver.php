<?php

namespace App\Services;

class AnthropicModelResolver
{
    private const RETIRED_MODELS = [
        'claude-3-5-sonnet-latest',
        'claude-3-5-haiku-latest',
        'claude-3-opus-latest',
        'claude-3-sonnet-20240229',
        'claude-3-haiku-20240307',
    ];

    public static function primary(): string
    {
        return self::models()[0];
    }

    public static function models(): array
    {
        $configured = trim((string) config('services.anthropic.model', ''));
        $fallbacks = config('services.anthropic.fallback_models', []);

        if (is_string($fallbacks)) {
            $fallbacks = explode(',', $fallbacks);
        }

        $models = array_merge(
            [$configured],
            is_array($fallbacks) ? $fallbacks : [],
            ['claude-sonnet-4-6', 'claude-haiku-4-5']
        );

        $models = array_values(array_unique(array_filter(array_map(
            fn ($model) => trim((string) $model),
            $models
        ), fn ($model) => !in_array($model, self::RETIRED_MODELS, true))));

        return $models ?: ['claude-sonnet-4-6'];
    }
}
