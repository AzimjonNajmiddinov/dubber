# PlayerKit HLS Integration

## Overview

The dubbing server exposes an HLS proxy API that serves a **modified master playlist**. Before the dub runway is verified it preserves the original audio; after `playable=true` it injects the dubbed audio track and subtitle track as `#EXT-X-MEDIA` alternate renditions. AVPlayer / PlayerKit auto-discovers these tracks and shows them in the audio/subtitle selection menus.

Video segments are served **directly from the CDN** (not proxied) for optimal performance. Only the dub audio and subtitles go through our server.

```
┌────────────┐         ┌──────────────────────────────────────┐
│  PlayerKit │         │          dubbing.uz                  │
│  (AVPlayer)│         │                                      │
│            │         │                                      │
│ 1. POST ───────────► │  /api/instant-dub/start              │
│    start   │◄─────── │  → session_id                        │
│            │         │                                      │
│ 2. SSE  ───────────► │  /api/instant-dub/{sid}/events       │
│    stream  │◄─────── │  → real-time progress + errors       │
│            │         │                                      │
│ 3. GET  ───────────► │  /api/instant-dub/{sid}/master.m3u8  │
│    master  │◄─────── │  → modified playlist                 │
│            │         │                                      │
│ 4. GET  ──────────►  │  CDN (direct, not proxied)           │
│    video   │◄──────  │  → video segments from CDN           │
│            │         │                                      │
│ 5. GET  ───────────► │  /api/instant-dub/{sid}/dub-audio    │
│    dub m3u8│◄─────── │  → audio playlist (EVENT, grows)     │
│            │         │                                      │
│ 6. GET  ───────────► │  /api/instant-dub/{sid}/dub-segment/ │
│    .ts     │◄─────── │  → MPEG-TS audio (TTS + background)  │
│            │         │                                      │
│ 7. GET  ───────────► │  /api/instant-dub/{sid}/dub-subtitles│
│    subs    │◄─────── │  → WebVTT subtitle track             │
└────────────┘         └──────────────────────────────────────┘
```

## API Endpoints

### 1. Start a dub session

```
POST /api/instant-dub/start
Content-Type: application/json

{
  "video_url": "https://cdn.example.com/path/to/master.m3u8?token=...",
  "language": "uz",
  "translate_from": "auto",
  "title": "Breaking Bad S01E01"
}
```

**Fields:**

| Field            | Required | Description                                                         |
|------------------|----------|---------------------------------------------------------------------|
| `video_url`      | yes      | Full HLS master playlist URL (with token if any)                   |
| `language`       | yes      | Target dub language (`uz`, `ru`, `en`, etc.)                       |
| `translate_from` | no       | Source language for translation (`auto` = detect). Omit if same as target. |
| `title`          | **yes**  | Human-readable title of the content (show + episode name). Used for the admin panel dub list and cache lookup. Example: `"Breaking Bad S01E01"` |
| `quality`        | no       | `standard` (Edge TTS, default) or `premium` (ElevenLabs)           |

> **`title` is required.** Without it, dubbed content will show as "Untitled" in the admin panel and cannot be easily identified or re-used. Send the show name and episode in a searchable format, e.g. `"Squid Game S02E03"`.

**Response:**
```json
{
  "session_id": "550e8400-e29b-41d4-a716-446655440000",
  "hls_url": "https://dubbing.uz/api/instant-dub/550e8400-e29b-41d4-a716-446655440000/master.m3u8",
  "master_url": "https://dubbing.uz/api/instant-dub/550e8400-e29b-41d4-a716-446655440000/master.m3u8",
  "dub_audio_url": "https://dubbing.uz/api/instant-dub/550e8400-e29b-41d4-a716-446655440000/dub-audio.m3u8",
  "subtitles_url": "https://dubbing.uz/api/instant-dub/550e8400-e29b-41d4-a716-446655440000/dub-subtitles.m3u8"
}
```

If the video was previously dubbed and cached, the server returns immediately with the cached result — no re-processing occurs. If an admin edited the translation, only TTS is re-run (no re-translation).

The server begins:
1. Fetching subtitles from the HLS stream
2. Downloading the original audio track for background mixing
3. Translating subtitles via GPT-4o
4. Generating TTS audio via Edge TTS

### 2. Server-Sent Events (real-time updates)

```
GET /api/instant-dub/{session_id}/events
```

Keeps a persistent connection open and streams events as they happen. **Use this instead of polling.**

**Event types:**

| Event     | Description                              |
|-----------|------------------------------------------|
| `update`  | Status/progress changed                  |
| `warning` | Transient error (429 rate limit, retries) |
| `done`    | Session finished (complete/stopped/error) |

