/**
 * Dubber Chrome Extension — content script
 * Injects a button into the YouTube player and plays dubbed audio
 * directly in the YouTube tab via WebAudio API (no popup needed).
 */

let dubState = {
    active:          false,
    sessionId:       null,
    apiBase:         'https://dubbing.uz',
    language:        'uz',
    selectedVoiceId: null,
    audioCtx:        null,
    chunks:          [],
    lastChunkIdx:    -1,
    currentSrc:      null,
    currentIdx:      -1,
    pollTimer:       null,
    _origVolume:     null,
    _waitingToPlay:  false,
};

chrome.storage.sync.get({ apiBase: 'https://dubbing.uz', language: 'uz' }, (s) => {
    dubState.apiBase  = s.apiBase;
    dubState.language = s.language;
});

const observer = new MutationObserver(() => injectButtonIfNeeded());
observer.observe(document.body, { childList: true, subtree: true });
injectButtonIfNeeded();

// ─── Button injection ────────────────────────────────────────────────────────

function injectButtonIfNeeded() {
    if (document.getElementById('dubber-btn')) return;
    const rightControls = document.querySelector('.ytp-right-controls');
    if (!rightControls) return;

    const btn = document.createElement('button');
    btn.id = 'dubber-btn';
    btn.title = "O'zbek tilida ko'r (Dubber)";
    btn.style.cssText = `
        background:none;border:none;cursor:pointer;padding:0 6px;
        opacity:.85;display:flex;align-items:center;transition:opacity .15s;
    `;
    btn.innerHTML = `<svg width="36" height="22" viewBox="0 0 36 22">
        <rect width="36" height="22" rx="4" fill="#1a73e8"/>
        <text x="18" y="16" font-size="11" font-family="Arial,sans-serif"
              font-weight="bold" fill="white" text-anchor="middle">UZ</text>
    </svg>`;
    btn.addEventListener('mouseenter', () => btn.style.opacity = '1');
    btn.addEventListener('mouseleave', () => btn.style.opacity = dubState.active ? '1' : '.85');
    btn.addEventListener('click', onButtonClick);
    rightControls.prepend(btn);
}

// ─── Button click ────────────────────────────────────────────────────────────

