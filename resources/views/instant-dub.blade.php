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
        .segment-list .error { color: #f87171; }
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
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h1 style="margin-bottom:0">Instant Dub</h1>
        <a href="{{ route('admin.dubs.index') }}" style="padding:7px 16px;background:#1a1f2e;border:1px solid #333;border-radius:6px;color:#999;font-size:0.85rem;text-decoration:none">Admin</a>
    </div>

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
                        <option value="auto" selected>Auto-detect (HLS)</option>
                        <option value="en">English</option>
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
                <video id="videoPlayer" controls>
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
    let chunks = [];
    let hlsInstance = null;
    let firstChunksPlayed = false;
    let currentDubIndex = -1;
    let currentSource = null;
    let decodeSuccessCount = 0;
    let decodeFailCount = 0;

    // Toggle SRT textarea
    toggleSrt.addEventListener('click', () => {
        srtSection.classList.toggle('open');
        toggleSrt.textContent = srtSection.classList.contains('open') ? 'Hide SRT' : 'Paste SRT manually';
    });

    // HLS Video Loading — returns a promise that resolves when video is ready to play
    function loadVideo(url) {
        return new Promise((resolve, reject) => {
            if (!url) { reject('No URL'); return; }
            if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }

            if (url.includes('.m3u8')) {
                if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = url;
                    video.addEventListener('loadedmetadata', () => resolve(), { once: true });
                } else if (typeof Hls !== 'undefined' && Hls.isSupported()) {
                    hlsInstance = new Hls({ enableWorker: false });
                    hlsInstance.loadSource(url);
                    hlsInstance.attachMedia(video);
                    hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => resolve());
                    hlsInstance.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) reject(d.type); });
                } else {
                    reject('HLS not supported'); return;
                }
            } else {
                video.src = url;
                video.addEventListener('loadedmetadata', () => resolve(), { once: true });
            }
            video.volume = 0.2;
        });
    }

    // Start Dubbing — one button does everything
    btnStart.addEventListener('click', async () => {
        const url = videoUrl.value.trim();
        if (!url) { alert('Enter a video URL'); return; }

        // Create AudioContext immediately in click handler — Chrome requires user gesture
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') audioCtx.resume();

        btnStart.disabled = true;
        btnStop.disabled = false;
        statusBar.classList.add('active');
        statusText.textContent = 'Loading video...';
        progressFill.style.width = '0%';

        // Load video but DON'T auto-play — wait for first audio chunks
        try {
            await loadVideo(url);
            video.pause();
            video.currentTime = 0;
        } catch (e) {
            console.warn('Video load failed:', e);
        }
        statusText.textContent = 'Fetching subtitles & translating — video will start when ready...';

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
            firstChunksPlayed = false;

            statusText.textContent = `0 / ${totalSegments} segments ready`;
            updateSegmentList();

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
        stopCurrentAudio();
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

            const errorCount = chunks.filter(c => c && c.error).length;
            let statusStr = `${ready} / ${total} segments ready`;
            if (decodeSuccessCount > 0) statusStr += ` | ${decodeSuccessCount} audio OK`;
            if (decodeFailCount > 0) statusStr += ` | ${decodeFailCount} decode fail`;
            if (errorCount > 0) statusStr += ` | ${errorCount} TTS errors`;
            if (audioCtx) statusStr += ` | ctx:${audioCtx.state}`;
            statusText.textContent = statusStr;
            progressFill.style.width = (total > 0 ? Math.round((ready / total) * 100) : 0) + '%';

            if (data.chunks && data.chunks.length > 0) {
                for (const chunk of data.chunks) {
                    if (chunk.index > lastChunkIndex) lastChunkIndex = chunk.index;
                    if (chunk.error) {
                        console.error('TTS error chunk', chunk.index, chunk.error);
                    }
                    if (chunk.audio_base64) {
                        try {
                            chunk._audioBuffer = await audioCtx.decodeAudioData(base64ToArrayBuffer(chunk.audio_base64));
                            decodeSuccessCount++;
                        } catch (e) {
                            decodeFailCount++;
                            console.warn('Audio decode failed for chunk', chunk.index, e);
                        }
                    }
                    // Free base64 string from memory after decoding
                    delete chunk.audio_base64;
                    chunks[chunk.index] = chunk;
                }
                updateSegmentList();

                // Auto-play video when first audio chunks are ready
                if (!firstChunksPlayed) {
                    firstChunksPlayed = true;
                    video.currentTime = 0;
                    try { await video.play(); } catch (e) { console.warn('Auto-play failed:', e); }
                }
            }

            if (data.status === 'complete') {
                stopPolling();
                statusText.textContent = `Done! ${total} / ${total} segments | ${decodeSuccessCount} audio OK`;
                if (decodeFailCount > 0) statusText.textContent += ` | ${decodeFailCount} decode fail`;
                if (errorCount > 0) statusText.textContent += ` | ${errorCount} TTS errors`;
                progressFill.style.width = '100%';
            }
        } catch (err) { console.error('Poll error:', err); }
    }

    // Dub audio playback — plays one chunk at a time, driven by timeupdate
    function updateDubAudio() {
        if (!audioCtx || !video || video.paused) return;
        if (audioCtx.state === 'suspended') audioCtx.resume();

        const t = video.currentTime;

        // Find which chunk should be playing at time t
        let targetIndex = -1;
        for (let i = 0; i < chunks.length; i++) {
            const c = chunks[i];
            if (!c || !c._audioBuffer) continue;
            const end = c.start_time + c._audioBuffer.duration;
            if (t >= c.start_time && t < end) {
                targetIndex = i;
                break;
            }
        }

        // Same chunk already playing — nothing to do
        if (targetIndex === currentDubIndex) return;

        // Stop current source
        stopCurrentAudio();
        currentDubIndex = targetIndex;

        // Start new chunk
        if (targetIndex >= 0) {
            const chunk = chunks[targetIndex];
            const offset = Math.max(0, t - chunk.start_time);
            if (offset < chunk._audioBuffer.duration) {
                try {
                    const source = audioCtx.createBufferSource();
                    source.buffer = chunk._audioBuffer;
                    source.connect(audioCtx.destination);
                    source.start(0, offset);
                    source.onended = () => {
                        if (currentDubIndex === targetIndex) {
                            currentSource = null;
                            currentDubIndex = -1;
                        }
                    };
                    currentSource = source;
                } catch (e) {
                    console.error('Failed to play chunk', targetIndex, e);
                }
            }
        }
    }

    function stopCurrentAudio() {
        if (currentSource) {
            try { currentSource.stop(); } catch (e) {}
            currentSource = null;
        }
        currentDubIndex = -1;
    }

    // Speaker color map — distinct colors per speaker
    const speakerColors = {
        'M1': '#60a5fa', 'M2': '#7dd3fc', 'M3': '#86efac', 'M4': '#fde68a',
        'F1': '#fca5a5', 'F2': '#c4b5fd', 'F3': '#f9a8d4', 'F4': '#fdba74',
        'C1': '#fbbf24', 'C2': '#a3e635',
    };

    // Subtitle display with per-speaker color
    let lastSubKey = '';
    function updateSubtitle() {
        const t = video.currentTime;
        let found = '';
        let speaker = '';
        for (let i = 0; i < chunks.length; i++) {
            const c = chunks[i];
            if (c && t >= c.start_time && t <= c.end_time) { found = c.text; speaker = c.speaker || ''; break; }
        }
        const subKey = speaker + ':' + found;
        if (subKey === lastSubKey) return;
        lastSubKey = subKey;
        const span = subtitleOverlay.querySelector('span');
        const color = speakerColors[speaker] || '#fff';
        if (found) {
            span.innerHTML = `<b style="color:${color};font-size:0.75em;opacity:0.7">[${speaker}]</b> <span style="color:${color}">${found}</span>`;
        } else {
            span.innerHTML = '';
        }
    }

    video.addEventListener('play', () => { if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); });
    video.addEventListener('pause', () => stopCurrentAudio());
    video.addEventListener('seeked', () => stopCurrentAudio());
    video.addEventListener('timeupdate', () => { updateSubtitle(); updateDubAudio(); });

    // Segment list
    function updateSegmentList() {
        let html = '';
        for (let i = 0; i < chunks.length; i++) {
            const c = chunks[i];
            const cls = c ? (c.error ? 'error' : 'ready') : 'pending';
            const timeStr = c ? formatTime(c.start_time) : '--:--';
            const txt = c ? truncate(c.text, 50) : '...';
            const spk = c ? (c.speaker || '') : '';
            const color = speakerColors[spk] || '#888';
            const errHint = c && c.error ? ` [ERR: ${truncate(c.error, 40)}]` : '';
            const hasAudio = c && c._audioBuffer ? '' : (c && !c.error ? ' [no audio]' : '');
            html += `<div class="${cls}" style="color:${c ? (c.error ? '' : color) : ''}">[${timeStr}] <b>${spk}</b> ${txt}${errHint}${hasAudio}</div>`;
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

    // ── Embedded / extension mode ─────────────────────────────────────────────
    (function() {
        const params       = new URLSearchParams(location.search);
        const preSessionId = params.get('session_id');
        const isEmbedded   = params.has('embedded');

        if (!preSessionId) return;

        if (isEmbedded) {
            document.querySelector('.layout > .panel:first-child').style.display = 'none';
            const hdr = document.querySelector('[style*="justify-content"]');
            if (hdr) hdr.style.display = 'none';
        }

        sessionId = preSessionId;
        btnStart.disabled = true;
        btnStop.disabled  = false;
        statusBar.classList.add('active');
        statusText.textContent = 'Dubbing yuklanmoqda...';

        // AudioContext — popup opened via user gesture (window.open on button click)
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }

        // Time tracking driven by postMessage from YouTube content script
        let embTime    = 0;
        let embPlaying = false;
        let tickHandle = null;

        function embTick() {
            if (!embPlaying) return;
            embTime += 0.1;
            embUpdateAudio(embTime);
            embUpdateSubtitle(embTime);
        }

        function embUpdateAudio(t) {
            if (!audioCtx) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();

            let target = -1;
            for (let i = 0; i < chunks.length; i++) {
                const c = chunks[i];
                if (!c || !c._audioBuffer) continue;
                if (t >= c.start_time && t < c.start_time + c._audioBuffer.duration) { target = i; break; }
            }
            if (target === currentDubIndex) return;
            stopCurrentAudio();
            currentDubIndex = target;
            if (target < 0) return;
            const chunk = chunks[target];
            const offset = Math.max(0, t - chunk.start_time);
            if (offset >= chunk._audioBuffer.duration) return;
            try {
                const src = audioCtx.createBufferSource();
                src.buffer = chunk._audioBuffer;
                src.connect(audioCtx.destination);
                src.start(0, offset);
                src.onended = () => { if (currentDubIndex === target) { currentSource = null; currentDubIndex = -1; } };
                currentSource = src;
            } catch (e) {}
        }

        function embUpdateSubtitle(t) {
            let found = '', speaker = '';
            for (let i = 0; i < chunks.length; i++) {
                const c = chunks[i];
                if (c && t >= c.start_time && t <= c.end_time) { found = c.text; speaker = c.speaker || ''; break; }
            }
            const key = speaker + ':' + found;
            if (key === lastSubKey) return;
            lastSubKey = key;
            const span = subtitleOverlay.querySelector('span');
            const color = speakerColors[speaker] || '#fff';
            span.innerHTML = found
                ? `<b style="color:${color};font-size:0.75em;opacity:0.7">[${speaker}]</b> <span style="color:${color}">${found}</span>`
                : '';
        }

        // Poll and decode audio — same as non-embedded mode
        let pollHandle = setInterval(async () => {
            try {
                const resp = await fetch(`/api/instant-dub/${sessionId}/poll?after=${lastChunkIndex}`);
                if (!resp.ok) { if (resp.status === 404) clearInterval(pollHandle); return; }
                const data = await resp.json();

                const ready = data.segments_ready || 0;
                const total = data.total_segments || 0;
                if (total > 0 && !totalSegments) { totalSegments = total; chunks = new Array(total).fill(null); }
                if (total > 0) statusText.textContent = `${ready} / ${total} segment tayyor...`;
                progressFill.style.width = total > 0 ? Math.round(ready / total * 100) + '%' : '0%';

                if (data.chunks && data.chunks.length > 0) {
                    for (const c of data.chunks) {
                        if (c.index > lastChunkIndex) lastChunkIndex = c.index;
                        const cd = { start_time: c.start_time, end_time: c.end_time, text: c.text, speaker: c.speaker };
                        if (c.audio_base64) {
                            try {
                                cd._audioBuffer = await audioCtx.decodeAudioData(base64ToArrayBuffer(c.audio_base64));
                                decodeSuccessCount++;
                            } catch (e) { decodeFailCount++; }
                        }
                        if (c.error) cd.error = c.error;
                        chunks[c.index] = cd;
                    }
                    updateSegmentList();
                    // Immediately try to play if we're in playing state
                    if (embPlaying) embUpdateAudio(embTime);
                }

                if (data.status === 'complete') {
                    clearInterval(pollHandle);
                    statusText.textContent = `Tayyor! ${total} / ${total} segment`;
                    progressFill.style.width = '100%';
                }
                if (data.status === 'error') {
                    clearInterval(pollHandle);
                    statusText.textContent = "Xato: " + (data.error || "Noma'lum xato");
                }
            } catch (e) { console.error('Embedded poll error:', e); }
        }, 2000);

        // Sync commands from YouTube content script
        window.addEventListener('message', (e) => {
            const d = e.data;
            if (!d || d.source !== 'dubber-ext') return;
            if (d.type === 'seek') {
                embTime = d.time;
                stopCurrentAudio();
                currentDubIndex = -1;
                embUpdateAudio(embTime);
                embUpdateSubtitle(embTime);
            }
            if (d.type === 'pause') {
                embPlaying = false;
                clearInterval(tickHandle);
                tickHandle = null;
                stopCurrentAudio();
            }
            if (d.type === 'play') {
                embPlaying = true;
                if (!tickHandle) tickHandle = setInterval(embTick, 100);
                if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
                embUpdateAudio(embTime);
            }
        });
    })();
})();
</script>
</body>
</html>
