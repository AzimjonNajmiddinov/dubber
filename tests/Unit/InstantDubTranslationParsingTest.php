<?php

namespace Tests\Unit;

use App\Jobs\TranslateInstantDubBatchJob;
use App\Jobs\TranslateInstantDubMicroBatchJob;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class InstantDubTranslationParsingTest extends TestCase
{
    public function test_batch_translation_parser_rejects_missing_lines(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'en');
        $batch = [
            ['text' => 'Hello', 'start' => 0.0, 'end' => 1.0],
            ['text' => 'World', 'start' => 1.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('skipped line(s): 2');

        $this->invokeParser($job, $batch, '1. Salom {neutral|normal}');
    }

    public function test_micro_translation_parser_rejects_missing_lines(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $batch = [
            ['text' => 'Hello', 'start' => 0.0, 'end' => 1.0],
            ['text' => 'World', 'start' => 1.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('skipped line(s): 2');

        $this->invokeParser($job, $batch, '1. Salom {neutral|normal}');
    }

    public function test_batch_translation_parser_rejects_empty_lines(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'en');
        $batch = [
            ['text' => 'Hello', 'start' => 0.0, 'end' => 1.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty line(s): 1');

        $this->invokeParser($job, $batch, '1. {neutral|normal}');
    }

    public function test_micro_translation_parser_rejects_empty_lines(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $batch = [
            ['text' => 'Hello', 'start' => 0.0, 'end' => 1.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty line(s): 1');

        $this->invokeParser($job, $batch, '1. {neutral|normal}');
    }

    public function test_batch_translation_parser_rejects_copied_source_text(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'en');
        $batch = [
            ['text' => 'We need to leave right now', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copied source text for line(s): 1');

        $this->invokeParser($job, $batch, '1. We need to leave right now {neutral|normal}');
    }

    public function test_micro_translation_parser_rejects_copied_source_text(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $batch = [
            ['text' => 'We need to leave right now', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copied source text for line(s): 1');

        $this->invokeParser($job, $batch, '1. We need to leave right now {neutral|normal}');
    }

    public function test_batch_translation_parser_transliterates_cyrillic_for_uzbek_tts(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'ru');
        $batch = [
            ['text' => 'Привет, как дела?', 'start' => 0.0, 'end' => 2.0],
        ];

        $parsed = $this->invokeParser($job, $batch, '1. Қалайсан? {neutral|normal}');

        $this->assertSame('Qalaysan?', $parsed[0]['text']);
    }

    public function test_micro_translation_parser_transliterates_cyrillic_for_uzbek_tts(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'ru');
        $batch = [
            ['text' => 'Привет, как дела?', 'start' => 0.0, 'end' => 2.0],
        ];

        $parsed = $this->invokeParser($job, $batch, '1. Қалайсан? {neutral|normal}');

        $this->assertSame('Qalaysan?', $parsed[0]['text']);
    }

    public function test_auto_batch_translation_parser_rejects_obvious_english_copy_for_uzbek(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'auto');
        $batch = [
            ['text' => 'We need to leave right now', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copied source text for line(s): 1');

        $this->invokeParser($job, $batch, '1. We need to leave right now {neutral|normal}');
    }

    public function test_auto_batch_translation_parser_allows_already_uzbek_latin(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'auto');
        $batch = [
            ['text' => 'Men hozir ketishim kerak', 'start' => 0.0, 'end' => 2.0],
        ];

        $parsed = $this->invokeParser($job, $batch, '1. Men hozir ketishim kerak {neutral|normal}');

        $this->assertSame('Men hozir ketishim kerak', $parsed[0]['text']);
    }

    public function test_auto_micro_translation_parser_rejects_obvious_english_copy_for_uzbek(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'auto');
        $batch = [
            ['text' => 'We need to leave right now', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copied source text for line(s): 1');

        $this->invokeParser($job, $batch, '1. We need to leave right now {neutral|normal}');
    }

    public function test_batch_translation_parser_accepts_common_numbering_formats(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'en');
        $batch = [
            ['text' => 'Hello there', 'start' => 0.0, 'end' => 1.0],
            ['text' => 'World is big', 'start' => 1.0, 'end' => 2.0],
            ['text' => 'Go now', 'start' => 2.0, 'end' => 3.0],
            ['text' => 'Stay here', 'start' => 3.0, 'end' => 4.0],
        ];

        $parsed = $this->invokeParser($job, $batch, implode("\n", [
            '**1.** Salom {neutral|normal}',
            '2) Dunyo katta {neutral|normal}',
            '[3] Ketdik {excited|fast}',
            '4: Shu yerda qol {calm|slow}',
        ]));

        $this->assertSame('Salom', $parsed[0]['text']);
        $this->assertSame('Dunyo katta', $parsed[1]['text']);
        $this->assertSame('Ketdik', $parsed[2]['text']);
        $this->assertSame('Shu yerda qol', $parsed[3]['text']);
    }

    public function test_micro_translation_parser_accepts_common_numbering_formats(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $batch = [
            ['text' => 'Hello there', 'start' => 0.0, 'end' => 1.0],
            ['text' => 'World is big', 'start' => 1.0, 'end' => 2.0],
        ];

        $parsed = $this->invokeParser($job, $batch, implode("\n", [
            '- 1: Salom {neutral|normal}',
            '* 2 - Dunyo katta {neutral|normal}',
        ]));

        $this->assertSame('Salom', $parsed[0]['text']);
        $this->assertSame('Dunyo katta', $parsed[1]['text']);
    }

    public function test_batch_translation_parser_accepts_global_subtitle_numbering(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 2, 3, 'uz', 'en', 64);
        $batch = [
            ['text' => "They'll be destroyed, Captain,", 'start' => 582.791, 'end' => 584.832],
            ['text' => 'but in the arena,', 'start' => 584.833, 'end' => 586.665],
        ];

        $parsed = $this->invokeParser($job, $batch, implode("\n", [
            "I'll analyze each line carefully first.",
            "95. Yo'q qilinadi, Kapitan, {neutral|normal}",
            "96. lekin arenada, {neutral|normal}",
        ]));

        $this->assertSame("Yo'q qilinadi, Kapitan,", $parsed[0]['text']);
        $this->assertSame('lekin arenada,', $parsed[1]['text']);
    }

    public function test_micro_translation_parser_accepts_segment_index_numbering(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $batch = [
            ['index' => 94, 'text' => "They'll be destroyed, Captain,", 'start' => 582.791, 'end' => 584.832],
            ['index' => 95, 'text' => 'but in the arena,', 'start' => 584.833, 'end' => 586.665],
        ];

        $parsed = $this->invokeParser($job, $batch, implode("\n", [
            "95. Yo'q qilinadi, Kapitan, {neutral|normal}",
            "96. lekin arenada, {neutral|normal}",
        ]));

        $this->assertSame("Yo'q qilinadi, Kapitan,", $parsed[0]['text']);
        $this->assertSame('lekin arenada,', $parsed[1]['text']);
    }

    public function test_micro_translation_falls_back_when_provider_output_is_unusable(): void
    {
        config([
            'services.local_translation.enabled' => false,
            'services.uzbektranslator.url' => null,
            'services.anthropic.key' => 'anthropic-test-key',
            'services.openai.key' => 'openai-test-key',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['text' => 'This response is not numbered.'],
                ],
            ], 200),
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '1. Salom {neutral|normal}']],
                ],
            ], 200),
        ]);

        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $segments = [
            ['text' => 'Hello there', 'raw_text' => 'Hello there', 'start' => 0.0, 'end' => 1.0],
        ];

        $method = new ReflectionMethod($job, 'translateMicroBatch');
        $method->setAccessible(true);

        $translated = $method->invoke($job, $segments, '');

        $this->assertSame('Salom', $translated[0]['text']);
        Http::assertSentCount(2);
    }

    public function test_micro_translation_skips_retired_claude_model(): void
    {
        config([
            'services.local_translation.enabled' => false,
            'services.uzbektranslator.url' => null,
            'services.anthropic.key' => 'anthropic-test-key',
            'services.anthropic.model' => 'claude-3-5-sonnet-latest',
            'services.anthropic.fallback_models' => ['claude-sonnet-4-6'],
            'services.openai.key' => null,
        ]);

        Http::fake(function ($request) {
            $model = $request->data()['model'] ?? '';

            if ($model === 'claude-sonnet-4-6') {
                return Http::response([
                    'content' => [
                        ['text' => '1. Salom {neutral|normal}'],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unexpected model'], 500);
        });

        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $segments = [
            ['text' => 'Hello there', 'raw_text' => 'Hello there', 'start' => 0.0, 'end' => 1.0],
        ];

        $method = new ReflectionMethod($job, 'translateMicroBatch');
        $method->setAccessible(true);

        $translated = $method->invoke($job, $segments, '');

        $this->assertSame('Salom', $translated[0]['text']);
        Http::assertNotSent(fn ($request) => ($request->data()['model'] ?? '') === 'claude-3-5-sonnet-latest');
        Http::assertSent(fn ($request) => ($request->data()['model'] ?? '') === 'claude-sonnet-4-6');
        Http::assertSentCount(1);
    }

    public function test_micro_translation_missing_provider_error_only_mentions_paid_providers_when_local_is_disabled(): void
    {
        config([
            'services.local_translation.enabled' => false,
            'services.uzbektranslator.url' => null,
            'services.anthropic.key' => null,
            'services.openai.key' => null,
        ]);

        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'en');
        $segments = [
            ['text' => 'Hello there', 'raw_text' => 'Hello there', 'start' => 0.0, 'end' => 1.0],
        ];

        $method = new ReflectionMethod($job, 'translateMicroBatch');
        $method->setAccessible(true);

        try {
            $method->invoke($job, $segments, '');
            $this->fail('Expected translation provider failure.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Claude not configured', $e->getMessage());
            $this->assertStringContainsString('OpenAI not configured', $e->getMessage());
            $this->assertStringContainsString('ANTHROPIC_API_KEY or OPENAI_API_KEY', $e->getMessage());
            $this->assertStringNotContainsString('uzbekTranslator not configured', $e->getMessage());
        }
    }

    private function invokeParser(object $job, array $batch, string $content): array
    {
        $method = new ReflectionMethod($job, 'parseTranslationResponse');
        $method->setAccessible(true);

        return $method->invoke($job, $batch, $content);
    }
}
