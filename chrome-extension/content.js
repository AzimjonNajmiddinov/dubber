/**
 * Dubber Chrome Extension — content script
 * Injected into youtube.com/watch pages.
 * Extracts captions, calls Dubber API, replaces audio with dubbed version.
 */

const SEGMENT_DURATION = 30; // seconds per bg chunk (must match backend CHUNK_SIZE)
const POLL_INTERVAL    = 2000; // ms between status polls

let dubState = {
    active: false,
    sessionId: null,
    apiBase: 'https://dubbing.uz',
    language: 'uz',
    player: null,
};

// ─── Entry point ────────────────────────────────────────────────────────────

function init() {
    // Load saved settings
    chrome.storage.sync.get({ apiBase: 'https://dubbing.uz', language: 'uz' }, (s) => {
        dubState.apiBase = s.apiBase;
        dubState.language = s.language;
    });

    // YouTube is a SPA — watch for navigation
    const observer = new MutationObserver(() => injectButtonIfNeeded());
    observer.observe(document.body, { childList: true, subtree: true });
    injectButtonIfNeeded();
}

function injectButtonIfNeeded() {
    if (document.getElementById('dubber-btn')) return;

    const rightControls = document.querySelector('.ytp-right-controls');
    if (!rightControls) return;

    const btn = document.createElement('button');
    btn.id = 'dubber-btn';
    btn.title = "O'zbek tilida ko'r (Dubber)";
    btn.innerHTML = `<svg width="36" height="24" viewBox="0 0 36 24" style="vertical-align:middle">
        <rect width="36" height="24" rx="4" fill="#1a73e8"/>
        <text x="18" y="17" font-size="11" font-family="Arial,sans-serif" font-weight="bold"
              fill="white" text-anchor="middle">UZ</text>
    </svg>`;
    btn.style.cssText = `
        background: none; border: none; cursor: pointer;
        padding: 0 4px; opacity: 0.85; display: flex; align-items: center;
        transition: opacity .15s;
    `;
    btn.addEventListener('mouseenter', () => btn.style.opacity = '1');
    btn.addEventListener('mouseleave', () => btn.style.opacity = dubState.active ? '1' : '0.85');
    btn.addEventListener('click', onButtonClick);

    rightControls.prepend(btn);
}

// ─── Button click handler ────────────────────────────────────────────────────

function onButtonClick() {
    if (dubState.active) {
        stopDubbing();
    } else {
        showLanguageOverlay();
    }
}

