# PlayerKit HLS Integration

## Overview

The dubbing server exposes an HLS proxy API that serves a **modified master playlist** with a dubbed audio track injected as an `#EXT-X-MEDIA` alternate rendition. AVPlayer / PlayerKit auto-discovers this track and shows it in the audio selection menu — no client-side HLS parsing needed.

```
┌────────────┐         ┌──────────────────────────────────────┐
│  PlayerKit │         │          dubbing.uz                  │
│  (AVPlayer)│         │                                      │
│            │         │                                      │
│ 1. POST ───────────► │  /api/instant-dub/start              │
│    start   │◄─────── │  → session_id                        │
│            │         │                                      │
│ 2. GET  ───────────► │  /api/instant-dub/{sid}/master.m3u8  │
│    master  │◄─────── │  → modified playlist + dub track     │
│            │         │                                      │
│ 3. GET  ───────────► │  /api/instant-dub/{sid}/proxy/...    │
│    video   │◄─────── │  → proxied video segments            │
│            │         │                                      │
│ 4. GET  ───────────► │  /api/instant-dub/{sid}/dub-audio    │
│    dub m3u8│◄─────── │  → audio playlist (EVENT, grows)     │
│            │         │                                      │
│ 5. GET  ───────────► │  /api/instant-dub/{sid}/dub-segment/ │
│    .aac    │◄─────── │  → AAC audio segments                │
└────────────┘         └──────────────────────────────────────┘
```

## API Endpoints

### 1. Start a dub session

```
POST /api/instant-dub/start
Content-Type: application/json

{
  "video_url": "https://itv.uz/path/to/master.m3u8?token=...",
  "language": "uz",
  "translate_from": "auto"
}
```

**Response:**
```json
{ "session_id": "550e8400-e29b-41d4-a716-446655440000" }
```

The server begins fetching subtitles from the HLS stream, translating them, and generating TTS audio in the background. The session is usable immediately.

### 2. Load the master playlist

```
GET /api/instant-dub/{session_id}/master.m3u8
```

Returns a standard HLS master playlist with:
- All original video variant streams, proxied through the server
- All original subtitle/audio tracks, proxied through the server
- An injected `#EXT-X-MEDIA` line for the dubbed audio track

Example output:
```m3u8
#EXTM3U
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="dub",NAME="O'zbek dublyaj",LANGUAGE="uz",URI="dub-audio.m3u8",DEFAULT=NO,AUTOSELECT=YES
#EXT-X-STREAM-INF:BANDWIDTH=3000000,RESOLUTION=1280x720,AUDIO="dub"
/api/instant-dub/{sid}/proxy/video_720p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1500000,RESOLUTION=854x480,AUDIO="dub"
/api/instant-dub/{sid}/proxy/video_480p.m3u8
```

### 3. Dubbed audio playlist (auto-polled by AVPlayer)

```
GET /api/instant-dub/{session_id}/dub-audio.m3u8
```

Returns an `EVENT`-type playlist that grows as TTS segments finish:
```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:15
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:EVENT
#EXTINF:4.200,
dub-segment/0.aac
#EXTINF:8.500,
dub-segment/1.aac
```

When all segments are ready, `#EXT-X-ENDLIST` is appended. AVPlayer automatically re-fetches EVENT playlists to discover new segments.

### 4. Audio segments

```
GET /api/instant-dub/{session_id}/dub-segment/{index}.aac
```

Returns AAC/ADTS audio. Each segment includes silence padding at the start to align with the video timeline (so subtitle audio plays at the correct moment). Segments are cached after first generation.

### 5. Proxy (transparent)

```
GET /api/instant-dub/{session_id}/proxy/{any_path}
```

Passthrough proxy to the original video server. Appends auth query parameters from the original URL. This is used internally by AVPlayer to fetch video segments, subtitle playlists, etc.

---

## Swift Integration (PlayerKit)

### Minimal example

```swift
import PlayerKit

// 1. Start the dub session
let sessionId = try await DubAPI.startSession(
    videoURL: hlsURL,
    language: "uz"
)

// 2. Build the master playlist URL
let masterURL = URL(string: "https://dubbing.uz/api/instant-dub/\(sessionId)/master.m3u8")!

// 3. Load into PlayerKit — that's it
let player = PlayerKit.Player()
player.load(url: masterURL)
player.play()

// The dub audio track appears automatically in PlayerKit's audio menu
// as "O'zbek dublyaj". User taps it to switch. No extra code needed.
```

### DubAPI helper

