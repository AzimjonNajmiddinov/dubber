<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dubber - Live Stream</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 20px;
            background: #0a0a0a;
            color: #fff;
            min-height: 100vh;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        h1 {
            font-size: 20px;
            margin: 0;
            color: #fff;
        }
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-live {
            background: #ef4444;
            color: #fff;
            animation: pulse 2s infinite;
        }
        .badge-done {
            background: #22c55e;
            color: #000;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .video-wrapper {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            aspect-ratio: 16/9;
        }
        video {
            width: 100%;
            height: 100%;
            display: block;
        }
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.85);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }
        .loading-overlay.hidden { display: none; }
        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid #333;
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text {
            font-size: 14px;
            color: #aaa;
        }
        .status-bar {
            margin-top: 16px;
            padding: 12px 16px;
            background: #1a1a1a;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .status-text {
            font-size: 14px;
            color: #888;
        }
        .status-text strong {
            color: #fff;
        }
        .progress-bar {
            width: 200px;
            height: 6px;
            background: #333;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #22c55e;
            transition: width 0.3s ease;
        }
        .subtitle-container {
            position: absolute;
            bottom: 60px;
            left: 0;
            right: 0;
            text-align: center;
            padding: 0 20px;
            pointer-events: none;
        }
        .subtitle {
            display: inline-block;
            background: rgba(0, 0, 0, 0.85);
            color: #fff;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 16px;
            max-width: 80%;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Video #{{ $video->id }}</h1>
            <span class="badge badge-live" id="status-badge">LIVE</span>
        </div>

        <div class="video-wrapper">
            <video id="video" controls autoplay playsinline></video>

            <div class="loading-overlay" id="loading">
                <div class="spinner"></div>
                <div class="loading-text" id="loading-text">Preparing stream...</div>
            </div>

            <div class="subtitle-container">
                <div class="subtitle" id="subtitle" style="display: none;"></div>
            </div>
        </div>

        <div class="status-bar">
            <div class="status-text">
                <span id="status-label">Processing</span> &bull;
                <strong id="segment-count">0</strong> segments ready
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <script>
        const hlsUrl = @json($hlsUrl);
        const statusUrl = @json($statusUrl);
        const manifestUrl = @json($manifestUrl);

        const video = document.getElementById('video');
        const loading = document.getElementById('loading');
        const loadingText = document.getElementById('loading-text');
        const statusBadge = document.getElementById('status-badge');
        const statusLabel = document.getElementById('status-label');
        const segmentCount = document.getElementById('segment-count');
        const progressFill = document.getElementById('progress-fill');
        const subtitle = document.getElementById('subtitle');

        let hls = null;
        let segments = [];
        let isComplete = false;
        let retryCount = 0;
        const maxRetries = 60; // 5 minutes of retries

        async function checkManifest() {
            try {
                const res = await fetch(manifestUrl);
                const data = await res.json();
                segments = data.segments || [];

                const readyCount = segments.filter(s => s.ready).length;
                const totalCount = segments.length;

                segmentCount.textContent = readyCount;

                if (totalCount > 0) {
                    const progress = Math.round((readyCount / totalCount) * 100);
                    progressFill.style.width = progress + '%';

                    if (readyCount === totalCount) {
                        isComplete = true;
                        statusBadge.textContent = 'COMPLETE';
                        statusBadge.className = 'badge badge-done';
                        statusLabel.textContent = 'Ready';
                    }
                }

                return readyCount > 0;
            } catch (e) {
                console.error('Manifest check failed:', e);
                return false;
            }
        }

        async function initPlayer() {
            // Wait for at least one segment to be ready
            const hasSegments = await checkManifest();

            if (!hasSegments) {
                loadingText.textContent = 'Waiting for first segment...';
                retryCount++;

                if (retryCount < maxRetries) {
                    setTimeout(initPlayer, 5000);
                } else {
                    loadingText.textContent = 'Timeout waiting for segments';
                }
                return;
            }

            loadingText.textContent = 'Starting stream...';

            if (Hls.isSupported()) {
                hls = new Hls({
                    liveSyncDuration: 3,
                    liveMaxLatencyDuration: 10,
                    liveDurationInfinity: true,
                    manifestLoadingMaxRetry: 10,
                    manifestLoadingRetryDelay: 2000,
                    levelLoadingMaxRetry: 10,
                });

                hls.loadSource(hlsUrl);
                hls.attachMedia(video);

                hls.on(Hls.Events.MANIFEST_PARSED, () => {
                    loading.classList.add('hidden');
                    video.play().catch(() => {});
                });

                hls.on(Hls.Events.ERROR, (event, data) => {
                    if (data.fatal) {
                        console.error('HLS fatal error:', data);
                        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                            // Try to recover
                            setTimeout(() => hls.loadSource(hlsUrl), 3000);
                        }
                    }
                });

                // Poll for new segments if not complete
                if (!isComplete) {
                    setInterval(async () => {
                        await checkManifest();
                        if (!isComplete) {
                            // Reload playlist to get new segments
                            hls.loadSource(hlsUrl);
                        }
                    }, 5000);
                }

            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                // Native HLS support (Safari)
                video.src = hlsUrl;
                video.addEventListener('loadedmetadata', () => {
                    loading.classList.add('hidden');
                    video.play().catch(() => {});
                });
            } else {
                loadingText.textContent = 'HLS not supported in this browser';
            }
        }

        // Show current subtitle based on video time
        video.addEventListener('timeupdate', () => {
            const currentTime = video.currentTime;
            let currentSub = null;

            for (const seg of segments) {
                if (currentTime >= seg.start_time && currentTime < seg.end_time) {
                    currentSub = seg.translated_text || seg.text;
                    break;
                }
            }

            if (currentSub) {
                subtitle.textContent = currentSub;
                subtitle.style.display = 'inline-block';
            } else {
                subtitle.style.display = 'none';
            }
        });

        // Start
        initPlayer();
    </script>
</body>
</html>