**Example stream:**
```
event: update
data: {"status":"preparing","progress":"Fetching subtitles...","segments_ready":0,"total_segments":0}

event: update
data: {"status":"preparing","progress":"Downloading background audio...","segments_ready":0,"total_segments":0}

event: update
data: {"status":"Translating...","progress":"Translating (1/5)...","segments_ready":0,"total_segments":75}

event: warning
data: {"message":"OpenAI rate limited, retrying in 4s... (attempt 1/4)"}

event: update
data: {"status":"processing","progress":"Generating audio (2/5)...","segments_ready":12,"total_segments":75,"playable":false}

event: update
data: {"status":"processing","segments_ready":24,"total_segments":75,"playable":true,"hls":{"playable":true,"hls_url":"https://dubbing.uz/api/instant-dub/.../master.m3u8","ready_seconds":180,"required_seconds":180,"continuous_until":225}}

event: update
data: {"status":"complete","segments_ready":75,"total_segments":75,"progress":null,"playable":true}

event: done
data: {"status":"complete"}
```

**`update` data fields:**

| Field             | Type    | Description                                    |
|-------------------|---------|------------------------------------------------|
| `status`          | string  | `preparing`, `Translating...`, `processing`, `complete`, `stopped`, `error` |
| `progress`        | string? | Human-readable description of current activity |
| `segments_ready`  | int     | Number of TTS segments generated so far        |
| `total_segments`  | int     | Total segments to generate (0 until known)     |
| `playable`        | bool    | `true` only after the verified HLS dubbed runway is ready. Switch only on this, never on segment count. |
| `hls`             | object? | HLS readiness metrics and URLs (`hls_url`, `ready_seconds`, `required_seconds`, `continuous_until`) |
| `error`           | string? | Error message if status is `error`             |

### 3. Master playlist (modified HLS)

```
GET /api/instant-dub/{session_id}/master.m3u8
```

Returns the original HLS master playlist with modifications:
- **Video segment URIs** are rewritten to absolute CDN URLs (direct, not proxied through our server)
- **Dub audio track** injected as `#EXT-X-MEDIA` only after `playable=true`
- **Dub subtitle track** injected as `#EXT-X-MEDIA TYPE=SUBTITLES`
- All existing audio/subtitle tracks are preserved

Example output:
```m3u8
#EXTM3U
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio0",NAME="LE-Production",LANGUAGE="ru",URI="https://cdn.example.com/.../index-f1-a1.m3u8?token=..."
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio0",NAME="O'zbek dublyaj",LANGUAGE="uz",URI="dub-audio.m3u8",DEFAULT=YES,AUTOSELECT=YES
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs0",NAME="O'zbek",LANGUAGE="uz",URI="dub-subtitles.m3u8",DEFAULT=NO,AUTOSELECT=YES,FORCED=NO
#EXT-X-STREAM-INF:BANDWIDTH=3000000,RESOLUTION=1280x720,AUDIO="audio0",SUBTITLES="subs0"
https://cdn.example.com/.../index-f1-v1.m3u8?token=...
```

### 4. Dubbed audio playlist

```
GET /api/instant-dub/{session_id}/dub-audio.m3u8
```

Returns an `EVENT`-type playlist that grows as verified mixed chunks finish:
```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:EVENT
#EXTINF:30.000,
dub-segment/bg-0.ts
#EXTINF:15.000,
dub-segment/source-bg-1-to-15000.ts
#EXTINF:15.000,
dub-segment/bg-1-from-15000.ts
#EXTINF:30.000,
dub-segment/bg-2.ts
```

- **`dub-segment/bg-{index}.ts`** — timestamped MPEG-TS audio with translated TTS mixed over source background
- **`dub-segment/source-bg-{index}-to-{offsetMs}.ts`** — original/source audio before the known dub start time
- When all segments are ready, `#EXT-X-ENDLIST` is appended
- AVPlayer automatically re-fetches `EVENT` playlists to discover new segments

### 5. Audio segments

```
GET /api/instant-dub/{session_id}/dub-segment/bg-{index}.ts
```

Returns MPEG-TS audio. Each segment is:
- Padded/trimmed to exact subtitle slot duration (start_time → end_time)
- Mixed with original video audio at 30% volume (background)
- Cached after first generation (session TTL)

### 6. Source lead/slice segments

```
GET /api/instant-dub/{session_id}/dub-segment/source-bg-{index}-to-{offsetMs}.ts
```

Returns source audio for the pre-dub part of a chunk. This keeps the alternate audio timeline aligned before the first translated line starts.

### 7. Subtitle playlist + VTT

```
GET /api/instant-dub/{session_id}/dub-subtitles.m3u8
GET /api/instant-dub/{session_id}/dub-subtitles.vtt
```

WebVTT subtitles of the translated text. Appears in PlayerKit's subtitle menu.

### 8. Poll (legacy, use SSE instead)

