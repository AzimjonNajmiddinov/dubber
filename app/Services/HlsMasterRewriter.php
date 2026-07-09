<?php

namespace App\Services;

/**
 * Rewrites an upstream HLS master playlist so the player exposes the dubbed
 * audio track and subtitle track: injects EXT-X-MEDIA entries into every
 * audio group, demotes original tracks once the dub is playable, resolves
 * relative CDN URLs, and synthesizes a wrapper master when the upstream URL
 * is a bare media playlist. Pure text transformation — no HTTP, no cache.
 */
class HlsMasterRewriter
{
    public function rewrite(
        string $master,
        string $videoUrl,
        string $videoBaseUrl,
        string $videoQuery,
        string $lang,
        bool $dubPlayable,
    ): string {
        $dubDefault = $dubPlayable ? 'YES' : 'NO';
        $dubAutoselect = $dubPlayable ? 'YES' : 'NO';
        $langNames = ['uz' => "O'zbek dublyaj", 'ru' => 'Русский дубляж', 'en' => 'English dub'];
        $dubName = $langNames[$lang] ?? ucfirst($lang) . ' dub';
        $subNames = ['uz' => "O'zbek", 'ru' => 'Русский', 'en' => 'English'];
        $subName = $subNames[$lang] ?? ucfirst($lang);

        if (str_contains($master, '#EXTINF') && !str_contains($master, '#EXT-X-STREAM-INF')) {
            return $this->syntheticMasterForMediaPlaylist(
                $videoUrl,
                'audio',
                'subs',
                $dubName,
                $subName,
                $lang,
                $dubPlayable,
            );
        }

        $lines = explode("\n", $master);

        // Inject EXT-X-INDEPENDENT-SEGMENTS if not present
        $hasIndependent = false;
        foreach ($lines as $line) {
            if (str_contains(trim($line), '#EXT-X-INDEPENDENT-SEGMENTS')) {
                $hasIndependent = true;
                break;
            }
        }
        if (!$hasIndependent) {
            array_splice($lines, 1, 0, ['#EXT-X-INDEPENDENT-SEGMENTS']);
        }

        // First pass: collect all audio/subtitle groups. Some HLS masters use
        // different AUDIO groups per variant; the dub rendition must exist in
        // every referenced group or ABR level switching can fall back to original.
        $existingAudioGroups = [];
        $streamAudioGroups = [];
        $existingSubsGroups = [];
        $streamSubsGroups = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#EXT-X-MEDIA')) {
                if (str_contains($trimmed, 'TYPE=AUDIO')) {
                    $group = $this->hlsTagAttribute($trimmed, 'GROUP-ID');
                    if ($group !== null && !in_array($group, $existingAudioGroups, true)) {
                        $existingAudioGroups[] = $group;
                    }
                }
                if (str_contains($trimmed, 'TYPE=SUBTITLES')) {
                    $group = $this->hlsTagAttribute($trimmed, 'GROUP-ID');
                    if ($group !== null && !in_array($group, $existingSubsGroups, true)) {
                        $existingSubsGroups[] = $group;
                    }
                }
            } elseif (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                $audioGroup = $this->hlsTagAttribute($trimmed, 'AUDIO');
                if ($audioGroup !== null && !in_array($audioGroup, $streamAudioGroups, true)) {
                    $streamAudioGroups[] = $audioGroup;
                }
                $subsGroup = $this->hlsTagAttribute($trimmed, 'SUBTITLES');
                if ($subsGroup !== null && !in_array($subsGroup, $streamSubsGroups, true)) {
                    $streamSubsGroups[] = $subsGroup;
                }
            }
        }

        $audioGroupIds = array_values(array_unique(array_merge(
            $streamAudioGroups,
            $existingAudioGroups,
        )));
        if (empty($audioGroupIds)) {
            $audioGroupIds = ['audio'];
        }

        $subsGroupIds = array_values(array_unique(array_merge(
            $streamSubsGroups,
            $existingSubsGroups,
        )));
        if (empty($subsGroupIds)) {
            $subsGroupIds = ['subs'];
        }

        $groupId = $audioGroupIds[0];
        $subsGroupId = $subsGroupIds[0];
        $output = [];
        $dubInjected = false;
        $fallbackAudioInjected = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Inject dub audio track only after the server verifies a continuous
            // post-intro HLS runway. Before that, the track must not be selectable.
            if ($dubPlayable && !$dubInjected && str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'TYPE=AUDIO')) {
                foreach ($audioGroupIds as $audioGroupId) {
                    $output[] = $this->hlsDubAudioMediaLine($audioGroupId, $dubName, $lang, $dubDefault, $dubAutoselect);
                }
                $dubInjected = true;
            }

            // Inject before STREAM-INF if no existing audio tracks.
            if (!$fallbackAudioInjected && str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (empty($existingAudioGroups)) {
                    $originalDefault = $dubPlayable ? 'NO' : 'YES';
                    $originalAutoselect = $dubPlayable ? 'NO' : 'YES';
                    $output[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT={$originalDefault},AUTOSELECT={$originalAutoselect}";
                }
                if ($dubPlayable && !$dubInjected) {
                    foreach ($audioGroupIds as $audioGroupId) {
                        $output[] = $this->hlsDubAudioMediaLine($audioGroupId, $dubName, $lang, $dubDefault, $dubAutoselect);
                    }
                    $dubInjected = true;
                }
                if (!str_contains(implode("\n", $output), 'dub-subtitles')) {
                    $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
                }
                $fallbackAudioInjected = true;
            }

            // Inject subtitles before STREAM-INF
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF') && !str_contains(implode("\n", $output), 'dub-subtitles')) {
                $output[] = "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO";
            }

            // Once dub is playable, demote existing audio/subtitle tracks so ours takes priority.
            if ($dubPlayable && str_starts_with($trimmed, '#EXT-X-MEDIA') && !str_contains($trimmed, 'dub-audio') && !str_contains($trimmed, 'dub-subtitles')) {
                $line = preg_replace('/DEFAULT=YES/', 'DEFAULT=NO', $line);
                $line = preg_replace('/AUTOSELECT=YES/', 'AUTOSELECT=NO', $line);
            }

            // Ensure STREAM-INF lines reference audio + subtitle groups
            if (str_starts_with($trimmed, '#EXT-X-STREAM-INF')) {
                if (str_contains($trimmed, 'AUDIO=')) {
                    if (empty($existingAudioGroups)) {
                        $line = preg_replace('/AUDIO="[^"]*"/', 'AUDIO="' . $groupId . '"', $line);
                    }
                } else {
                    $line = rtrim($line) . ',AUDIO="' . $groupId . '"';
                }
                if (!str_contains($line, 'SUBTITLES=')) {
                    $line = rtrim($line) . ',SUBTITLES="' . $subsGroupId . '"';
                }
            }

            // EXT-X-MEDIA URIs: convert relative to absolute CDN URLs (skip our own)
            if (str_starts_with($trimmed, '#EXT-X-MEDIA') && str_contains($trimmed, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($videoBaseUrl, $videoQuery) {
                    $uri = $m[1];
                    if (str_contains($uri, 'dub-')) return $m[0];
                    if (!str_starts_with($uri, 'http')) {
                        return 'URI="' . $this->resolveHlsUrl($videoBaseUrl, $uri, $videoQuery) . '"';
                    }
                    return $m[0];
                }, $line);
            }

            // Standalone URIs: convert relative to absolute CDN URLs
            if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                if (!str_starts_with($trimmed, 'http')) {
                    $line = $this->resolveHlsUrl($videoBaseUrl, $trimmed, $videoQuery);
                }
            }

            $output[] = $line;
        }

        $result = implode("\n", $output);

        return $result;
    }

    private function hlsTagAttribute(string $tag, string $name): ?string
    {
        return preg_match('/(?:^|,)' . preg_quote($name, '/') . '="([^"]*)"/', $tag, $m)
            ? $m[1]
            : null;
    }

    private function hlsDubAudioMediaLine(
        string $groupId,
        string $dubName,
        string $lang,
        string $dubDefault,
        string $dubAutoselect,
    ): string {
        return "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT={$dubDefault},AUTOSELECT={$dubAutoselect},CHANNELS=\"1\"";
    }

    private function syntheticMasterForMediaPlaylist(
        string $mediaPlaylistUrl,
        string $groupId,
        string $subsGroupId,
        string $dubName,
        string $subName,
        string $lang,
        bool $dubPlayable,
    ): string {
        $originalDefault = $dubPlayable ? 'NO' : 'YES';
        $originalAutoselect = $dubPlayable ? 'NO' : 'YES';
        $dubDefault = $dubPlayable ? 'YES' : 'NO';
        $dubAutoselect = $dubPlayable ? 'YES' : 'NO';

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-INDEPENDENT-SEGMENTS',
            "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"Original\",DEFAULT={$originalDefault},AUTOSELECT={$originalAutoselect}",
            "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"{$subsGroupId}\",NAME=\"{$subName}\",LANGUAGE=\"{$lang}\",URI=\"dub-subtitles.m3u8\",DEFAULT=YES,AUTOSELECT=YES,FORCED=NO",
            "#EXT-X-STREAM-INF:BANDWIDTH=3000000,AUDIO=\"{$groupId}\",SUBTITLES=\"{$subsGroupId}\"",
            $mediaPlaylistUrl,
            '',
        ];

        if ($dubPlayable) {
            array_splice($lines, 4, 0, [
                "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"{$groupId}\",NAME=\"{$dubName}\",LANGUAGE=\"{$lang}\",URI=\"dub-audio.m3u8\",DEFAULT={$dubDefault},AUTOSELECT={$dubAutoselect},CHANNELS=\"1\"",
            ]);
        }

        return implode("\n", $lines);
    }

    public function resolveHlsUrl(string $baseUrl, string $uri, string $parentQuery = ''): string
    {
        if (str_starts_with($uri, 'http')) {
            return $uri;
        }

        if (str_starts_with($uri, '//')) {
            return 'https:' . $uri;
        }

        if (str_starts_with($uri, '/')) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            $url = $origin . $uri;
        } else {
            $url = rtrim($baseUrl, '/') . '/' . $uri;
        }

        if ($parentQuery && !str_contains($url, '?')) {
            $url .= '?' . $parentQuery;
        }

        return $url;
    }

}
