<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instant Dub</title>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #111; color: #eee; }

        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        h1 { font-size: 1.4rem; margin-bottom: 16px; }

        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }

        .panel { background: #1a1a1a; border-radius: 8px; padding: 16px; }

        label { display: block; font-size: 0.85rem; color: #999; margin-bottom: 4px; }
        input[type="text"], textarea, select {
            width: 100%; padding: 8px 10px; border: 1px solid #333; border-radius: 6px;
            background: #222; color: #eee; font-size: 0.9rem;
        }
        textarea { resize: vertical; font-family: monospace; font-size: 0.8rem; }
        .row { margin-bottom: 12px; }

        .btn {
            display: inline-block; padding: 12px 32px; border: none; border-radius: 6px;
            font-size: 1rem; cursor: pointer; font-weight: 600;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .actions { display: flex; gap: 10px; margin-top: 12px; }

        .video-wrap { position: relative; border-radius: 8px; overflow: hidden; background: #000; }
        video { width: 100%; display: block; background: #000; max-height: 500px; }
        .subtitle-overlay {
            position: absolute; bottom: 40px; left: 0; right: 0;
            text-align: center; pointer-events: none; padding: 0 16px;
        }
        .subtitle-overlay span {
            display: inline-block; padding: 4px 14px; border-radius: 4px;
            background: rgba(0,0,0,0.75); color: #fff; font-size: 1.1rem;
            line-height: 1.4; max-width: 90%;
        }

        .status-bar {
            margin-top: 12px; padding: 10px 14px; border-radius: 6px;
            background: #222; font-size: 0.85rem; display: none;
        }
        .status-bar.active { display: block; }

        .progress-fill {
            height: 4px; background: #3b82f6; border-radius: 2px;
            transition: width 0.3s; margin-top: 6px;
        }

        .segment-list {
            margin-top: 12px; max-height: 200px; overflow-y: auto;
            font-size: 0.75rem; color: #888;
        }
        .segment-list div { padding: 2px 0; }
        .segment-list .ready { color: #4ade80; }
        .segment-list .pending { color: #555; }

        .toggle-link { font-size: 0.8rem; color: #666; cursor: pointer; margin-top: 8px; display: inline-block; }
        .toggle-link:hover { color: #999; }
        .srt-section { display: none; margin-top: 8px; }
        .srt-section.open { display: block; }

        .lang-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .lang-row .field { flex: 1; }
    </style>
</head>
<body>
<div class="container">
    <h1>Instant Dub</h1>

    <div class="layout">
        <div class="panel">
            <div class="row">
                <label>Video URL</label>
                <input type="text" id="videoUrl" placeholder="https://...master.m3u8 or .mp4">
            </div>

            <div class="lang-row">
                <div class="field">
                    <label>Subtitle language</label>
                    <select id="translateFrom">
                        <option value="en" selected>English</option>
                        <option value="ru">Russian</option>
                        <option value="uz">Uzbek</option>
                        <option value="tr">Turkish</option>
                        <option value="es">Spanish</option>
                        <option value="fr">French</option>
                        <option value="de">German</option>
                        <option value="ar">Arabic</option>
                        <option value="zh">Chinese</option>
                        <option value="ja">Japanese</option>
                        <option value="ko">Korean</option>
                    </select>
                </div>
                <div class="field">
                    <label>Dub to</label>
                    <select id="language">
                        <option value="uz" selected>Uzbek</option>
                        <option value="ru">Russian</option>
                        <option value="en">English</option>
                        <option value="tr">Turkish</option>
                        <option value="es">Spanish</option>
                        <option value="fr">French</option>
                        <option value="de">German</option>
                        <option value="ar">Arabic</option>
                        <option value="zh">Chinese</option>
                        <option value="ja">Japanese</option>
                        <option value="ko">Korean</option>
                    </select>
                </div>
            </div>

            <span class="toggle-link" id="toggleSrt">Paste SRT manually</span>
            <div class="srt-section" id="srtSection">
                <textarea id="srtText" rows="10" placeholder="Optional — paste SRT if you have it. Otherwise subtitles are fetched from HLS automatically."></textarea>
            </div>

            <div class="actions">
                <button class="btn btn-primary" id="btnStart">Start Dubbing</button>
                <button class="btn btn-danger" id="btnStop" disabled>Stop</button>
            </div>
        </div>

        <div class="panel">
            <div class="video-wrap">
                <video id="videoPlayer" controls crossorigin="anonymous">
                    Your browser does not support the video element.
                </video>
                <div class="subtitle-overlay" id="subtitleOverlay"><span></span></div>
            </div>

            <div class="status-bar" id="statusBar">
                <span id="statusText">Ready</span>
                <div style="background:#333; border-radius:2px; height:4px; margin-top:6px;">
                    <div class="progress-fill" id="progressFill" style="width:0%"></div>
                </div>
            </div>

            <div class="segment-list" id="segmentList"></div>
        </div>
    </div>
</div>

<script>
(function() {
    const POLL_INTERVAL = 2000;

    const videoUrl = document.getElementById('videoUrl');
    const language = document.getElementById('language');
    const translateFrom = document.getElementById('translateFrom');
    const srtText = document.getElementById('srtText');
    const btnStart = document.getElementById('btnStart');
    const btnStop = document.getElementById('btnStop');
    const toggleSrt = document.getElementById('toggleSrt');
    const srtSection = document.getElementById('srtSection');
    const video = document.getElementById('videoPlayer');
    const statusBar = document.getElementById('statusBar');
    const statusText = document.getElementById('statusText');
    const progressFill = document.getElementById('progressFill');
    const segmentList = document.getElementById('segmentList');
    const subtitleOverlay = document.getElementById('subtitleOverlay');

    let sessionId = null;
    let totalSegments = 0;
    let pollTimer = null;
    let lastChunkIndex = -1;
    let audioCtx = null;
    let scheduledSources = [];
    let chunks = [];
    let hlsInstance = null;

    // Toggle SRT textarea
    toggleSrt.addEventListener('click', () => {
        srtSection.classList.toggle('open');
        toggleSrt.textContent = srtSection.classList.contains('open') ? 'Hide SRT' : 'Paste SRT manually';
    });

    // HLS Video Loading
    function loadVideo(url) {
        if (!url) return;
        if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }

        if (url.includes('.m3u8')) {
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = url;
            } else if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                hlsInstance = new Hls();
                hlsInstance.loadSource(url);
                hlsInstance.attachMedia(video);
            } else {
                alert('HLS not supported in this browser');
                return;
            }
        } else {
            video.src = url;
        }
        video.volume = 0.4;
    }

    // Start Dubbing — one button does everything
    btnStart.addEventListener('click', async () => {
        const url = videoUrl.value.trim();
        if (!url) { alert('Enter a video URL'); return; }

        // Load video
        loadVideo(url);

        btnStart.disabled = true;
        btnStop.disabled = false;
        statusBar.classList.add('active');
        statusText.textContent = 'Fetching subtitles & translating...';
        progressFill.style.width = '0%';

        try {
            const body = {
                language: language.value,
                video_url: url,
                translate_from: translateFrom.value,
            };

            // Include SRT only if user pasted it
            const srt = srtText.value.trim();
            if (srt) {
                body.srt = srt;
            }

            const resp = await fetch('/api/instant-dub/start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await resp.json();
            if (!resp.ok) throw new Error(data.error || 'Failed to start');

            sessionId = data.session_id;
            totalSegments = 0;
            lastChunkIndex = -1;
            chunks = [];

            statusText.textContent = `0 / ${totalSegments} segments ready`;
            updateSegmentList();

            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') await audioCtx.resume();

            startPolling();

        } catch (err) {
            statusText.textContent = 'Error: ' + err.message;
            btnStart.disabled = false;
            btnStop.disabled = true;
        }
    });

    // Stop
    btnStop.addEventListener('click', async () => {
        stopPolling();
        cancelAllAudio();
        if (sessionId) {
            try { await fetch(`/api/instant-dub/${sessionId}/stop`, { method: 'POST' }); } catch (e) {}
        }
        sessionId = null;
        btnStart.disabled = false;
        btnStop.disabled = true;
        statusText.textContent = 'Stopped';
    });

    // Polling
    function startPolling() { stopPolling(); poll(); pollTimer = setInterval(poll, POLL_INTERVAL); }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    async function poll() {
        if (!sessionId) return;
        try {
            const resp = await fetch(`/api/instant-dub/${sessionId}/poll?after=${lastChunkIndex}`);
            const data = await resp.json();
            if (!resp.ok) { if (resp.status === 404) { stopPolling(); statusText.textContent = 'Session expired'; } return; }

            const ready = data.segments_ready || 0;
            const total = data.total_segments || totalSegments;

            if (data.status === 'error') {
                stopPolling();
                statusText.textContent = 'Error: ' + (data.error || 'Unknown error');
                btnStart.disabled = false; btnStop.disabled = true;
                return;
            }

            if (data.status === 'preparing') {
                statusText.textContent = 'Fetching subtitles & translating...';
                return;
            }

            // Update total when server reports it
            if (total > 0 && total !== totalSegments) {
                totalSegments = total;
                chunks = new Array(total).fill(null);
                updateSegmentList();
            }

            statusText.textContent = `${ready} / ${total} segments ready`;
            progressFill.style.width = (total > 0 ? Math.round((ready / total) * 100) : 0) + '%';

            if (data.chunks && data.chunks.length > 0) {
                for (const chunk of data.chunks) {
                    if (chunk.index > lastChunkIndex) lastChunkIndex = chunk.index;
                    if (chunk.audio_base64) {
                        try { chunk._audioBuffer = await audioCtx.decodeAudioData(base64ToArrayBuffer(chunk.audio_base64)); } catch (e) {}
                    }
                    chunks[chunk.index] = chunk;
                }
                updateSegmentList();
                if (!video.paused) scheduleAudio();
            }

            if (data.status === 'complete') {
                stopPolling();
                statusText.textContent = `Done! ${total} / ${total} segments`;
                progressFill.style.width = '100%';
            }
        } catch (err) { console.error('Poll error:', err); }
    }

    // Web Audio Scheduling
    function scheduleAudio() {
        cancelAllAudio();
        if (!audioCtx || !video || video.paused) return;
        const currentTime = video.currentTime;
        const ctxNow = audioCtx.currentTime;

        for (let i = 0; i < chunks.length; i++) {
            const chunk = chunks[i];
            if (!chunk || !chunk._audioBuffer) continue;
            const segEnd = chunk.start_time + chunk._audioBuffer.duration;
            if (segEnd <= currentTime) continue;

            const delay = chunk.start_time - currentTime;
            let offset = 0, playAt = ctxNow + delay;
            if (delay < 0) { offset = -delay; playAt = ctxNow; if (offset >= chunk._audioBuffer.duration) continue; }

            const source = audioCtx.createBufferSource();
            source.buffer = chunk._audioBuffer;
            const gain = audioCtx.createGain();
            gain.gain.value = 1.0;
            source.connect(gain).connect(audioCtx.destination);
            source.start(playAt, offset);
            scheduledSources.push({ source, gain });
        }
    }

    function cancelAllAudio() {
        for (const s of scheduledSources) { try { s.source.stop(); } catch (e) {} }
        scheduledSources = [];
    }

    // Speaker color map
    const speakerColors = {
        'M1': '#fff', 'M2': '#7dd3fc', 'M3': '#86efac', 'M4': '#fde68a',
        'F1': '#fca5a5', 'F2': '#c4b5fd', 'F3': '#f9a8d4', 'F4': '#fdba74',
    };

    // Subtitle display with per-speaker color
    function updateSubtitle() {
        const t = video.currentTime;
        let found = '';
        let speaker = '';
        for (let i = 0; i < chunks.length; i++) {
            const c = chunks[i];
            if (c && t >= c.start_time && t <= c.end_time) { found = c.text; speaker = c.speaker || ''; break; }
        }
        const span = subtitleOverlay.querySelector('span');
        if (span.textContent !== found) {
            span.textContent = found;
            span.style.color = speakerColors[speaker] || '#fff';
        }
    }

    video.addEventListener('play', () => { if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); scheduleAudio(); });
    video.addEventListener('pause', () => cancelAllAudio());
    video.addEventListener('seeked', () => { if (!video.paused) scheduleAudio(); });
    video.addEventListener('timeupdate', updateSubtitle);

    // Segment list
    function updateSegmentList() {
        let html = '';
        for (let i = 0; i < chunks.length; i++) {
            const c = chunks[i];
            const cls = c ? 'ready' : 'pending';
            const timeStr = c ? formatTime(c.start_time) : '--:--';
            const txt = c ? truncate(c.text, 50) : '...';
            html += `<div class="${cls}">[${timeStr}] ${txt}</div>`;
        }
        segmentList.innerHTML = html;
    }

    function base64ToArrayBuffer(b64) {
        const bin = atob(b64), len = bin.length, bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
        return bytes.buffer;
    }
    function formatTime(s) { return String(Math.floor(s/60)).padStart(2,'0') + ':' + String(Math.floor(s%60)).padStart(2,'0'); }
    function truncate(s, n) { return s.length > n ? s.substring(0, n) + '...' : s; }
})();
</script>
</body>
</html>
