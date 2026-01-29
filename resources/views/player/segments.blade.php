<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Live Dubbing - Video #{{ $video->id }}</title>
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
            min-height: 400px;
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
        /* Loading overlay */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        .loading-overlay.hidden {
            display: none;
        }
        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #333;
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-text {
            color: #888;
            font-size: 16px;
            text-align: center;
        }
        .loading-progress {
            width: 200px;
            height: 4px;
            background: #333;
            border-radius: 2px;
            overflow: hidden;
        }
        .loading-progress-bar {
            height: 100%;
            background: #22c55e;
            width: 0%;
            transition: width 0.3s;
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
            min-height: 36px;
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
        .segment-dot.pending {
            background: #444;
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
        .status-text.ready {
            color: #22c55e;
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
            flex-wrap: wrap;
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
        <h1>Live Dubbing - Video #{{ $video->id }}</h1>

        <div class="video-wrapper">
            <video id="player" controls>
                <source id="video-source" src="" type="video/mp4">
                Your browser does not support the video tag.
            </video>
            <div class="subtitle-container">
                <div class="subtitle" id="subtitle" style="display: none;"></div>
            </div>
            <div class="loading-overlay" id="loading-overlay">
                <div class="spinner"></div>
                <div class="loading-text" id="loading-text">Preparing video...</div>
                <div class="loading-progress">
                    <div class="loading-progress-bar" id="loading-progress-bar"></div>
                </div>
            </div>
        </div>

        <div class="segment-indicators" id="indicators">
            <!-- Populated dynamically -->
        </div>

        <div class="info">
            <div class="info-row">
                <div class="info-item">
                    Segment: <strong id="current-segment">-</strong> / <strong id="total-segments">-</strong>
                </div>
                <div class="info-item">
                    Ready: <strong id="ready-segments">0</strong>
                </div>
                <div class="info-item">
                    Status: <span class="status-text" id="status">Initializing...</span>
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
                <button class="btn btn-secondary" id="next-btn" disabled>Next</button>
                <button class="btn btn-secondary" id="autoplay-btn">Autoplay: ON</button>
            </div>
        </div>

        <div class="actions">
            <a href="{{ route('stream.live') }}" class="btn btn-secondary">New Video</a>
            <a href="{{ route('videos.show', $video) }}" class="btn btn-secondary">Video Details</a>
            <a href="{{ route('videos.index') }}" class="btn btn-secondary">All Videos</a>
        </div>
    </div>

    <script>
        const videoId = {{ $video->id }};
        const manifestUrl = '/api/player/{{ $video->id }}/manifest';
        const prefetchUrl = '/api/player/{{ $video->id }}/prefetch';
        const statusUrl = '/api/live/{{ $video->id }}/status';

        let segments = [];
        let currentIndex = 0;
        let autoplay = true;
        let prefetchInProgress = false;
        let isPlaying = false;
        let pollInterval = null;

        const player = document.getElementById('player');
        const videoSource = document.getElementById('video-source');
        const indicators = document.getElementById('indicators');
        const statusEl = document.getElementById('status');
        const currentSegmentEl = document.getElementById('current-segment');
        const totalSegmentsEl = document.getElementById('total-segments');
        const readySegmentsEl = document.getElementById('ready-segments');
        const originalTextEl = document.getElementById('original-text');
        const translatedTextEl = document.getElementById('translated-text');
        const subtitleEl = document.getElementById('subtitle');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        const autoplayBtn = document.getElementById('autoplay-btn');
        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingText = document.getElementById('loading-text');
        const loadingProgressBar = document.getElementById('loading-progress-bar');

        // Status messages for different pipeline stages
        const statusMessages = {
            'pending': 'Waiting to start...',
            'downloading': 'Downloading video...',
            'uploaded': 'Video downloaded',
            'audio_extracted': 'Extracting audio...',
            'transcribing': 'Transcribing speech...',
            'transcribed': 'Processing segments...',
            'streaming_ready': 'Ready to play',
        };

        // Initialize - poll for status until segments are ready
        async function initialize() {
            loadingText.textContent = 'Checking video status...';

            // Start polling for status
            pollInterval = setInterval(checkStatus, 2000);
            checkStatus();
        }

        async function checkStatus() {
            try {
                const response = await fetch(statusUrl);
                const data = await response.json();

                const statusText = statusMessages[data.status] || data.status;
                loadingText.textContent = statusText;
                loadingProgressBar.style.width = data.progress + '%';

                totalSegmentsEl.textContent = data.total_segments || '-';
                readySegmentsEl.textContent = data.ready_segments || '0';

                // If we have ready segments, load manifest and start playing
                if (data.ready_segments > 0 || data.can_play) {
                    clearInterval(pollInterval);
                    loadManifest();
                }
            } catch (error) {
                console.error('Status check error:', error);
            }
        }

        // Load manifest and start playing
        async function loadManifest() {
            try {
                const response = await fetch(manifestUrl);
                const data = await response.json();

                segments = data.segments;
                totalSegmentsEl.textContent = segments.length;

                // Update indicators
                renderIndicators();
                updateIndicators();

                // Find first ready segment
                const firstReady = segments.findIndex(s => s.has_tts);

                if (firstReady >= 0) {
                    // Hide loading, start playing
                    loadingOverlay.classList.add('hidden');
                    loadSegment(firstReady);
                    player.play();
                    isPlaying = true;

                    // Start polling for more segments
                    startSegmentPolling();
                } else {
                    // No ready segments yet, keep polling
                    loadingText.textContent = 'Waiting for first segment...';
                    setTimeout(loadManifest, 2000);
                }
            } catch (error) {
                console.error('Failed to load manifest:', error);
                statusEl.textContent = 'Error loading manifest';
                loadingText.textContent = 'Error: ' + error.message;
            }
        }

        function renderIndicators() {
            indicators.innerHTML = '';
            segments.forEach((seg, index) => {
                const dot = document.createElement('div');
                dot.className = 'segment-dot';
                dot.dataset.segmentId = seg.id;
                dot.dataset.index = index;
                dot.title = `Segment ${index + 1}`;
                indicators.appendChild(dot);
            });
        }

        function updateIndicators() {
            const dots = indicators.querySelectorAll('.segment-dot');
            let readyCount = 0;

            dots.forEach((dot, index) => {
                dot.classList.remove('ready', 'generating', 'current', 'pending');

                if (index === currentIndex) {
                    dot.classList.add('current');
                } else if (segments[index]) {
                    if (segments[index].has_tts || segments[index].ready) {
                        dot.classList.add('ready');
                        readyCount++;
                    } else if (segments[index].generating) {
                        dot.classList.add('generating');
                    } else {
                        dot.classList.add('pending');
                    }
                }
            });

            // Also count current if ready
            if (segments[currentIndex]?.has_tts || segments[currentIndex]?.ready) {
                readyCount++;
            }

            readySegmentsEl.textContent = readyCount;
        }

        function startSegmentPolling() {
            // Poll for segment status every 2 seconds
            setInterval(async () => {
                try {
                    const response = await fetch(manifestUrl);
                    const data = await response.json();

                    // Update segment status
                    data.segments.forEach((newSeg, idx) => {
                        if (segments[idx]) {
                            segments[idx].has_tts = newSeg.has_tts;
                            segments[idx].ready = newSeg.ready;
                            segments[idx].generating = newSeg.generating;
                            segments[idx].translated_text = newSeg.translated_text;
                        }
                    });

                    updateIndicators();

                    // Also prefetch upcoming segments
                    prefetchNext();
                } catch (error) {
                    console.error('Segment polling error:', error);
                }
            }, 2000);
        }

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

            // Check if segment is ready
            if (!segment.has_tts && !segment.ready) {
                statusEl.textContent = 'Waiting for segment...';
                statusEl.classList.add('loading');

                // Wait for segment to be ready
                await waitForSegment(index);
            }

            statusEl.textContent = 'Loading...';
            statusEl.classList.add('loading');

            try {
                videoSource.src = segment.stream_url;
                player.load();

                player.oncanplay = () => {
                    statusEl.textContent = 'Playing';
                    statusEl.classList.remove('loading');
                    statusEl.classList.add('ready');
                    segments[index].ready = true;
                    updateIndicators();
                };

                player.onerror = () => {
                    statusEl.textContent = 'Error loading segment';
                    statusEl.classList.add('loading');
                };

                prefetchNext();
            } catch (error) {
                console.error('Failed to load segment:', error);
                statusEl.textContent = 'Error: ' + error.message;
            }
        }

        async function waitForSegment(index) {
            return new Promise((resolve) => {
                const check = setInterval(async () => {
                    try {
                        const response = await fetch(segments[index].status_url);
                        const data = await response.json();

                        if (data.ready) {
                            segments[index].ready = true;
                            segments[index].has_tts = true;
                            clearInterval(check);
                            resolve();
                        }
                    } catch (e) {
                        // ignore
                    }
                }, 1000);
            });
        }

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
                        current_segment_id: segments[currentIndex]?.id
                    })
                });
            } catch (error) {
                console.error('Prefetch error:', error);
            } finally {
                prefetchInProgress = false;
            }
        }

        // Handle video ended
        player.addEventListener('ended', () => {
            subtitleEl.style.display = 'none';

            if (autoplay && currentIndex < segments.length - 1) {
                loadSegment(currentIndex + 1);
                player.play();
            } else if (currentIndex === segments.length - 1) {
                statusEl.textContent = 'Playback complete';
                statusEl.classList.remove('loading');
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
        initialize();
    </script>
</body>
</html>
