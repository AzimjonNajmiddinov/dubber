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
use App\Support\DubSession;
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
        $lock = Redis::set(DubSession::persistLockKey($this->sessionId), 1, 'EX', 60, 'NX');
        if (!$lock) return;

        $session = DubSession::get($this->sessionId);
        if (!$session || ($session['status'] ?? '') !== 'complete') return;

        $videoUrl   = strtok($session['video_url'] ?? '', '?');
        $contentKey = InstantDub::extractContentKey($session['video_url'] ?? '');
        $language   = $session['language'] ?? 'uz';
        $total      = (int) ($session['total_segments'] ?? 0);

        if (!$videoUrl || $total === 0) return;

        try {
            $dub = InstantDub::updateOrCreate(
                ['video_content_key' => $contentKey, 'language' => $language],
                [
                    'title'          => $session['title'] ?? 'Untitled',
                    'video_url'      => $videoUrl,
                    'translate_from' => $session['translate_from'] ?? null,
                    'tts_driver'     => $session['tts_driver'] ?? 'edge',
                    'status'         => 'processing',
                    'total_segments' => $total,
                    'session_id'     => $this->sessionId,
                ]
            );

            // Save voice map
            $voiceMap = json_decode(Redis::get(DubSession::voicesKey($this->sessionId)) ?? '{}', true);
            InstantDubVoiceMap::where('instant_dub_id', $dub->id)->delete();
            foreach ($voiceMap as $tag => $config) {
                InstantDubVoiceMap::create([
                    'instant_dub_id' => $dub->id,
                    'speaker_tag'    => $tag,
                    'voice_config'   => $config,
                ]);
            }

            // Save translations only — TTS is always regenerated fresh on cache hit
            $chunkKeys   = array_map(fn($i) => DubSession::chunkKey($this->sessionId, $i), range(0, $total - 1));
            $chunkValues = Redis::mget($chunkKeys);

            InstantDubSegment::where('instant_dub_id', $dub->id)->delete();

            foreach ($chunkValues as $i => $chunkJson) {
                if (!$chunkJson) continue;
                $chunk = json_decode($chunkJson, true);

                InstantDubSegment::create([
                    'instant_dub_id'  => $dub->id,
                    'segment_index'   => $i,
                    'speaker'         => $chunk['speaker'] ?? 'M1',
                    'start_time'      => $chunk['start_time'] ?? 0,
                    'end_time'        => $chunk['end_time'] ?? 0,
                    'slot_end'        => $chunk['slot_end'] ?? null,
                    'source_text'     => $chunk['source_text'] ?? null,
                    'translated_text' => $chunk['text'] ?? '',
                    'aac_path'        => null,
                    'aac_duration'    => null,
                    'tts_path'        => null,
                    'tts_duration'    => null,
                    'needs_retts'     => false,
                ]);
            }

            $dub->update(['status' => 'complete']);

            // Mark session as cached so we don't persist again
            DubSession::patch($this->sessionId, ['cached_dub_id' => $dub->id]);

            Log::info("[DUB] Persisted dub #{$dub->id} ({$total} segments) for: {$videoUrl}");

        } catch (\Throwable $e) {
            Log::error("[DUB] PersistDubCacheJob failed: " . $e->getMessage(), ['session' => $this->sessionId]);
        }
    }
}