function showLanguageOverlay() {
    if (document.getElementById('dubber-overlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'dubber-overlay';
    overlay.style.cssText = `
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        background: #1a1a2e; color: #fff; border-radius: 12px; padding: 24px 32px;
        z-index: 9999; box-shadow: 0 8px 32px rgba(0,0,0,.6);
        font-family: Arial, sans-serif; min-width: 300px; text-align: center;
    `;

    const languages = [
        { code: 'uz', label: "O'zbek" },
        { code: 'ru', label: 'Русский' },
        { code: 'en', label: 'English' },
        { code: 'tr', label: 'Türkçe' },
    ];

    overlay.innerHTML = `
        <div style="font-size:18px;font-weight:bold;margin-bottom:16px">
            🎙 Dubber — Tilni tanlang
        </div>
        <div id="dubber-lang-btns" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:20px">
            ${languages.map(l => `
                <button data-lang="${l.code}" style="
                    padding:8px 16px;border-radius:8px;border:2px solid #1a73e8;
                    background:${dubState.language===l.code?'#1a73e8':'transparent'};
                    color:white;cursor:pointer;font-size:14px;transition:all .15s;
                ">${l.label}</button>
            `).join('')}
        </div>
        <div style="display:flex;gap:8px;justify-content:center">
            <button id="dubber-start-btn" style="
                padding:10px 24px;background:#1a73e8;color:white;border:none;
                border-radius:8px;cursor:pointer;font-size:15px;font-weight:bold;
            ">Boshlash</button>
            <button id="dubber-cancel-btn" style="
                padding:10px 24px;background:#444;color:white;border:none;
                border-radius:8px;cursor:pointer;font-size:15px;
            ">Bekor</button>
        </div>
        <div id="dubber-status" style="margin-top:14px;font-size:13px;color:#aaa;min-height:20px"></div>
    `;

    document.body.appendChild(overlay);

    // Language button selection
    overlay.querySelectorAll('[data-lang]').forEach(b => {
        b.addEventListener('click', () => {
            dubState.language = b.dataset.lang;
            overlay.querySelectorAll('[data-lang]').forEach(x => {
                x.style.background = x.dataset.lang === dubState.language ? '#1a73e8' : 'transparent';
            });
        });
    });

    document.getElementById('dubber-cancel-btn').addEventListener('click', () => overlay.remove());
    document.getElementById('dubber-start-btn').addEventListener('click', () => startDubbing(overlay));
}

// ─── Dubbing lifecycle ───────────────────────────────────────────────────────

async function startDubbing(overlay) {
    const statusEl = document.getElementById('dubber-status');
    const startBtn = document.getElementById('dubber-start-btn');
    startBtn.disabled = true;
    startBtn.textContent = 'Yuklanmoqda...';
    statusEl.textContent = 'YouTube subtitrlari olinmoqda...';

    try {
        const videoUrl = window.location.href.split('&')[0]; // clean URL
        const srt = await extractYouTubeCaptions();

        statusEl.textContent = srt
            ? `${countSrtLines(srt)} subtitle topildi. API ga yuborilmoqda...`
            : 'Subtitle topilmadi — Speech recognition ishlatiladi...';

        const resp = await fetch(`${dubState.apiBase}/api/instant-dub/start`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                video_url: videoUrl,
                language:  dubState.language,
                srt:       srt || '',
                title:     document.title.replace(' - YouTube', ''),
                quality:   'standard',
            }),
        });

        if (!resp.ok) throw new Error(`API xatosi: ${resp.status}`);
        const { session_id } = await resp.json();

        dubState.sessionId = session_id;
        dubState.active    = true;
        updateButtonActive(true);
        overlay.remove();

        showStatusToast('Dubbing tayyorlanmoqda...');
        pollForPlayable(session_id);

    } catch (err) {
        statusEl.textContent = `Xato: ${err.message}`;
        startBtn.disabled = false;
        startBtn.textContent = 'Qayta urinish';
    }
}

function stopDubbing() {
    if (!dubState.active) return;

    if (dubState.sessionId) {
        fetch(`${dubState.apiBase}/api/instant-dub/${dubState.sessionId}/stop`, { method: 'POST' })
            .catch(() => {});
    }

    dubState.active    = false;
    dubState.sessionId = null;

    if (dubState.player) {
        dubState.player.stop();
        dubState.player = null;
    }

    updateButtonActive(false);
    showStatusToast('Dubbing to\'xtatildi');
}

// ─── Polling ─────────────────────────────────────────────────────────────────

async function pollForPlayable(sessionId) {
    while (dubState.active && dubState.sessionId === sessionId) {
        try {
            const resp = await fetch(
                `${dubState.apiBase}/api/instant-dub/${sessionId}/poll`,
                { headers: { Accept: 'application/json' } }
            );
            if (!resp.ok) throw new Error(resp.status);
            const data = await resp.json();

            const ready = data.segments_ready || 0;
            const total = data.total_segments || 0;

            if (total > 0) {
                showStatusToast(`Dubbing: ${ready}/${total} segment tayyor...`);
            }

            if (data.playable || data.status === 'complete') {
                startAudioSync(sessionId);
                return;
            }

            if (data.status === 'error') {
                showStatusToast('Dubbing xatosi yuz berdi');
                stopDubbing();
                return;
            }
        } catch { /* retry */ }

        await sleep(POLL_INTERVAL);
    }
}

