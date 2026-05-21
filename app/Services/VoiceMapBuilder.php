<?php

namespace App\Services;

use App\Http\Controllers\AdminVoicePoolController;

class VoiceMapBuilder
{
    /**
     * Infer gender from a speaker tag or pool name (M1→male, F1→female, C1→child).
     */
    public static function genderFromTag(string $tag): string
    {
        if (str_starts_with($tag, 'F')) return 'female';
        if (str_starts_with($tag, 'C')) return 'child';
        return 'male';
    }

    /**
     * Build a voice entry for a forced voice selection.
     * Returns null for drivers that don't use force_voice (edge, aisha use default variants).
     */
    public static function forceVoiceEntry(string $driver, string $forceVoice): ?array
    {
        if ($driver === 'openai') {
            $gender = in_array($forceVoice, ['nova', 'shimmer', 'fable', 'coral', 'marin', 'ballad'])
                ? 'female' : 'male';
            return ['driver' => 'openai', 'gender' => $gender, 'openai_voice' => $forceVoice];
        }

        if ($driver === 'mms') {
            $gender = self::genderFromTag($forceVoice);
            return [
                'driver'    => 'mms',
                'gender'    => $gender,
                'pool_name' => $forceVoice,
                'tau'       => AdminVoicePoolController::getTau($gender, $forceVoice),
                'speed'     => AdminVoicePoolController::getSpeed($gender, $forceVoice),
            ];
        }

        return null; // edge, aisha: force_voice has no meaning
    }

    /**
     * Get male/female/child voice variant lists for a given driver and language.
     *
     * @return array{male: array, female: array, child: array}
     */
    public static function variantsForDriver(string $driver, string $language): array
    {
        if ($driver === 'mms') {
            $toVariant = fn(string $g) => fn(string $f) => [
                'driver'    => 'mms',
                'gender'    => $g,
                'pool_name' => pathinfo($f, PATHINFO_FILENAME),
            ];
            $maleFiles   = glob(storage_path('app/voice-pool/male/*.{wav,mp3,m4a}'), GLOB_BRACE)   ?: [];
            $femaleFiles = glob(storage_path('app/voice-pool/female/*.{wav,mp3,m4a}'), GLOB_BRACE) ?: [];
            $childFiles  = glob(storage_path('app/voice-pool/child/*.{wav,mp3,m4a}'), GLOB_BRACE)  ?: $maleFiles;
            return [
                'male'   => array_map($toVariant('male'),   $maleFiles),
                'female' => array_map($toVariant('female'), $femaleFiles),
                'child'  => array_map($toVariant('child'),  $childFiles),
            ];
        }

        if ($driver === 'aisha') {
            return VoiceVariants::forAisha();
        }

        return VoiceVariants::forLanguage($language);
    }

    /**
     * Assign voice entries to speakers not yet in the map.
     * Cycles through variants by gender, continuing from where existing assignments left off.
     *
     * @param array{male: array, female: array, child: array} $variants
     */
    public static function assignSpeakers(array $voiceMap, array $speakers, array $variants): array
    {
        $maleIdx = $femaleIdx = $childIdx = 0;
        foreach ($voiceMap as $tag => $_) {
            if (str_starts_with($tag, 'C'))      $childIdx++;
            elseif (str_starts_with($tag, 'M'))  $maleIdx++;
            else                                  $femaleIdx++;
        }

        foreach (array_keys($speakers) as $tag) {
            if (isset($voiceMap[$tag])) continue;

            if (str_starts_with($tag, 'C') && !empty($variants['child'])) {
                $voiceMap[$tag] = $variants['child'][$childIdx % count($variants['child'])];
                $childIdx++;
            } elseif (str_starts_with($tag, 'M') && !empty($variants['male'])) {
                $voiceMap[$tag] = $variants['male'][$maleIdx % count($variants['male'])];
                $maleIdx++;
            } elseif (!empty($variants['female'])) {
                $voiceMap[$tag] = $variants['female'][$femaleIdx % count($variants['female'])];
                $femaleIdx++;
            }
        }

        return $voiceMap;
    }
}