function onButtonClick() {
    if (dubState.active) {
        stopDubbing();
    } else {
        // Create AudioContext on user gesture
        if (!dubState.audioCtx) {
            dubState.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        showOverlay();
    }
}

async function showOverlay() {
    if (document.getElementById('dubber-overlay')) return;

    const languages = [
        { code: 'uz', label: "O'zbek" },
        { code: 'ru', label: 'Русский' },
        { code: 'en', label: 'English' },
        { code: 'tr', label: 'Türkçe' },
        { code: 'kk', label: 'Қазақша' },
    ];

    const overlay = document.createElement('div');
    overlay.id = 'dubber-overlay';
    overlay.style.cssText = `
        position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        background:#1a1a2e;color:#fff;border-radius:12px;padding:24px 32px;
        z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.7);
        font-family:Arial,sans-serif;min-width:320px;text-align:center;
    `;
    overlay.innerHTML = `
        <div style="font-size:18px;font-weight:bold;margin-bottom:16px">🎙 Dubber</div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:16px">
            ${languages.map(l => `
                <button data-lang="${l.code}" style="
                    padding:8px 14px;border-radius:8px;border:2px solid #1a73e8;
                    background:${dubState.language===l.code?'#1a73e8':'transparent'};
                    color:white;cursor:pointer;font-size:13px;
                ">${l.label}</button>
            `).join('')}
        </div>
        <div style="margin-bottom:6px;font-size:13px;color:#aaa;text-align:left">Ovoz tanlang:</div>
        <select id="dubber-voices" style="
            width:100%;padding:8px 10px;background:#252540;border:1px solid #444;
            color:#fff;border-radius:8px;font-size:13px;margin-bottom:16px;cursor:pointer;
        ">
            <option value="">Yuklanmoqda...</option>
        </select>
        <div style="display:flex;gap:8px;justify-content:center">
            <button id="dubber-start" disabled style="padding:10px 24px;background:#555;color:#aaa;
                border:none;border-radius:8px;cursor:not-allowed;font-size:15px;font-weight:bold;">
                Boshlash
            </button>
            <button id="dubber-cancel" style="padding:10px 20px;background:#444;color:white;
                border:none;border-radius:8px;cursor:pointer;font-size:15px;">
                Bekor
            </button>
        </div>
        <div id="dubber-msg" style="margin-top:12px;font-size:13px;color:#aaa;min-height:18px"></div>
    `;

    document.body.appendChild(overlay);

    overlay.querySelectorAll('[data-lang]').forEach(b => {
        b.addEventListener('click', () => {
            dubState.language = b.dataset.lang;
            overlay.querySelectorAll('[data-lang]').forEach(x =>
                x.style.background = x.dataset.lang === dubState.language ? '#1a73e8' : 'transparent'
            );
        });
    });

    document.getElementById('dubber-cancel').addEventListener('click', () => overlay.remove());
    document.getElementById('dubber-start').addEventListener('click', () => startDubbing(overlay));

    // Load available voices
    loadVoices(overlay);
}

async function loadVoices(overlay) {
    const sel = document.getElementById('dubber-voices');
    if (!sel) return;

    try {
        const resp = await fetch(`${dubState.apiBase}/api/instant-dub/voices`);
        const data = resp.ok ? await resp.json() : [];
        const raw = Array.isArray(data) ? data : (data.voices || []);
        const seen = new Set();
        const voices = raw.filter(v => {
            const key = v.name || v.voice_id;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });

        sel.innerHTML = '';
        if (!voices.length) {
            sel.innerHTML = '<option value="">Ovozlar topilmadi</option>';
            return;
        }

        const groups = {};
        voices.forEach(v => {
            const g = v.gender || 'male';
            if (!groups[g]) groups[g] = [];
            groups[g].push(v);
        });
        const labels = { male: 'Erkak', female: 'Ayol', child: 'Bola' };
        let firstVoiceId = null;
        for (const [g, list] of Object.entries(groups)) {
            const grp = document.createElement('optgroup');
            grp.label = labels[g] || g;
            list.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.voice_id;
                opt.textContent = v.name || v.voice_id;
                if (!firstVoiceId) firstVoiceId = v.voice_id;
                grp.appendChild(opt);
            });
            sel.appendChild(grp);
        }

        dubState.selectedVoiceId = firstVoiceId;
        sel.value = firstVoiceId;
        sel.addEventListener('change', () => { dubState.selectedVoiceId = sel.value; });

        const startBtn = document.getElementById('dubber-start');
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.style.cssText = 'padding:10px 24px;background:#1a73e8;color:white;border:none;border-radius:8px;cursor:pointer;font-size:15px;font-weight:bold;';
        }

    } catch (e) {
        sel.innerHTML = '<option value="">Yuklashda xato</option>';
    }
}

// ─── Dubbing start ───────────────────────────────────────────────────────────

