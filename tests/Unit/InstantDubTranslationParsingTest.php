<?php

namespace Tests\Unit;

use App\Jobs\TranslateInstantDubBatchJob;
use App\Jobs\TranslateInstantDubMicroBatchJob;
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

    public function test_batch_translation_parser_rejects_cyrillic_for_uzbek(): void
    {
        $job = new TranslateInstantDubBatchJob('parse-test', 0, 1, 'uz', 'ru');
        $batch = [
            ['text' => 'Привет, как дела?', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('used Cyrillic for Uzbek line(s): 1');

        $this->invokeParser($job, $batch, '1. Привет, как дела? {neutral|normal}');
    }

    public function test_micro_translation_parser_rejects_cyrillic_for_uzbek(): void
    {
        $job = new TranslateInstantDubMicroBatchJob('parse-test', [], 'uz', 'ru');
        $batch = [
            ['text' => 'Привет, как дела?', 'start' => 0.0, 'end' => 2.0],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('used Cyrillic for Uzbek line(s): 1');

        $this->invokeParser($job, $batch, '1. Привет, как дела? {neutral|normal}');
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

    private function invokeParser(object $job, array $batch, string $content): array
    {
        $method = new ReflectionMethod($job, 'parseTranslationResponse');
        $method->setAccessible(true);

        return $method->invoke($job, $batch, $content);
    }
}
