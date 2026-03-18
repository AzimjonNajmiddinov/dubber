<?php

namespace App\Services;

class VoiceVariants
{
    /**
     * Get male/female/child Edge TTS voice variants for a given language.
     *
     * @return array{male: array, female: array, child: array}
     */
    public static function forLanguage(string $language): array
    {
        $voices = self::voicesByLanguage($language);
        $male = $voices['male'];
        $female = $voices['female'];

        return [
            'male' => [
                ['voice' => $male, 'pitch' => '+0Hz',  'rate' => '+0%'],
                ['voice' => $male, 'pitch' => '-8Hz',  'rate' => '-5%'],
                ['voice' => $male, 'pitch' => '+6Hz',  'rate' => '+5%'],
                ['voice' => $male, 'pitch' => '-15Hz', 'rate' => '-8%'],
            ],
            'female' => [
                ['voice' => $female, 'pitch' => '+0Hz',  'rate' => '+0%'],
                ['voice' => $female, 'pitch' => '-6Hz',  'rate' => '-5%'],
                ['voice' => $female, 'pitch' => '+8Hz',  'rate' => '+5%'],
                ['voice' => $female, 'pitch' => '-12Hz', 'rate' => '-8%'],
            ],
            'child' => [
                ['voice' => $female, 'pitch' => '+15Hz', 'rate' => '+10%'],
                ['voice' => $male,   'pitch' => '+12Hz', 'rate' => '+8%'],
            ],
        ];
    }

    /**
     * Get AISHA voice variants (Uzbek only).
     *
     * @return array{male: array, female: array, child: array}
     */
    public static function forAisha(): array
    {
        return [
            'male' => [
                ['voice' => 'jaxongir', 'mood' => 'neutral'],
                ['voice' => 'jaxongir', 'mood' => 'happy'],
                ['voice' => 'jaxongir', 'mood' => 'sad'],
            ],
            'female' => [
                ['voice' => 'gulnoza', 'mood' => 'neutral'],
                ['voice' => 'gulnoza', 'mood' => 'happy'],
                ['voice' => 'gulnoza', 'mood' => 'sad'],
            ],
            'child' => [
                ['voice' => 'gulnoza', 'mood' => 'happy'],
                ['voice' => 'jaxongir', 'mood' => 'happy'],
            ],
        ];
    }

    private static function voicesByLanguage(string $language): array
    {
        return match ($language) {
            'uz' => ['male' => 'uz-UZ-SardorNeural',   'female' => 'uz-UZ-MadinaNeural'],
            'ru' => ['male' => 'ru-RU-DmitryNeural',   'female' => 'ru-RU-SvetlanaNeural'],
            'en' => ['male' => 'en-US-GuyNeural',      'female' => 'en-US-JennyNeural'],
            'tr' => ['male' => 'tr-TR-AhmetNeural',    'female' => 'tr-TR-EmelNeural'],
            'es' => ['male' => 'es-ES-AlvaroNeural',   'female' => 'es-ES-ElviraNeural'],
            'fr' => ['male' => 'fr-FR-HenriNeural',    'female' => 'fr-FR-DeniseNeural'],
            'de' => ['male' => 'de-DE-ConradNeural',   'female' => 'de-DE-KatjaNeural'],
            'ar' => ['male' => 'ar-SA-HamedNeural',    'female' => 'ar-SA-ZariyahNeural'],
            'zh' => ['male' => 'zh-CN-YunxiNeural',    'female' => 'zh-CN-XiaoxiaoNeural'],
            'ja' => ['male' => 'ja-JP-KeitaNeural',    'female' => 'ja-JP-NanamiNeural'],
            'ko' => ['male' => 'ko-KR-InJoonNeural',   'female' => 'ko-KR-SunHiNeural'],
            'pt' => ['male' => 'pt-BR-AntonioNeural',  'female' => 'pt-BR-FranciscaNeural'],
            'hi' => ['male' => 'hi-IN-MadhurNeural',   'female' => 'hi-IN-SwaraNeural'],
            'it' => ['male' => 'it-IT-DiegoNeural',    'female' => 'it-IT-ElsaNeural'],
            default => ['male' => 'en-US-GuyNeural',   'female' => 'en-US-JennyNeural'],
        };
    }
}