async function startDubbing(overlay) {
    const msgEl   = document.getElementById('dubber-msg');
    const startBtn = document.getElementById('dubber-start');
    startBtn.disabled = true;
    startBtn.textContent = 'Yuklanmoqda...';
    msgEl.textContent = 'YouTube ma\'lumotlari olinmoqda...';

    try {
        const videoUrl = location.href.split('&list')[0].split('&index')[0];
        const ytData   = await getYouTubeData();
        const srt      = ytData?.srt || null;
        const audioUrl = ytData?.audioUrl || null;

        msgEl.textContent = srt
            ? `${countSrt(srt)} subtitle topildi. Serverga yuborilmoqda...`
            : 'Subtitle topilmadi. Serverga yuborilmoqda...';

        const resp = await fetch(`${dubState.apiBase}/api/instant-dub/start`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({
                video_url:      videoUrl,
                language:       dubState.language,
                translate_from: 'en',
                srt:            srt || '',
                audio_url:      audioUrl || '',
                title:          document.title.replace(' - YouTube', ''),
                quality:        'standard',
                voice_id:       dubState.selectedVoiceId || undefined,
            }),
        });

        if (!resp.ok) throw new Error(`Server xatosi: ${resp.status}`);
        const { session_id } = await resp.json();

        dubState.sessionId      = session_id;
        dubState.active         = true;
        dubState.chunks         = [];
        dubState.lastChunkIdx   = -1;
        dubState._waitingToPlay = true;
        overlay.remove();

        // Pause video immediately, resume after 10% segments ready
        const video = document.querySelector('video');
        if (video) {
            dubState._origVolume = video.volume;
            video.volume = 0.08;
            video.pause();
            dubState._video = video;

            video.addEventListener('timeupdate', onVideoTimeUpdate);
            video.addEventListener('pause', onVideoPause);
            video.addEventListener('seeking', onVideoSeeking);
            video.addEventListener('play', onVideoPlay);
        }

        updateButton(true);
        showSubtitleBar();
        startPolling();
        toast("Ovoz yuklanmoqda...");

    } catch (err) {
        msgEl.textContent = `Xato: ${err.message}`;
        startBtn.disabled = false;
        startBtn.textContent = 'Qayta urinish';
    }
}

// ─── Polling ─────────────────────────────────────────────────────────────────

function startPolling() {
    stopPolling();
    doPoll();
    dubState.pollTimer = setInterval(doPoll, 2000);
}

function stopPolling() {
    if (dubState.pollTimer) { clearInterval(dubState.pollTimer); dubState.pollTimer = null; }
}

async function doPoll() {
    if (!dubState.sessionId) return;
    try {
        const resp = await fetch(`${dubState.apiBase}/api/instant-dub/${dubState.sessionId}/poll?after=${dubState.lastChunkIdx}`);
        if (!resp.ok) { if (resp.status === 404) stopPolling(); return; }
        const data = await resp.json();

        const ready = data.segments_ready || 0;
        const total = data.total_segments || 0;

        if (data.chunks && data.chunks.length > 0) {
            const ctx = dubState.audioCtx;
            if (ctx && ctx.state === 'suspended') ctx.resume();

            for (const c of data.chunks) {
                if (c.index > dubState.lastChunkIdx) dubState.lastChunkIdx = c.index;
                const cd = { start_time: c.start_time, end_time: c.end_time, text: c.text };
                if (c.audio_base64 && ctx) {
                    try {
                        cd._audioBuffer = await ctx.decodeAudioData(base64ToArrayBuffer(c.audio_base64));
                    } catch (e) {}
                }
                dubState.chunks[c.index] = cd;
            }
        }

        // Auto-play once 10% segments are ready
        if (data.playable && dubState._waitingToPlay) {
            dubState._waitingToPlay = false;
            const video = dubState._video;
            if (video) video.play().catch(() => {});
        }

        // Show progress while waiting, subtitle while playing
        if (dubState._waitingToPlay) {
            updateSubtitleBar(`Yuklanmoqda... ${ready} / ${total}`);
        }

        if (data.status === 'complete') {
            stopPolling();
        }
    } catch (e) {}
}

// ─── Audio playback ───────────────────────────────────────────────────────────

