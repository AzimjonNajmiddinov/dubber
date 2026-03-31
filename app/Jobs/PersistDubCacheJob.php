<?php

namespace App\Jobs;

use App\Models\InstantDub;
use App\Models\InstantDubSegment;
use App\Models\InstantDubVoiceMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PersistDubCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(public string $sessionId) {}

    public function handle(): void
    {
        $sessionKey = "instant-dub:{$this->sessionId}";

        // Prevent duplicate persist runs
        $lock = Redis::set("instant-dub:{$this->sessionId}:persist-lock", 1, 'EX', 60, 'NX');
        if (!$lock) return;

        $sessionJson = Redis::get($sessionKey);
        if (!$sessionJson) return;

        $session = json_decode($sessionJson, true);
        if (($session['status'] ?? '') !== 'complete') return;
        if (!empty($session['cached_dub_id'])) return; // already persisted

        $videoUrl = strtok($session['video_url'] ?? '', '?');
        $language  = $session['language'] ?? 'uz';
        $total     = (int) ($session['total_segments'] ?? 0);

        if (!$videoUrl || $total === 0) return;

        try {
            // Create or update the dub record
            $dub = InstantDub::updateOrCreate(
                ['video_url' => $videoUrl, 'language' => $language],
                [
                    'title'         => $session['title'] ?? 'Untitled',
                    'translate_from'=> $session['translate_from'] ?? null,
                    'tts_driver'    => $session['tts_driver'] ?? 'edge',
                    'status'        => 'processing',
                    'total_segments'=> $total,
                    'session_id'    => $this->sessionId,
                ]
            );

            // Permanent AAC directory
            $aacDir = storage_path("app/instant-dub-cache/{$dub->id}/aac");
            @mkdir($aacDir, 0755, true);
            $dub->update(['aac_dir' => $aacDir]);

            $sessionAacDir = storage_path("app/instant-dub/{$this->sessionId}/aac");

            // Copy lead + tail
            foreach (['lead.aac', 'tail.aac'] as $file) {
                $src = "{$sessionAacDir}/{$file}";
                if (file_exists($src)) {
                    copy($src, "{$aacDir}/{$file}");
                }
            }

            // Save voice map
            $voiceMap = json_decode(Redis::get("instant-dub:{$this->sessionId}:voices") ?? '{}', true);
            InstantDubVoiceMap::where('instant_dub_id', $dub->id)->delete();
            foreach ($voiceMap as $tag => $config) {
                InstantDubVoiceMap::create([
                    'instant_dub_id' => $dub->id,
                    'speaker_tag'    => $tag,
                    'voice_config'   => $config,
                ]);
            }

            // Save segments
            $chunkKeys = [];
            for ($i = 0; $i < $total; $i++) {
                $chunkKeys[] = "{$sessionKey}:chunk:{$i}";
            }
            $chunkValues = Redis::mget($chunkKeys);

            InstantDubSegment::where('instant_dub_id', $dub->id)->delete();

            foreach ($chunkValues as $i => $chunkJson) {
                if (!$chunkJson) continue;
                $chunk = json_decode($chunkJson, true);

                $src     = "{$sessionAacDir}/{$i}.aac";
                $dstPath = "{$aacDir}/{$i}.aac";
                if (file_exists($src)) {
                    copy($src, $dstPath);
                }

                InstantDubSegment::create([
                    'instant_dub_id' => $dub->id,
                    'segment_index'  => $i,
                    'speaker'        => $chunk['speaker'] ?? 'M1',
                    'start_time'     => $chunk['start_time'] ?? 0,
                    'end_time'       => $chunk['end_time'] ?? 0,
                    'slot_end'       => $chunk['slot_end'] ?? null,
                    'source_text'    => $chunk['source_text'] ?? null,
                    'translated_text'=> $chunk['text'] ?? '',
                    'aac_path'       => file_exists($dstPath) ? $dstPath : null,
                    'aac_duration'   => $chunk['aac_duration'] ?? null,
                    'needs_retts'    => false,
                ]);
            }

            $dub->update(['status' => 'complete']);

            // Mark session as cached so we don't persist again
            $session['cached_dub_id'] = $dub->id;
            Redis::setex($sessionKey, 50400, json_encode($session));

            Log::info("[DUB] Persisted dub #{$dub->id} ({$total} segments) for: {$videoUrl}");

        } catch (\Throwable $e) {
            Log::error("[DUB] PersistDubCacheJob failed: " . $e->getMessage(), ['session' => $this->sessionId]);
        }
    }
}