// ─── Audio sync player ───────────────────────────────────────────────────────

function startAudioSync(sessionId) {
    const video = document.querySelector('video');
    if (!video) return;

    showStatusToast('Dubbing boshlandi!');

    dubState.player = new DubPlayer(dubState.apiBase, sessionId, video);
    dubState.player.start();
}

class DubPlayer {
    constructor(apiBase, sessionId, video) {
        this.apiBase    = apiBase;
        this.sessionId  = sessionId;
        this.video      = video;
        this.ctx        = null;
        this.buffers    = {};        // segIndex → AudioBuffer
        this.gainNode   = null;
        this.currentSrc = null;
        this.currentIdx = -1;
        this.running    = false;
        this._handlers  = {};
    }

    start() {
        this.ctx      = new AudioContext();
        this.gainNode = this.ctx.createGain();
        this.gainNode.connect(this.ctx.destination);
        this.video.muted = true;
        this.running  = true;

        this._handlers.seeked  = () => this._sync();
        this._handlers.play    = () => { this.ctx.resume(); this._sync(); };
        this._handlers.pause   = () => this.ctx.suspend();
        this._handlers.ended   = () => this.stop();

        for (const [ev, fn] of Object.entries(this._handlers)) {
            this.video.addEventListener(ev, fn);
        }

        // Drift check every 3s
        this._driftTimer = setInterval(() => this._checkDrift(), 3000);

        // Pre-buffer loop
        this._bufferLoop();

        this._sync();
    }

    stop() {
        this.running = false;
        this.video.muted = false;

        for (const [ev, fn] of Object.entries(this._handlers)) {
            this.video.removeEventListener(ev, fn);
        }

        clearInterval(this._driftTimer);

        if (this.currentSrc) { try { this.currentSrc.stop(); } catch {} this.currentSrc = null; }
        if (this.ctx)        { this.ctx.close(); this.ctx = null; }
    }

    _segUrl(idx) {
        return `${this.apiBase}/api/instant-dub/${this.sessionId}/dub-segment/bg-${idx}.aac`;
    }

    async _fetchBuffer(idx) {
        if (this.buffers[idx]) return this.buffers[idx];
        try {
            const resp = await fetch(this._segUrl(idx));
            if (!resp.ok) return null;
            const ab  = await resp.arrayBuffer();
            const buf = await this.ctx.decodeAudioData(ab);
            this.buffers[idx] = buf;
            return buf;
        } catch { return null; }
    }

    async _sync() {
        if (!this.running || !this.ctx) return;

        const vt  = this.video.currentTime;
        const idx = Math.floor(vt / SEGMENT_DURATION);
        const off = vt % SEGMENT_DURATION;

        this._stopCurrent();
        await this._playFrom(idx, off);
    }

    async _playFrom(idx, offset) {
        if (!this.running || !this.ctx) return;
        const buf = await this._fetchBuffer(idx);
        if (!buf || !this.running) return;

        this._stopCurrent();

        const src = this.ctx.createBufferSource();
        src.buffer = buf;
        src.connect(this.gainNode);
        src.start(0, Math.min(offset, buf.duration - 0.01));
        this.currentSrc = src;
        this.currentIdx = idx;

        src.onended = () => {
            if (this.running && this.currentIdx === idx) {
                this._playFrom(idx + 1, 0);
            }
        };
    }

    _stopCurrent() {
        if (this.currentSrc) {
            try { this.currentSrc.onended = null; this.currentSrc.stop(); } catch {}
            this.currentSrc = null;
        }
    }

    _checkDrift() {
        if (!this.running || this.video.paused) return;
        const vt  = this.video.currentTime;
        const idx = Math.floor(vt / SEGMENT_DURATION);
        if (idx !== this.currentIdx) this._sync();
    }

