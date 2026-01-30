<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dubber - Video #{{ $video->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
            max-width: 1200px;
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
        .lang-badge {
            background: #22c55e;
            color: #000;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
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
            background: rgba(0, 0, 0, 0.85);
            color: #fff;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 18px;
            line-height: 1.4;
            max-width: 85%;
        }
        .subtitle .speaker-name {
            color: #22c55e;
            font-weight: 600;
            margin-right: 8px;
        }
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
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
            color: #888;
            font-size: 16px;
            text-align: center;
        }
        .progress-bar {
            width: 240px;
            height: 6px;
            background: #333;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            width: 0%;
            transition: width 0.3s;
        }
        .timeline {
            display: flex;
            gap: 3px;
            padding: 12px;
            background: #111;
            border-radius: 0 0 12px 12px;
            overflow-x: auto;
            justify-content: center;
            flex-wrap: wrap;
        }
        .chunk-dot {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            background: #333;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .chunk-dot:hover { transform: scale(1.2); }
        .chunk-dot.ready { background: #22c55e; }
        .chunk-dot.generating { background: #f97316; animation: pulse 1s infinite; }
        .chunk-dot.current { background: #3b82f6; transform: scale(1.3); }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        .panels {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 16px;
            margin-top: 16px;
        }
        @media (max-width: 900px) {
            .panels { grid-template-columns: 1fr; }
        }

        .panel {
            background: #151515;
            border-radius: 12px;
            padding: 16px;
        }
        .panel-title {
            font-size: 14px;
            color: #888;
            margin: 0 0 12px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        @media (max-width: 600px) {
            .info-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .info-item {
            text-align: center;
        }
        .info-value {
            font-size: 24px;
            font-weight: 600;
            color: #fff;
        }
        .info-label {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }

        .transcript {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #222;
        }
        .transcript-original {
            color: #666;
            font-size: 14px;
            margin-bottom: 6px;
        }
        .transcript-translated {
            color: #fff;
            font-size: 16px;
            line-height: 1.5;
        }

        .speakers-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .speaker-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #1a1a1a;
            border-radius: 8px;
        }
        .speaker-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        .speaker-avatar.male { background: #3b82f6; color: #fff; }
        .speaker-avatar.female { background: #ec4899; color: #fff; }
        .speaker-avatar.unknown { background: #6b7280; color: #fff; }
        .speaker-info {
            flex: 1;
        }
        .speaker-name {
            font-weight: 500;
            color: #fff;
            font-size: 14px;
        }
        .speaker-voice {
            font-size: 12px;
            color: #666;
        }

        .controls {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #222;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-primary { background: #22c55e; color: #000; }
        .btn-secondary { background: #222; color: #fff; }
        .btn-sm { padding: 6px 10px; font-size: 12px; }

        .download-section {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #222;
        }
        .download-progress {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .download-progress .progress-bar {
            flex: 1;
            height: 8px;
        }
        .download-progress .progress-text {
            font-size: 14px;
            color: #888;
            min-width: 60px;
            text-align: right;
        }

        .actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Movie Dubber</h1>
            <span class="lang-badge">{{ strtoupper($video->target_language) }}</span>
        </div>

        <div class="video-wrapper">
            <video id="player" controls>
                <source id="video-source" src="" type="video/mp4">
            </video>
            <div class="subtitle-container">
                <div class="subtitle" id="subtitle" style="display: none;">
                    <span class="speaker-name" id="subtitle-speaker"></span>
                    <span id="subtitle-text"></span>
                </div>
            </div>
            <div class="loading-overlay" id="loading-overlay">
                <div class="spinner"></div>
                <div class="loading-text" id="loading-text">Preparing video...</div>
                <div class="progress-bar">
                    <div class="progress-fill" id="loading-progress"></div>
                </div>
            </div>
        </div>

        <div class="timeline" id="timeline"></div>

        <div class="panels">
            <div class="panel">
                <h2 class="panel-title">Now Playing</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-value" id="current-chunk">-</div>
                        <div class="info-label">Chunk</div>
                    </div>
                    <div class="info-item">
                        <div class="info-value" id="total-chunks">-</div>
                        <div class="info-label">Total</div>
                    </div>
                    <div class="info-item">
                        <div class="info-value" id="ready-chunks">0</div>
                        <div class="info-label">Ready</div>
                    </div>
                    <div class="info-item">
                        <div class="info-value" id="status-text">...</div>
                        <div class="info-label">Status</div>
                    </div>
                </div>

                <div class="transcript" id="transcript-section">
                    <div class="transcript-original" id="original-text"></div>
                    <div class="transcript-translated" id="translated-text"></div>
                </div>

                <div class="controls">
                    <button class="btn btn-secondary" id="prev-btn" disabled>Previous</button>
                    <button class="btn btn-secondary" id="next-btn" disabled>Next</button>
                    <button class="btn btn-secondary" id="autoplay-btn">Autoplay: ON</button>
                </div>

                <div class="download-section">
                    <h3 class="panel-title">Download</h3>
                    <div class="download-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" id="download-progress"></div>
                        </div>
                        <span class="progress-text" id="download-progress-text">0%</span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-primary" id="download-btn" disabled>
                            Download Full Video
                        </button>
                        <button class="btn btn-secondary" id="download-chunk-btn" disabled>
                            Download Current Chunk
                        </button>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2 class="panel-title">Speakers</h2>
                <div class="speakers-list" id="speakers-list">
                    <div class="speaker-item">
                        <div class="speaker-avatar unknown">?</div>
                        <div class="speaker-info">
                            <div class="speaker-name">Detecting speakers...</div>
                            <div class="speaker-voice">Please wait</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <a href="{{ route('stream.live') }}" class="btn btn-secondary">New Video</a>
            <a href="{{ route('videos.show', $video) }}" class="btn btn-secondary">Details</a>
            <a href="{{ route('videos.index') }}" class="btn btn-secondary">All Videos</a>
        </div>
    </div>

    <script>
        const videoId = {{ $video->id }};
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        let chunks = [];
        let speakers = {};
        let currentIndex = 0;
        let autoplay = true;
        let isInitialized = false;

        const player = document.getElementById('player');
        const videoSource = document.getElementById('video-source');
        const timeline = document.getElementById('timeline');
        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingText = document.getElementById('loading-text');
        const loadingProgress = document.getElementById('loading-progress');
        const subtitleEl = document.getElementById('subtitle');
        const subtitleSpeaker = document.getElementById('subtitle-speaker');
        const subtitleText = document.getElementById('subtitle-text');

        // Status elements
        const currentChunkEl = document.getElementById('current-chunk');
        const totalChunksEl = document.getElementById('total-chunks');
        const readyChunksEl = document.getElementById('ready-chunks');
        const statusTextEl = document.getElementById('status-text');
        const originalTextEl = document.getElementById('original-text');
        const translatedTextEl = document.getElementById('translated-text');

        // Download elements
        const downloadProgress = document.getElementById('download-progress');
        const downloadProgressText = document.getElementById('download-progress-text');
        const downloadBtn = document.getElementById('download-btn');
        const downloadChunkBtn = document.getElementById('download-chunk-btn');

        // Control buttons
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        const autoplayBtn = document.getElementById('autoplay-btn');
        const speakersList = document.getElementById('speakers-list');

        async function initialize() {
            loadingText.textContent = 'Checking status...';
            await checkStatus();
        }

        async function checkStatus() {
            try {
                const res = await fetch(`/api/live/${videoId}/status`);
                const data = await res.json();

                loadingText.textContent = getStatusMessage(data.status);
                loadingProgress.style.width = data.progress + '%';
                totalChunksEl.textContent = data.total_segments || '-';
                readyChunksEl.textContent = data.ready_segments || '0';

                if (data.ready_segments > 0 || data.can_play) {
                    await loadManifest();
                } else {
                    setTimeout(checkStatus, 2000);
                }
            } catch (e) {
                console.error('Status check error:', e);
                setTimeout(checkStatus, 3000);
            }
        }

        function getStatusMessage(status) {
            const messages = {
                'pending': 'Starting...',
                'downloading': 'Downloading video...',
                'uploaded': 'Processing video...',
                'processing_chunks': 'Dubbing in progress...',
                'audio_extracted': 'Extracting audio...',
            };
            return messages[status] || status;
        }

        async function loadManifest() {
            try {
                const res = await fetch(`/api/player/${videoId}/manifest`);
                const data = await res.json();

                // Build chunks from segments (grouped by 12-second windows)
                const segmentsByChunk = {};
                data.segments.forEach(seg => {
                    const chunkIdx = Math.floor(seg.start_time / 12);
                    if (!segmentsByChunk[chunkIdx]) {
                        segmentsByChunk[chunkIdx] = {
                            index: chunkIdx,
                            segments: [],
                            ready: false,
                        };
                    }
                    segmentsByChunk[chunkIdx].segments.push(seg);
                    if (seg.has_tts || seg.ready) {
                        segmentsByChunk[chunkIdx].ready = true;
                    }
                });

                chunks = Object.values(segmentsByChunk).sort((a, b) => a.index - b.index);
                totalChunksEl.textContent = chunks.length;

                renderTimeline();
                updateTimeline();
                await loadSpeakers();

                // Find first ready chunk
                const firstReady = chunks.findIndex(c => c.ready);
                if (firstReady >= 0) {
                    loadingOverlay.classList.add('hidden');
                    loadChunk(firstReady);
                    player.play();
                    startPolling();
                } else {
                    loadingText.textContent = 'Waiting for first chunk...';
                    setTimeout(loadManifest, 2000);
                }
            } catch (e) {
                console.error('Manifest error:', e);
                loadingText.textContent = 'Error loading video';
            }
        }

        async function loadSpeakers() {
            try {
                const res = await fetch(`/videos/${videoId}/speakers`);
                const data = await res.json();

                speakers = {};
                data.forEach(s => {
                    speakers[s.id] = s;
                });

                renderSpeakers(data);
            } catch (e) {
                console.error('Failed to load speakers:', e);
            }
        }

        function renderSpeakers(speakerList) {
            if (speakerList.length === 0) {
                speakersList.innerHTML = `
                    <div class="speaker-item">
                        <div class="speaker-avatar unknown">?</div>
                        <div class="speaker-info">
                            <div class="speaker-name">No speakers detected yet</div>
                            <div class="speaker-voice">Processing...</div>
                        </div>
                    </div>
                `;
                return;
            }

            speakersList.innerHTML = speakerList.map(s => `
                <div class="speaker-item">
                    <div class="speaker-avatar ${s.gender || 'unknown'}">
                        ${s.label ? s.label.charAt(0) : '?'}
                    </div>
                    <div class="speaker-info">
                        <div class="speaker-name">${s.label || 'Unknown'}</div>
                        <div class="speaker-voice">${s.tts_voice || 'Default voice'}</div>
                    </div>
                </div>
            `).join('');
        }

        function renderTimeline() {
            timeline.innerHTML = chunks.map((chunk, i) => `
                <div class="chunk-dot" data-index="${i}" title="Chunk ${i + 1}"></div>
            `).join('');
        }

        function updateTimeline() {
            const dots = timeline.querySelectorAll('.chunk-dot');
            let readyCount = 0;

            dots.forEach((dot, i) => {
                dot.classList.remove('ready', 'generating', 'current');
                if (i === currentIndex) {
                    dot.classList.add('current');
                } else if (chunks[i]?.ready) {
                    dot.classList.add('ready');
                }
                if (chunks[i]?.ready) readyCount++;
            });

            readyChunksEl.textContent = readyCount;

            // Update download progress
            const progress = chunks.length > 0 ? Math.round((readyCount / chunks.length) * 100) : 0;
            downloadProgress.style.width = progress + '%';
            downloadProgressText.textContent = progress + '%';
            downloadBtn.disabled = progress < 100;
            downloadChunkBtn.disabled = !chunks[currentIndex]?.ready;
        }

        function loadChunk(index) {
            if (index < 0 || index >= chunks.length) return;

            currentIndex = index;
            const chunk = chunks[index];

            currentChunkEl.textContent = index + 1;
            prevBtn.disabled = index === 0;
            nextBtn.disabled = index === chunks.length - 1;
            statusTextEl.textContent = chunk.ready ? 'Playing' : 'Loading';

            updateTimeline();

            // Get first segment text for display
            const seg = chunk.segments[0];
            if (seg) {
                originalTextEl.textContent = seg.text || '';
                translatedTextEl.textContent = seg.translated_text || '';
            }

            // Load video
            const url = `/api/player/${videoId}/chunk/${index}/download`;
            videoSource.src = url;
            player.load();

            player.oncanplay = () => {
                statusTextEl.textContent = 'Playing';
            };
        }

        function startPolling() {
            setInterval(async () => {
                try {
                    const res = await fetch(`/api/player/${videoId}/manifest`);
                    const data = await res.json();

                    // Update chunk ready status
                    const segmentsByChunk = {};
                    data.segments.forEach(seg => {
                        const chunkIdx = Math.floor(seg.start_time / 12);
                        if (!segmentsByChunk[chunkIdx]) {
                            segmentsByChunk[chunkIdx] = { ready: false, segments: [] };
                        }
                        segmentsByChunk[chunkIdx].segments.push(seg);
                        if (seg.has_tts || seg.ready) {
                            segmentsByChunk[chunkIdx].ready = true;
                        }
                    });

                    Object.entries(segmentsByChunk).forEach(([idx, data]) => {
                        if (chunks[idx]) {
                            chunks[idx].ready = data.ready;
                            chunks[idx].segments = data.segments;
                        }
                    });

                    updateTimeline();
                    await loadSpeakers();
                } catch (e) {
                    console.error('Polling error:', e);
                }
            }, 3000);
        }

        // Event handlers
        player.addEventListener('ended', () => {
            subtitleEl.style.display = 'none';
            if (autoplay && currentIndex < chunks.length - 1) {
                loadChunk(currentIndex + 1);
                player.play();
            }
        });

        player.addEventListener('play', () => {
            const chunk = chunks[currentIndex];
            if (chunk?.segments[0]?.translated_text) {
                subtitleText.textContent = chunk.segments[0].translated_text;
                const speakerId = chunk.segments[0].speaker_id;
                subtitleSpeaker.textContent = speakers[speakerId]?.label || '';
                subtitleEl.style.display = 'inline-block';
            }
        });

        prevBtn.addEventListener('click', () => {
            if (currentIndex > 0) {
                loadChunk(currentIndex - 1);
                player.play();
            }
        });

        nextBtn.addEventListener('click', () => {
            if (currentIndex < chunks.length - 1) {
                loadChunk(currentIndex + 1);
                player.play();
            }
        });

        autoplayBtn.addEventListener('click', () => {
            autoplay = !autoplay;
            autoplayBtn.textContent = 'Autoplay: ' + (autoplay ? 'ON' : 'OFF');
        });

        timeline.addEventListener('click', (e) => {
            const dot = e.target.closest('.chunk-dot');
            if (dot) {
                const index = parseInt(dot.dataset.index);
                loadChunk(index);
                player.play();
            }
        });

        downloadBtn.addEventListener('click', () => {
            window.location.href = `/api/player/${videoId}/download`;
        });

        downloadChunkBtn.addEventListener('click', () => {
            window.location.href = `/api/player/${videoId}/chunk/${currentIndex}/download`;
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') prevBtn.click();
            else if (e.key === 'ArrowRight') nextBtn.click();
            else if (e.key === ' ') {
                e.preventDefault();
                player.paused ? player.play() : player.pause();
            }
        });

        // Start
        initialize();
    </script>
</body>
</html>
