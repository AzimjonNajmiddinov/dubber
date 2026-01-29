<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Segment Player - Video #{{ $video->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 20px;
            background: #111;
            color: #fff;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 16px 0;
            color: #fff;
        }
        .video-wrapper {
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }
        video {
            width: 100%;
            max-height: 70vh;
            display: block;
        }
        .subtitle-container {
            position: absolute;
            bottom: 60px;
            left: 0;
            right: 0;
            text-align: center;
            padding: 0 20px;
        }
        .subtitle {
            display: inline-block;
            background: rgba(0, 0, 0, 0.8);
            color: #fff;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 18px;
            line-height: 1.4;
            max-width: 80%;
        }
        .segment-indicators {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
            background: #1a1a1a;
            border-radius: 0 0 8px 8px;
            overflow-x: auto;
            justify-content: center;
            flex-wrap: wrap;
        }
        .segment-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #444;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .segment-dot:hover {
            transform: scale(1.2);
        }
        .segment-dot.ready {
            background: #22c55e;
        }
        .segment-dot.generating {
            background: #f97316;
            animation: pulse 1s infinite;
        }
        .segment-dot.current {
            background: #3b82f6;
            transform: scale(1.3);
        }
        .segment-dot.error {
            background: #ef4444;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .info {
            margin-top: 16px;
            padding: 12px 16px;
            background: #222;
            border-radius: 8px;
            font-size: 14px;
        }
        .info-row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            align-items: center;
        }
        .info-item {
            color: #888;
        }
        .info-item strong {
            color: #fff;
        }
        .status-text {
            font-size: 14px;
            color: #888;
        }
        .status-text.loading {
            color: #f97316;
        }
        .segment-text {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #333;
        }
        .segment-text .original {
            color: #666;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .segment-text .translated {
            color: #fff;
            font-size: 15px;
        }
        .actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 6px;
            border: none;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn-primary {
            background: #fff;
            color: #111;
        }
        .btn-secondary {
            background: #333;
            color: #fff;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .controls {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        .controls .btn {
            padding: 8px 12px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Segment Player - Video #{{ $video->id }}</h1>

        <div class="video-wrapper">
            <video id="player" controls>
                <source id="video-source" src="" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <div class="subtitle-container">
                <div class="subtitle" id="subtitle" style="display: none;"></div>
            </div>
        </div>

        <div class="segment-indicators" id="indicators">
            @foreach($segments as $index => $segment)
                <div class="segment-dot"
                     data-segment-id="{{ $segment->id }}"
                     data-index="{{ $index }}"
                     title="Segment {{ $index + 1 }}"></div>
            @endforeach
        </div>

        <div class="info">
            <div class="info-row">
                <div class="info-item">
                    Segment: <strong id="current-segment">1</strong> / <strong>{{ count($segments) }}</strong>
                </div>
                <div class="info-item">
                    Status: <span class="status-text" id="status">Loading...</span>
                </div>
                <div class="info-item">
                    Language: <strong>{{ strtoupper($video->target_language) }}</strong>
                </div>
            </div>
            <div class="segment-text" id="segment-text">
                <div class="original" id="original-text"></div>
                <div class="translated" id="translated-text"></div>
            </div>
            <div class="controls">
                <button class="btn btn-secondary" id="prev-btn" disabled>Previous</button>
                <button class="btn btn-secondary" id="next-btn">Next</button>
                <button class="btn btn-secondary" id="autoplay-btn">Autoplay: ON</button>
            </div>
        </div>

        <div class="actions">
            <a href="{{ route('stream.player', $video) }}" class="btn btn-secondary">Full Video Player</a>
            <a href="{{ route('videos.show', $video) }}" class="btn btn-secondary">Video Details</a>
            <a href="{{ route('videos.index') }}" class="btn btn-secondary">All Videos</a>
        </div>
    </div>

    <script>
        const videoId = {{ $video->id }};
        const manifestUrl = '/api/player/{{ $video->id }}/manifest';
        const prefetchUrl = '/api/player/{{ $video->id }}/prefetch';

        let segments = [];
        let currentIndex = 0;
        let autoplay = true;
        let prefetchInProgress = false;

        const player = document.getElementById('player');
        const videoSource = document.getElementById('video-source');
        const indicators = document.getElementById('indicators');
        const statusEl = document.getElementById('status');
        const currentSegmentEl = document.getElementById('current-segment');
        const originalTextEl = document.getElementById('original-text');
        const translatedTextEl = document.getElementById('translated-text');
        const subtitleEl = document.getElementById('subtitle');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        const autoplayBtn = document.getElementById('autoplay-btn');

        // Load manifest
        async function loadManifest() {
            try {
                const response = await fetch(manifestUrl);
                const data = await response.json();
                segments = data.segments;
                updateIndicators();
                loadSegment(0);
            } catch (error) {
                console.error('Failed to load manifest:', error);
                statusEl.textContent = 'Error loading manifest';
                statusEl.classList.add('loading');
            }
        }

        // Update segment indicators
        function updateIndicators() {
            const dots = indicators.querySelectorAll('.segment-dot');
            dots.forEach((dot, index) => {
                dot.classList.remove('ready', 'generating', 'current', 'error');

                if (index === currentIndex) {
                    dot.classList.add('current');
                } else if (segments[index]) {
                    if (segments[index].ready) {
                        dot.classList.add('ready');
                    } else if (segments[index].generating) {
                        dot.classList.add('generating');
                    }
                }
            });
        }

        // Load a specific segment
        async function loadSegment(index) {
            if (index < 0 || index >= segments.length) return;

            currentIndex = index;
            const segment = segments[index];

            // Update UI
            currentSegmentEl.textContent = index + 1;
            originalTextEl.textContent = segment.text || '';
            translatedTextEl.textContent = segment.translated_text || '';

            // Update buttons
            prevBtn.disabled = index === 0;
            nextBtn.disabled = index === segments.length - 1;

            // Update indicators
            updateIndicators();

            // Set status
            statusEl.textContent = 'Loading segment...';
            statusEl.classList.add('loading');

            try {
                // Load segment video
                videoSource.src = segment.stream_url;
                player.load();

                // Mark as ready once loaded
                player.oncanplay = () => {
                    statusEl.textContent = 'Playing';
                    statusEl.classList.remove('loading');
                    segments[index].ready = true;
                    updateIndicators();
                };

                player.onerror = () => {
                    statusEl.textContent = 'Error loading segment';
                    statusEl.classList.add('loading');
                };

                // Trigger prefetch for upcoming segments
                prefetchNext();

            } catch (error) {
                console.error('Failed to load segment:', error);
                statusEl.textContent = 'Error: ' + error.message;
            }
        }

        // Prefetch upcoming segments
        async function prefetchNext() {
            if (prefetchInProgress) return;
            prefetchInProgress = true;

            try {
                const response = await fetch(prefetchUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        current_segment_id: segments[currentIndex].id
                    })
                });

                if (response.ok) {
                    // Poll status of next segments
                    pollSegmentStatus();
                }
            } catch (error) {
                console.error('Prefetch error:', error);
            } finally {
                prefetchInProgress = false;
            }
        }

        // Poll status of upcoming segments
        async function pollSegmentStatus() {
            const toCheck = segments.slice(currentIndex + 1, currentIndex + 3);

            for (const segment of toCheck) {
                if (segment.ready) continue;

                try {
                    const response = await fetch(segment.status_url);
                    const data = await response.json();

                    const idx = segments.findIndex(s => s.id === segment.id);
                    if (idx !== -1) {
                        segments[idx].ready = data.ready;
                        segments[idx].generating = data.generating;
                    }
                } catch (error) {
                    console.error('Status check error:', error);
                }
            }

            updateIndicators();

            // Continue polling if there are generating segments
            const hasGenerating = segments.slice(currentIndex + 1, currentIndex + 3)
                .some(s => s.generating && !s.ready);

            if (hasGenerating) {
                setTimeout(pollSegmentStatus, 1000);
            }
        }

        // Handle video ended
        player.addEventListener('ended', () => {
            if (autoplay && currentIndex < segments.length - 1) {
                loadSegment(currentIndex + 1);
                player.play();
            } else if (currentIndex === segments.length - 1) {
                statusEl.textContent = 'Playback complete';
            }
        });

        // Handle video playing - show subtitle
        player.addEventListener('play', () => {
            const segment = segments[currentIndex];
            if (segment && segment.translated_text) {
                subtitleEl.textContent = segment.translated_text;
                subtitleEl.style.display = 'inline-block';
            }
        });

        player.addEventListener('pause', () => {
            // Keep subtitle visible when paused
        });

        // Navigation buttons
        prevBtn.addEventListener('click', () => {
            if (currentIndex > 0) {
                loadSegment(currentIndex - 1);
                player.play();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (currentIndex < segments.length - 1) {
                loadSegment(currentIndex + 1);
                player.play();
            }
        });

        // Autoplay toggle
        autoplayBtn.addEventListener('click', () => {
            autoplay = !autoplay;
            autoplayBtn.textContent = 'Autoplay: ' + (autoplay ? 'ON' : 'OFF');
        });

        // Click on indicator dots
        indicators.addEventListener('click', (e) => {
            const dot = e.target.closest('.segment-dot');
            if (dot) {
                const index = parseInt(dot.dataset.index);
                loadSegment(index);
                player.play();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                prevBtn.click();
            } else if (e.key === 'ArrowRight') {
                nextBtn.click();
            } else if (e.key === ' ') {
                e.preventDefault();
                if (player.paused) {
                    player.play();
                } else {
                    player.pause();
                }
            }
        });

        // Initialize
        loadManifest();
    </script>
</body>
</html>
