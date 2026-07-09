# Over-Engineering Analysis: Dubber (Instant Dub / TTS Pipeline)

> **Progress (2026-07-09):**
> - ✅ **Phase 1 applied** — dead code deleted (~2,300 lines): `ActingDirector`, `F5TtsClient`, `XttsClient`, `AishaTtsDriver`, `LocalTranslationClient` + config/env entries, V2's dead `processSegment` path + 9 orphaned helpers, controller's legacy `{index}.aac` on-demand path + `.aac` alias routes, `$forceVoice`/local-translator/Uzbek-translator branches.
> - ✅ **Phase 2a applied** — translation dedupe: 15 shared methods extracted to `app/Jobs/Concerns/ParsesInstantDubTranslation.php` (token-exact copies, parameterized via 3 hooks); Batch job 1,183→763 lines, MicroBatch 684→216 lines. Every translation parse fix is now made in one place.
> - ⚠️ **Kept deliberately:** SSE `events` endpoint (production nginx logs show real traffic), `EmotionDSPProcessor`/`HybridUzbekDriver` (entangled with V2's voice-cloning path — revisit in Phase 3), `NaturalSpeechProcessor` (live on V2 path).
> - ✅ **Phase 2b applied** — `SubtitleFetcher` service extracted from `PrepareInstantDubJob` (946→458 lines; HLS/VTT/YouTube subtitle scraping now reusable); `HlsMasterRewriter` service extracted from `InstantDubController` (1,561→1,314 lines; master-playlist rewriting is now unit-testable without HTTP).
> - ⚠️ **Skipped deliberately:** `SegmentAudioService` — the remaining TTS jobs' audio helpers are *divergent implementations*, not copies (the literal duplication was V2 ↔ GenerateTtsForSegmentJob, resolved in Phase 1); unifying them would be redesign with behavior risk. Controller 3-way split — `HlsMasterRewriter` already delivered the testability win; the split would add churn without deleting code.
> - ⏳ **Remaining:** Phase 4 (`Bus::batch` wave replacement — needs staging test).

**Subject:** ~31,000 lines of app/view PHP whose core concept is simple: *take a video, fetch its subtitles, translate them with an LLM, synthesize speech, and serve the result as a switchable HLS audio track.*

## 1. Overall Simplification Strategy

Four themes account for most of the complexity:

1. **Parallel pipelines that never converged.** There are four live TTS pipelines and two separate translation stacks solving the same problem with independent, incompatible code. Each new flow (upload → online → live → instant) was written beside its predecessor instead of on top of it.
2. **Copy-paste instead of extraction.** ~700 lines of translation parsing/retry/sanitization code are duplicated byte-for-byte between two jobs; the TTS fit-to-slot/strip-silence helpers exist in four places.
3. **Hand-rolled distributed orchestration.** The "wave" system (per-wave Redis counters, compare-and-swap claims, Lua completion scripts, three separate dispatch sites) reimplements what Laravel's `Bus::batch()->then()` provides natively.
4. **Dead and default-dead code.** Roughly 2,200 lines are provably unreachable: an entire 613-line service never instantiated, two full HTTP client wrappers with no callers, fallback tiers gated behind config that is off by default, and a hardcoded `$forceVoice = false` guarding dead branches.

The strategy: delete what's dead, extract what's duplicated into shared services, replace the Redis wave state machine with framework job batching, and split the two god-files (controller and prepare job) along their natural seams.

**Important caveat:** not all the complexity here is accidental — the HLS runway/readiness logic encodes real, recently-fixed playback bugs (the last five commits are all about it). That part should be *reorganized*, not naively removed.

---

## 2. Identified Complexities & Proposed Simpler Solutions

### 2.1 Two translation jobs that are ~70% identical copy-paste

**Original complex area.** `TranslateInstantDubBatchJob` (1,183 lines) and `TranslateInstantDubMicroBatchJob` (684 lines) differ only in prompt size and batch size. Everything else is pasted twice:

- `parseTranslationResponse` — Batch:882 vs Micro:385
- `sanitizeForTts`, `scrubUtf8`, `extractDelivery`, `targetLanguageRules`, `rejectBadTranslationOutput`, `callAnthropic`, `mergeVoiceMap` — all duplicated
- A 40-entry Cyrillic→Latin transliteration map inlined in both files (Batch:931-946, Micro:434-448)

Every bug fix must be applied twice — recent commits ("Scrub instant dub translation UTF-8", "Accept global numbering") are exactly the kind of change this duplication doubles.

The fallback ladder is also retries-of-retries: `callAnthropic` already loops over multiple models internally, yet batch-0 wraps it in a parallel `Http::pool` attempt, then a sequential re-call, then `callOpenAiWithRetry` with 4 more attempts and `sleep()` backoff (Batch:267-344, 468-505).

**Proposed simpler solution.** Extract one `TranslationService` (~200 lines) holding the prompt fragments, `callAnthropic`, the single GPT-4o fallback, and all parsing/sanitization. Both jobs shrink to "build my prompt variant → call service → dispatch TTS":

```php
// TranslateInstantDubMicroBatchJob — the whole job body
$result = app(TranslationService::class)->translate(
    $segments, $lang, prompt: TranslationPrompt::micro($lang)
);
foreach ($result->lines as $i => $line) {
    ProcessInstantDubSegmentJob::dispatch($this->sessionId, $segments[$i], $line);
}
```

Collapse the provider ladder to two tiers (Claude model loop → GPT-4o once). Delete the branches that are dead under default config:

- The local translator (`LOCAL_TRANSLATION_ENABLED=false` by default)
- The Uzbek microservice (`UZBEKTRANSLATOR_SERVICE_URL` has no default and isn't in `.env.example` — 56 unreachable lines at Micro:270-325)
- The `$forceVoice = false` literal at Micro:110 guarding ~15 dead lines

**Estimate: 2,544 → ~790 lines** across the three translation jobs, identical behavior on every path that actually runs.

### 2.2 A hand-rolled distributed "wave" state machine in Redis

**Original complex area.** Wave orchestration is spread across three dispatchers:

1. `PrepareInstantDubJob`'s lookahead loop
2. `DispatchWaveJob`'s chain
3. `ProcessInstantDubSegmentJob::dispatchNextWaveIfReady` (:522-576)

…coordinating through five loose key families (`waveKey`, `waveKey:offset`, `waveProgressKey`, `waveProgressKey:ready`, `wavesDispatchedKey`). Progress is a raw `Redis::incr` compared against an 80% `ceil` threshold, double-dispatch is prevented with a manual compare-and-swap (`if ($newDispatched !== $nextWave + 1) return;` at :553-560), and session completion is computed inside a Lua script that decodes the whole session JSON (:492-510) — *and also* inside `GenerateBgChunkJob::checkPlayable()`, so two jobs race to declare completion. `DubSession` needs 21 key-builder methods and an `allDeleteKeys()` that guesses "max ~50 waves" to clean up after itself.

**Proposed simpler solution.** This is what Laravel job batching exists for:

```php
$batch = Bus::batch(
    collect($waves)->map(fn ($wave) => new TranslateWaveJob($sessionId, $wave))
)->then(fn () => PersistDubCacheJob::dispatch($sessionId))
 ->onQueue('segment-generation')
 ->dispatch();
```

Batching gives you atomic progress counters, single-owner completion callbacks, and cancellation for free — no CAS claims, no Lua, no orphaned counter keys, and the "who marks it complete" race disappears because exactly one `then()` fires. The 80%-lookahead behavior (start wave N+1 before N finishes) is preserved by dispatching all waves up front with `->delay()` staggering, or by chaining from the batch's progress callback.

**Estimate:** `DispatchWaveJob` (192 lines) folds away entirely; ~150 lines of counter ceremony deleted from Prepare/ProcessSegment; `DubSession` drops 5 key families.

### 2.3 Four parallel TTS pipelines, plus 1,100+ lines of dead audio code

**Original complex area.** Four live jobs each own their own synthesize→fit-to-slot→strip-silence implementation:

| Job | Flow | TTS mechanism |
|---|---|---|
| `GenerateTtsSegmentsJobV2` + `GenerateTtsForSegmentJob` | upload | `TtsManager->driver()->synthesize()` |
| `ProcessSegmentTtsJob` | online/live | `TtsManager->driver()->synthesize()` |
| `ProcessInstantDubSegmentJob` | instant | bypasses `TtsManager` entirely; shells out to `edge-tts` CLI directly (:248-286) |

Meanwhile:

- `ActingDirector.php` (613 lines) is **never instantiated anywhere** — its only references are an unused import and a docblock.
- `GenerateTtsSegmentsJobV2::processSegment()` + `processSegmentWithFallback()` (:360-541, ~180 lines) are never called — the live copy of that logic is `GenerateTtsForSegmentJob`, whose helpers (`fitAudioToSlot`, `stripSilence`, `probeAudioDuration`, `buildAtempoChain`…) are *also* still duplicated in V2.
- `F5Tts/F5TtsClient.php`, `Xtts/XttsClient.php`, and `AishaTtsDriver.php` have zero live callers (F5/XTTS matching the recorded decision to abandon those engines).
- `EmotionDSPProcessor` (518 lines) is reachable only via the non-default `hybrid_uzbek` driver, and `NaturalSpeechProcessor` applies intensity-scaled `tremolo`/`vibrato` per segment on the V2 path (`GenerateTtsForSegmentJob.php:140`) — precisely the per-segment emotion prosody that was decided against.
- `GenerateTtsSegmentsJobV2.php:143-155` holds a queue worker hostage for up to 30 minutes in a `while { sleep(5) }` DB-polling loop waiting for the segment jobs it just dispatched — with 4 TTS workers total, one orchestrator job consumes 25% of TTS capacity doing nothing.

**Proposed simpler solution.** One `SegmentAudioService` with `synthesize()` (retry loop, not the hand-written 5-row retry matrix at ProcessInstantDubSegmentJob:264-270 whose rows 3-5 are identical), `fitToSlot()`, and `stripSilence()`. All four jobs call it; each keeps only its flow-specific storage/dispatch logic. Replace the V2 polling loop with `Bus::batch(...)->then(new MixDubbedAudioJob(...))`.

Delete outright: `ActingDirector`, `F5TtsClient`, `XttsClient`, `AishaTtsDriver`, V2's dead methods, and — given the explicit no-emotion-DSP decision — `EmotionDSPProcessor` + the tremolo/vibrato calls in `NaturalSpeechProcessor` (or the whole service if breath insertion is also unwanted; V2's own docblock at :448 says breath insertion was "removed," yet the code still ships).

**That's ~1,800–2,300 lines deleted with zero behavior change on default config**, plus a worker freed from the sleep loop.

### 2.4 The 1,790-line `InstantDubController`

**Original complex area.** One controller does five unrelated jobs: session API (`start`/`poll`/`stop`), an SSE event stream, HLS master-playlist rewriting (~180 lines of line-by-line M3U8 surgery in `hlsMaster`), dynamic playlist/segment serving with **on-request ffmpeg transcoding** (`hlsBgSliceSegment` runs ffmpeg inside the HTTP request at :953-962; `generateLeadFromHls` downloads and transcodes the intro under a `flock` at :1303-1348), and heuristics like `hlsSliceNeedsRefresh`'s "slice should be ≥ 65% of the proportional source bytes" guess (:995-1000). Notably:

- **The SSE `events` endpoint (:298-407) has no in-repo consumer** — the web page and Chrome extension both use `poll`. Unless an external PlayerKit integration calls it, it's ~110 dead lines duplicating poll's payload-building (a third copy of the `hls` response array).
- **Every segment route is registered twice** — 8 `.ts` routes and 8 mirror `.aac` routes (routes/api.php:53-66), but `hlsAudioPlaylist` only ever emits `.ts` URIs now. The `.aac` set plus `hlsAudioSegment`'s on-demand generation fallback (`generateAacSegment`, `computeSlotBounds`, `silentAacResponse`, `silentAacOfDuration` — the last appears entirely uncalled) look like the pre-`.ts` legacy path, ~250 lines.
- `resolveHlsPlayableState` (:1597-1664) mutates session state from GET endpoints, with a 7-condition hand-written change detector.

**Proposed simpler solution.** Split along the existing seams into three small controllers:

1. `InstantDubSessionController` — start/poll/stop (~250 lines)
2. `HlsPlaylistController` — master rewrite + audio/subtitle playlists
3. `HlsSegmentController` — file serving

Move the M3U8 parsing/rewriting into an `HlsMasterRewriter` class that is unit-testable without HTTP. Confirm whether anything external consumes SSE and the `.aac` routes (check PlayerKit integration snippets / nginx access logs); if not, delete both sets. Keep the on-request ffmpeg slicing — it's load-bearing for seek behavior — but it becomes ~80 focused lines in the segment controller instead of being interleaved with playlist logic.

**Estimate: 1,790 → ~900 lines across three files**, with the playlist rewriter finally testable.

### 2.5 A 500-line subtitle-scraping library inside a queue job

**Original complex area.** `PrepareInstantDubJob` (946 lines) is three programs in one file: lines 436-935 are a general-purpose subtitle library (HLS track scraping, VTT→SRT conversion, a dual-language "EN timing + RU text" merge gated on a cue-ratio ≥ 0.75 heuristic, YouTube yt-dlp with two attempt profiles), wrapped around a 278-line `handle()` that also builds voice maps and dispatches waves.

**Proposed simpler solution.** Extract `app/Services/SubtitleFetcher.php` (~250 lines) with one public method: `fetch(url, srt, lang): SubtitleResult`, keeping the fallback chain (SRT → HLS tracks → YouTube) as three small private strategies. The job becomes ~120 lines: fetch → build plan → dispatch batch. This is pure code motion — zero behavior change — and it makes the subtitle logic reusable by the premium-dub flow, which will need it.

### 2.6 Readiness logic rebuilt from floats on every read

**Original complex area.** `InstantDubHlsReadiness` reconstructs the ready-runway on every playlist request by scanning float start/end times with an `OVERLAP_EPSILON = 0.05` sprinkled across ~8 comparisons, maintains two chunk-lookup strategies (by index and by 0.05s time-matching) because chunk identity isn't stable, re-runs the same bracket/`♪` text-cleaning regex that Prepare already applied (:244-245 vs Prepare:121-122), and returns a 9-key array most callers use two fields of.

**Proposed simpler solution.** This logic is *essential* (it's the HLS-switch bug fix) but its *representation* is accidental. Make chunk index the single identity: at plan time, store per-bg-chunk `expected_speech_count` in the Redis hash; at mix time, mark `dub_ready`. Readiness then reduces to "walk indices 0..N, stop at the first not-ready chunk" — integer contiguity, no epsilons, no re-filtering, and `readyWindow()` returns `{ready, continuous_until}`. ~321 → ~120 lines with the same externally observable switching behavior.

---

## 3. Refactoring Recommendations & Next Steps

Sequenced by risk — each phase ships independently and each improvement is verifiable before the next:

1. **Delete dead code (zero risk, ~2,300 lines).** `ActingDirector`, `F5TtsClient`, `XttsClient`, `AishaTtsDriver`, V2's `processSegment`/`processSegmentWithFallback`, the `$forceVoice` branches, the default-dead local/Uzbek translator blocks, `silentAacOfDuration`. Verify with grep + a full flow run before each removal.
2. **Extract shared services (low risk, pure code motion).** `TranslationService`, `SegmentAudioService`, `SubtitleFetcher`, `HlsMasterRewriter`. No logic changes — move, dedupe, point both callers at one copy.
3. **Confirm-then-delete the questionable surfaces.** SSE `events`, the `.aac` route set, `EmotionDSPProcessor`/`NaturalSpeechProcessor` prosody. These need one check each (external consumers; whether V2 output audibly changes without tremolo) rather than assumption.
4. **Replace the wave machine with `Bus::batch` (highest risk, do last).** This changes runtime orchestration behavior. Test with a long movie on staging first, and keep the readiness invariants (runway seconds, verified-format gate) untouched — they encode real player bugs.

**Trade-offs to accept.** The batch refactor requires the `job_batches` table and slightly changes wave pacing (framework-throttled rather than 80%-triggered) — a fair trade for deleting the CAS/Lua machinery. Keeping on-request ffmpeg slicing means requests can still take ~1s on cache miss; pre-generating slices would remove that but adds back precomputation complexity — not worth it at current traffic.

**Avoiding this in future AI-generated code.** The pattern throughout is *additive generation*: each fix or flow was appended beside the old one (V2 next to a dead V1-shaped method, MicroBatch pasted from Batch, `.ts` routes beside `.aac`) with nothing removed. Two habits prevent it:

1. When asking for a new variant of existing behavior, explicitly instruct "modify/replace the existing implementation, delete the superseded path."
2. After any fallback is added, ask "under default config, can this branch execute?" — most of the dead weight here would have failed that one question.

**Net effect if all phases land: the instant-dub feature goes from roughly 7,500 lines to ~3,000 with identical user-visible behavior.**