function _startChunk(i, t) {
    const ctx = dubState.audioCtx;
    const chunk = dubState.chunks[i];
    if (!chunk || !chunk._audioBuffer) return;
    const offset = Math.max(0, t - chunk.start_time);
    if (offset >= chunk._audioBuffer.duration) return;
    try {
        const src  = ctx.createBufferSource();
        const gain = ctx.createGain();
        src.buffer = chunk._audioBuffer;
        src.connect(gain);
        gain.connect(ctx.destination);
        // Fade in over 30ms to avoid abrupt click/mute at segment start
        gain.gain.setValueAtTime(0, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(1.8, ctx.currentTime + 0.03);
        src.start(0, offset);
        dubState.currentSrc = src;
        dubState.currentIdx = i;

        src.onended = () => {
            if (dubState.currentIdx !== i) return;
            dubState.currentSrc = null;
            dubState.currentIdx = -1;
            if (!dubState.active || !dubState._video || dubState._video.paused) return;
            // Time-based lookup, but skip the chunk that just ended to avoid restart
            playDubAudio(dubState._video.currentTime, i);
        };
    } catch (e) {}
}

function playDubAudio(t, excludeIdx = -1) {
    const ctx = dubState.audioCtx;
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume();

    let target = -1;
    for (let i = 0; i < dubState.chunks.length; i++) {
        if (i === excludeIdx) continue;
        const c = dubState.chunks[i];
        if (!c || !c._audioBuffer) continue;
        if (t >= c.start_time && t < c.start_time + c._audioBuffer.duration) { target = i; break; }
    }

    if (target === dubState.currentIdx) return;
    stopCurrentAudio();
    if (target < 0) return;
    _startChunk(target, t);
}

function stopCurrentAudio() {
    if (dubState.currentSrc) {
        try { dubState.currentSrc.stop(); } catch (e) {}
        dubState.currentSrc = null;
    }
    dubState.currentIdx = -1;
}

// ─── Subtitle display ─────────────────────────────────────────────────────────

function showSubtitleBar() {
    if (document.getElementById('dubber-sub')) return;
    const el = document.createElement('div');
    el.id = 'dubber-sub';
    el.style.cssText = `
        position:fixed;bottom:70px;left:50%;transform:translateX(-50%);
        background:rgba(0,0,0,0.82);color:#fff;padding:6px 18px;border-radius:6px;
        font-size:16px;font-family:Arial,sans-serif;z-index:9998;
        pointer-events:none;text-align:center;max-width:80%;line-height:1.4;
        transition:opacity .2s;min-height:28px;
    `;
    document.body.appendChild(el);
}

function updateSubtitleBar(text) {
    const el = document.getElementById('dubber-sub');
    if (el) { el.textContent = text; el.style.opacity = text ? '1' : '0'; }
}

let _lastSubText = '';
function updateSubtitle(t) {
    let found = '';
    for (const c of dubState.chunks) {
        if (c && t >= c.start_time && t <= c.end_time) { found = c.text; break; }
    }
    if (found === _lastSubText) return;
    _lastSubText = found;
    updateSubtitleBar(found);
}

// ─── Video event handlers (attached directly to <video> in startDubbing) ──────

function onVideoTimeUpdate() {
    if (!dubState.active) return;
    const t = this.currentTime;
    updateSubtitle(t);
    playDubAudio(t);
}
function onVideoPause()   { if (dubState.active) stopCurrentAudio(); }
function onVideoSeeking() { if (dubState.active) stopCurrentAudio(); }
function onVideoPlay()    {
    dubState._waitingToPlay = false;
    if (dubState.active && dubState.audioCtx?.state === 'suspended') dubState.audioCtx.resume();
}

// ─── Stop ────────────────────────────────────────────────────────────────────

function stopDubbing() {
    if (!dubState.active) return;

    stopPolling();
    stopCurrentAudio();

    const video = dubState._video || document.querySelector('video');
    if (video) {
        video.removeEventListener('timeupdate', onVideoTimeUpdate);
        video.removeEventListener('pause',      onVideoPause);
        video.removeEventListener('seeking',    onVideoSeeking);
        video.removeEventListener('play',       onVideoPlay);
        if (dubState._origVolume != null) video.volume = dubState._origVolume;
    }

    if (dubState.sessionId) {
        fetch(`${dubState.apiBase}/api/instant-dub/${dubState.sessionId}/stop`, { method: 'POST' }).catch(() => {});
    }

    const sub = document.getElementById('dubber-sub');
    if (sub) sub.remove();

    dubState.active         = false;
    dubState.sessionId      = null;
    dubState.chunks         = [];
    dubState.lastChunkIdx   = -1;
    dubState._origVolume    = null;
    dubState._video         = null;
    dubState._waitingToPlay = false;

    updateButton(false);
    toast("Dubbing to'xtatildi");
}

// ─── YouTube data extraction ──────────────────────────────────────────────────

function getYouTubeData() {
    return new Promise(resolve => {
        let done = false;
        const finish = (val) => { if (!done) { done = true; resolve(val); } };
        const timer = setTimeout(() => finish(getDomYouTubeData()), 3000);
        try {
            const videoUrl = location.href.split('&list')[0].split('&index')[0];
            chrome.runtime.sendMessage({ type: 'getYouTubeData', videoUrl }, data => {
                clearTimeout(timer);
                if (chrome.runtime.lastError || !data) finish(getDomYouTubeData());
                else finish(data);
            });
        } catch {
            clearTimeout(timer);
            finish(getDomYouTubeData());
        }
    });
}

function getDomYouTubeData() {
    try {
        for (const s of document.querySelectorAll('script')) {
            const text = s.textContent;
            if (!text.includes('captionTracks')) continue;
            const key = '"captionTracks"';
            const idx = text.indexOf(key);
            if (idx === -1) continue;
            const arrStart = text.indexOf('[', idx + key.length);
            if (arrStart === -1) continue;
            let depth = 0, i = arrStart;
            for (; i < text.length; i++) {
                const c = text[i];
                if (c === '[' || c === '{') depth++;
                else if (c === ']' || c === '}') { if (--depth === 0) break; }
            }
            try {
                const tracks = JSON.parse(text.slice(arrStart, i + 1));
                if (Array.isArray(tracks) && tracks.length > 0)
                    return { captionTracks: tracks, audioUrl: null };
            } catch {}
        }
    } catch {}
    return null;
}

function json3ToSrt(data) {
    let srt = '', idx = 1;
    for (const ev of data.events || []) {
        if (!ev.segs || !ev.dDurationMs) continue;
        const text = ev.segs.map(s => (s.utf8 || '').replace(/\n/g, ' ')).join('').trim();
        if (!text) continue;
        const s = ev.tStartMs || 0, e = s + ev.dDurationMs;
        srt += `${idx}\n${ms2srt(s)} --> ${ms2srt(e)}\n${text}\n\n`;
        idx++;
    }
    return srt || null;
}

function ms2srt(ms) {
    const h = Math.floor(ms/3600000), m = Math.floor(ms%3600000/60000),
          s = Math.floor(ms%60000/1000), f = ms % 1000;
    return `${p(h)}:${p(m)}:${p(s)},${String(f).padStart(3,'0')}`;
}
function p(n) { return String(n).padStart(2,'0'); }
function countSrt(s) { return (s.match(/^\d+$/gm) || []).length; }
function base64ToArrayBuffer(b64) {
    const bin = atob(b64), len = bin.length, bytes = new Uint8Array(len);
    for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
    return bytes.buffer;
}

// ─── UI helpers ───────────────────────────────────────────────────────────────

function updateButton(active) {
    const btn = document.getElementById('dubber-btn');
    if (!btn) return;
    const rect = btn.querySelector('rect');
    if (active) {
        btn.style.opacity = '1';
        btn.title = "Dubbingni to'xtatish";
        rect?.setAttribute('fill', '#e53935');
    } else {
        btn.style.opacity = '.85';
        btn.title = "O'zbek tilida ko'r (Dubber)";
        rect?.setAttribute('fill', '#1a73e8');
    }
}

let _toastTimer;
function toast(msg) {
    let el = document.getElementById('dubber-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'dubber-toast';
        el.style.cssText = `
            position:fixed;bottom:80px;right:20px;background:rgba(0,0,0,.87);
            color:#fff;padding:10px 16px;border-radius:8px;font-size:13px;
            font-family:Arial,sans-serif;z-index:9999;transition:opacity .3s;max-width:300px;
        `;
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.style.opacity = '1';
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => el.style.opacity = '0', 4000);
}