```
GET /api/instant-dub/{session_id}/poll?after=-1
```

Returns current status and new chunks (base64 audio). Used by the web UI. **iOS apps should use the `/events` SSE endpoint instead.**

### 9. Stop session

```
POST /api/instant-dub/{session_id}/stop
```

Stops processing, cleans up audio files and Redis data.

---

## Swift Integration (PlayerKit)

### DubAPI helper with SSE

```swift
import Foundation

enum DubAPI {
    static let baseURL = "https://dubbing.uz/api/instant-dub"

    // MARK: - Models

    struct StartResponse: Decodable {
        let session_id: String
        let hls_url: String
        let master_url: String
    }

    struct SessionUpdate: Decodable {
        struct HlsState: Decodable {
            let playable: Bool
            let hls_url: String?
            let master_url: String?
            let ready_seconds: Double?
            let required_seconds: Double?
            let continuous_until: Double?
        }

        let status: String
        let segments_ready: Int
        let total_segments: Int
        let playable: Bool?
        let hls: HlsState?
        let progress: String?
        let error: String?
    }

    struct SessionWarning: Decodable {
        let message: String
    }

    // MARK: - Start session

    static func startSession(
        videoURL: URL,
        title: String,
        language: String = "uz",
        translateFrom: String = "auto"
    ) async throws -> StartResponse {
        var request = URLRequest(url: URL(string: "\(baseURL)/start")!)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        let body: [String: String] = [
            "video_url": videoURL.absoluteString,
            "title": title,
            "language": language,
            "translate_from": translateFrom,
        ]
        request.httpBody = try JSONEncoder().encode(body)

        let (data, _) = try await URLSession.shared.data(for: request)
        return try JSONDecoder().decode(StartResponse.self, from: data)
    }

    // MARK: - HLS URLs

    static func masterPlaylistURL(sessionId: String) -> URL {
        URL(string: "\(baseURL)/\(sessionId)/master.m3u8")!
    }

    static func eventsURL(sessionId: String) -> URL {
        URL(string: "\(baseURL)/\(sessionId)/events")!
    }

    // MARK: - Stop session

    static func stop(sessionId: String) async throws {
        var request = URLRequest(url: URL(string: "\(baseURL)/\(sessionId)/stop")!)
        request.httpMethod = "POST"
        _ = try await URLSession.shared.data(for: request)
    }
}
```

### SSE event reader

```swift
import Foundation

/// Reads Server-Sent Events from the /events endpoint.
class DubEventSource {
    private var task: URLSessionDataTask?
    private let sessionId: String

    var onUpdate: ((DubAPI.SessionUpdate) -> Void)?
    var onWarning: ((String) -> Void)?
    var onDone: ((String) -> Void)?
    var onReadyToPlay: ((URL) -> Void)?

    private var readyFired = false

    init(sessionId: String) {
        self.sessionId = sessionId
    }

    func start() {
        let url = DubAPI.eventsURL(sessionId: sessionId)
        var request = URLRequest(url: url)
        request.timeoutInterval = 600 // 10 min max session

        let session = URLSession(configuration: .default, delegate: nil, delegateQueue: .main)
        task = session.dataTask(with: request) { [weak self] data, _, _ in
            guard let self, let data else { return }
            self.parseSSE(data: data)
        }

        // Use bytes streaming for real-time SSE parsing
        let streamTask = session.dataTask(with: request)
        // For production, use URLSession delegate with didReceive data
        // or a library like LDSwiftEventSource
        streamTask.resume()
        self.task = streamTask
    }

    func stop() {
        task?.cancel()
        task = nil
    }

    private func parseSSE(data: Data) {
        guard let text = String(data: data, encoding: .utf8) else { return }
        let decoder = JSONDecoder()

        // Split into individual events
        let events = text.components(separatedBy: "\n\n")
        for event in events {
            let lines = event.components(separatedBy: "\n")
            var eventType = ""
            var eventData = ""

            for line in lines {
                if line.hasPrefix("event: ") {
                    eventType = String(line.dropFirst(7))
                } else if line.hasPrefix("data: ") {
                    eventData = String(line.dropFirst(6))
                }
            }

            guard !eventType.isEmpty, !eventData.isEmpty,
                  let jsonData = eventData.data(using: .utf8) else { continue }

            switch eventType {
            case "update":
                if let update = try? decoder.decode(DubAPI.SessionUpdate.self, from: jsonData) {
                    onUpdate?(update)

                    // Switch only after backend verifies a continuous HLS dubbed runway.
                    if !readyFired,
                       update.playable == true || update.hls?.playable == true,
                       let rawURL = update.hls?.hls_url ?? update.hls?.master_url,
                       let url = URL(string: rawURL) {
                        readyFired = true
                        onReadyToPlay?(url)
                    }
                }

            case "warning":
                if let warning = try? decoder.decode(DubAPI.SessionWarning.self, from: jsonData) {
                    onWarning?(warning.message)
                }

            case "done":
                if let data = try? decoder.decode([String: String].self, from: jsonData) {
                    onDone?(data["status"] ?? "complete")
                }
                stop()

            default:
                break
            }
        }
    }
}
```