    async _bufferLoop() {
        while (this.running) {
            const vt  = this.video.currentTime;
            const idx = Math.floor(vt / SEGMENT_DURATION);
            // Pre-fetch current + next 2 segments
            for (let i = idx; i <= idx + 2; i++) {
                if (!this.buffers[i]) await this._fetchBuffer(i);
            }
            // Clean old buffers (keep last 2)
            for (const k of Object.keys(this.buffers)) {
                if (Number(k) < idx - 2) delete this.buffers[k];
            }
            await sleep(8000);
        }
    }
}

// ─── YouTube caption extraction ───────────────────────────────────────────────

async function extractYouTubeCaptions() {
    try {
        // ytInitialPlayerResponse is available in the page context
        const playerData = getYtPlayerData();
        if (!playerData) return null;

        const tracks = playerData?.captions?.playerCaptionsTracklistRenderer?.captionTracks;
        if (!tracks || tracks.length === 0) return null;

        // Prefer English or first available track
        const track = tracks.find(t => t.languageCode?.startsWith('en')) || tracks[0];
        if (!track?.baseUrl) return null;

        const url  = track.baseUrl + '&fmt=json3';
        const resp = await fetch(url);
        const data = await resp.json();

        return jsonCaptionToSrt(data);
    } catch { return null; }
}

function getYtPlayerData() {
    // Try to read ytInitialPlayerResponse from a script tag
    const scripts = document.querySelectorAll('script');
    for (const s of scripts) {
        const m = s.textContent.match(/ytInitialPlayerResponse\s*=\s*(\{.+?\});/s);
        if (m) {
            try { return JSON.parse(m[1]); } catch {}
        }
    }
    return null;
}

function jsonCaptionToSrt(data) {
    let srt = '';
    let idx = 1;
    for (const event of data.events || []) {
        if (!event.segs || !event.dDurationMs) continue;
        const text = event.segs.map(s => (s.utf8 || '').replace(/\n/g, ' ')).join('').trim();
        if (!text) continue;
        const start = event.tStartMs || 0;
        const end   = start + (event.dDurationMs || 2000);
        srt += `${idx}\n${msToSrtTime(start)} --> ${msToSrtTime(end)}\n${text}\n\n`;
        idx++;
    }
    return srt || null;
}

function msToSrtTime(ms) {
    const h  = Math.floor(ms / 3600000);
    const m  = Math.floor((ms % 3600000) / 60000);
    const s  = Math.floor((ms % 60000) / 1000);
    const ms_ = ms % 1000;
    return `${pad(h)}:${pad(m)}:${pad(s)},${String(ms_).padStart(3, '0')}`;
}

function pad(n) { return String(n).padStart(2, '0'); }

function countSrtLines(srt) {
    return (srt.match(/^\d+$/gm) || []).length;
}

// ─── UI helpers ──────────────────────────────────────────────────────────────

function updateButtonActive(active) {
    const btn = document.getElementById('dubber-btn');
    if (!btn) return;
    if (active) {
        btn.style.opacity = '1';
        btn.title = "Dubbingni to'xtatish";
        btn.querySelector('rect')?.setAttribute('fill', '#e53935');
    } else {
        btn.style.opacity = '0.85';
        btn.title = "O'zbek tilida ko'r (Dubber)";
        btn.querySelector('rect')?.setAttribute('fill', '#1a73e8');
    }
}

let _toastTimer = null;
function showStatusToast(msg) {
    let toast = document.getElementById('dubber-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'dubber-toast';
        toast.style.cssText = `
            position:fixed;bottom:80px;right:20px;background:rgba(0,0,0,.85);
            color:white;padding:10px 16px;border-radius:8px;font-size:13px;
            font-family:Arial,sans-serif;z-index:9999;transition:opacity .3s;
            max-width:300px;line-height:1.4;
        `;
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.opacity = '1';
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => { toast.style.opacity = '0'; }, 4000);
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ─── Boot ─────────────────────────────────────────────────────────────────────

init();