```swift
import Foundation

enum DubAPI {
    static let baseURL = "https://dubbing.uz/api/instant-dub"

    struct StartResponse: Decodable {
        let session_id: String
    }

    /// Start a dubbing session. Returns the session ID.
    static func startSession(
        videoURL: URL,
        language: String = "uz",
        translateFrom: String = "auto"
    ) async throws -> String {
        var request = URLRequest(url: URL(string: "\(baseURL)/start")!)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        let body: [String: String] = [
            "video_url": videoURL.absoluteString,
            "language": language,
            "translate_from": translateFrom
        ]
        request.httpBody = try JSONEncoder().encode(body)

        let (data, _) = try await URLSession.shared.data(for: request)
        let response = try JSONDecoder().decode(StartResponse.self, from: data)
        return response.session_id
    }

    /// Build the HLS master playlist URL for a session.
    static func masterPlaylistURL(sessionId: String) -> URL {
        URL(string: "\(baseURL)/\(sessionId)/master.m3u8")!
    }

    /// Poll session status (optional — for showing progress UI).
    struct PollResponse: Decodable {
        let status: String
        let segments_ready: Int
        let total_segments: Int
        let error: String?
    }

    static func poll(sessionId: String) async throws -> PollResponse {
        let url = URL(string: "\(baseURL)/\(sessionId)/poll")!
        let (data, _) = try await URLSession.shared.data(from: url)
        return try JSONDecoder().decode(PollResponse.self, from: data)
    }

    /// Stop and clean up a session.
    static func stop(sessionId: String) async throws {
        var request = URLRequest(url: URL(string: "\(baseURL)/\(sessionId)/stop")!)
        request.httpMethod = "POST"
        _ = try await URLSession.shared.data(for: request)
    }
}
```

### Full integration with progress

```swift
import PlayerKit
import SwiftUI

class DubPlayerViewModel: ObservableObject {
    @Published var isLoading = true
    @Published var progress: Double = 0
    @Published var status: String = "Starting..."

    private var sessionId: String?
    private var pollTimer: Timer?

    let player = PlayerKit.Player()

    func startDub(videoURL: URL) {
        Task {
            do {
                // 1. Create session
                let sid = try await DubAPI.startSession(videoURL: videoURL)
                self.sessionId = sid

                // 2. Start playing immediately — video plays with original audio,
                //    dub track appears in menu as segments become ready
                let masterURL = DubAPI.masterPlaylistURL(sessionId: sid)
                await MainActor.run {
                    player.load(url: masterURL)
                    player.play()
                    isLoading = false
                }

                // 3. Poll for progress (optional — just for UI feedback)
                startPolling(sessionId: sid)

            } catch {
                await MainActor.run {
                    status = "Error: \(error.localizedDescription)"
                }
            }
        }
    }

    private func startPolling(sessionId: String) {
        pollTimer = Timer.scheduledTimer(withTimeInterval: 2.0, repeats: true) { [weak self] timer in
            Task {
                guard let self else { timer.invalidate(); return }
                do {
                    let poll = try await DubAPI.poll(sessionId: sessionId)
                    await MainActor.run {
                        if poll.total_segments > 0 {
                            self.progress = Double(poll.segments_ready) / Double(poll.total_segments)
                            self.status = "Dubbing: \(poll.segments_ready)/\(poll.total_segments)"
                        }
                        if poll.status == "complete" {
                            self.status = "Dub ready"
                            timer.invalidate()
                        }
                        if poll.status == "error" {
                            self.status = "Error: \(poll.error ?? "unknown")"
                            timer.invalidate()
                        }
                    }
                } catch {}
            }
        }
    }

    func stop() {
        pollTimer?.invalidate()
        if let sid = sessionId {
            Task { try? await DubAPI.stop(sessionId: sid) }
        }
    }
}
```

## How It Works

1. **`POST /start`** creates a Redis session and kicks off background jobs: subtitle extraction, translation (GPT-4o), and TTS generation (Edge TTS)

2. **`master.m3u8`** fetches the original HLS manifest from the video server, rewrites all URIs to go through our proxy, and injects the dubbed audio as an `#EXT-X-MEDIA` alternate rendition

3. **AVPlayer loads the master playlist**, sees the video streams + the dubbed audio track. Video starts playing immediately with original audio

4. **The dub audio playlist** (`dub-audio.m3u8`) is an `EVENT` type — AVPlayer re-fetches it periodically and discovers new segments as TTS finishes. Each segment includes silence padding to align with the video timeline

5. **User taps the audio track selector** in PlayerKit and sees "O'zbek dublyaj". Selecting it switches to the dubbed audio. The original audio track remains available to switch back

6. **All original content is proxied** through the server to avoid mixed-content / CORS issues. Auth tokens from the original URL are forwarded automatically

## Notes

- The dubbed audio track may not appear in the PlayerKit menu until the first few segments are ready (AVPlayer needs at least one segment to show the track)
- During gaps between subtitles, the dubbed audio track plays silence. The user hears the dubbed voice only during dialogue
- Sessions expire after 14 hours. Call `/stop` to clean up earlier
- The web-based instant-dub UI (`/instant-dub` page) is completely unaffected — it uses the poll+base64 flow, not HLS