### Full integration with SSE + PlayerKit

```swift
import PlayerKit
import SwiftUI

class DubPlayerViewModel: ObservableObject {
    @Published var isLoading = true
    @Published var progress: Double = 0
    @Published var statusText: String = "Starting..."
    @Published var warning: String?

    private var sessionId: String?
    private var eventSource: DubEventSource?

    let player = PlayerKit.Player()

    func startDub(videoURL: URL) {
        Task {
            do {
                // 1. Create session
                let start = try await DubAPI.startSession(videoURL: videoURL, title: "Show Name S01E01")
                let sid = start.session_id
                self.sessionId = sid

                // 2. Connect SSE for real-time updates
                let events = DubEventSource(sessionId: sid)

                events.onUpdate = { [weak self] update in
                    guard let self else { return }
                    if update.total_segments > 0 {
                        self.progress = Double(update.segments_ready) / Double(update.total_segments)
                    }
                    self.statusText = update.progress ?? update.status
                }

                events.onWarning = { [weak self] message in
                    self?.warning = message
                    // Auto-dismiss warning after 3s
                    DispatchQueue.main.asyncAfter(deadline: .now() + 3) {
                        self?.warning = nil
                    }
                }

                events.onReadyToPlay = { [weak self] masterURL in
                    guard let self else { return }
                    // 3. Switch/start playback only after the backend says HLS is playable.
                    self.player.load(url: masterURL)
                    self.player.play()
                    self.isLoading = false
                    // Dub audio track appears in PlayerKit audio menu as "O'zbek dublyaj"
                    // Subtitle track appears as "O'zbek"
                }

                events.onDone = { [weak self] status in
                    self?.statusText = status == "complete" ? "Dub ready" : "Error"
                }

                events.start()
                self.eventSource = events

            } catch {
                await MainActor.run {
                    statusText = "Error: \(error.localizedDescription)"
                    isLoading = false
                }
            }
        }
    }

    func stop() {
        eventSource?.stop()
        if let sid = sessionId {
            Task { try? await DubAPI.stop(sessionId: sid) }
        }
    }
}
```

**Note:** For production SSE in Swift, consider using [LDSwiftEventSource](https://github.com/launchdarkly/swift-eventsource) which handles reconnection, buffered parsing, and edge cases. The example above shows the concept.

---

## Recommended Flow

```
User taps "Dub"
       │
       ▼
POST /start → session_id
       │
       ▼
Connect SSE /events ◄──── shows progress bar, warnings
       │
       │ (waits for playable=true / hls.playable=true)
       │
       ▼
Load master.m3u8 into AVPlayer
       │
       ├─► Video plays from CDN (direct, fast)
       ├─► Dub audio track appears in menu
       ├─► Subtitle track appears in menu
       │
       │ (dub audio is default after verified HLS runway is ready)
       │
       ▼
AVPlayer switches to dub audio
       │
       ├─► TTS voice (100%) + original audio (30%) during dialogue
       ├─► Original audio (30%) during gaps
       │
       ▼
SSE "done" event → session complete
```

## Audio Mixing

Each dub audio segment is a mix of:
- **TTS voice at 100% volume** — the translated dubbed dialogue
- **Original video audio at 30% volume** — background sounds, music, ambient

This creates a natural dubbing experience where the viewer hears the translated voice with the original soundtrack underneath. During gaps between dialogue, only the original audio at 30% plays (no silence).

The original audio is downloaded from the HLS audio track specified in the master playlist (e.g., the Russian or English track).

## Notes

- **Wait for HLS readiness before switching**: keep playing original video/audio until SSE or poll returns `playable=true` / `hls.playable=true`, then reload `hls.hls_url` or `hls.master_url` and select the dubbed audio track. Do not switch based on `segments_ready`.
- **CDN direct**: Video segments go directly to the CDN, not through our server. Only dub audio/subtitles go through `dubbing.uz`.
- **Subtitle track**: Translated subtitles appear in PlayerKit's subtitle menu alongside any original subtitles from the source video.
- **Background audio**: Original audio at 30% plays during both dialogue and gaps. Falls back to silence if the source has no separate audio track.
- **Sessions expire** after 14 hours. Call `POST /stop` to clean up earlier.
- **The web UI** (`/instant-dub` page) uses the HLS readiness switch for `.m3u8` sources and the poll+base64 flow for non-HLS sources.
