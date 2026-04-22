/**
 * Dubber Chrome Extension — content script
 * Injects a button into the YouTube player, creates a dubbing session,
 * and opens the existing instant-dub player in a popup window for audio.
 */

let dubState = {
    active:    false,
    sessionId: null,
    apiBase:   'https://dubbing.uz',
    language:  'uz',
    popup:     null,
};

// ─── Boot ───────────────────────────────────────────────────────────────────

chrome.storage.sync.get({ apiBase: 'https://dubbing.uz', language: 'uz' }, (s) => {
    dubState.apiBase   = s.apiBase;
    dubState.language  = s.language;
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
        showOverlay();
    }
}

function showOverlay() {
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
        font-family:Arial,sans-serif;min-width:300px;text-align:center;
    `;
    overlay.innerHTML = `
        <div style="font-size:18px;font-weight:bold;margin-bottom:16px">🎙 Dubber</div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:20px">
            ${languages.map(l => `
                <button data-lang="${l.code}" style="
                    padding:8px 14px;border-radius:8px;border:2px solid #1a73e8;
                    background:${dubState.language===l.code?'#1a73e8':'transparent'};
                    color:white;cursor:pointer;font-size:13px;
                ">${l.label}</button>
            `).join('')}
        </div>
        <div style="display:flex;gap:8px;justify-content:center">
            <button id="dubber-start" style="padding:10px 24px;background:#1a73e8;color:white;
                border:none;border-radius:8px;cursor:pointer;font-size:15px;font-weight:bold;">
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
}

// ─── Dubbing start ───────────────────────────────────────────────────────────

async function startDubbing(overlay) {
    const msgEl  = document.getElementById('dubber-msg');
    const startBtn = document.getElementById('dubber-start');
    startBtn.disabled = true;
    startBtn.textContent = 'Yuklanmoqda...';
    msgEl.textContent = 'YouTube subtitrlari olinmoqda...';

    try {
        const videoUrl = location.href.split('&list')[0].split('&index')[0];
        const srt      = await extractYouTubeCaptions();

        msgEl.textContent = srt
            ? `${countSrt(srt)} subtitle topildi. Serverga yuborilmoqda...`
            : 'Subtitle topilmadi — nutq tanib olish ishlatiladi...';

        const resp = await fetch(`${dubState.apiBase}/api/instant-dub/start`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({
                video_url:      videoUrl,
                language:       dubState.language,
                srt:            srt || '',
                title:          document.title.replace(' - YouTube', ''),
                quality:        'standard',
            }),
        });

        if (!resp.ok) throw new Error(`Server xatosi: ${resp.status}`);
        const { session_id } = await resp.json();

        dubState.sessionId = session_id;
        dubState.active    = true;
        overlay.remove();

        // Open the existing instant-dub player in a popup window
        openPlayerPopup(session_id);

        // Setup YouTube ↔ popup sync
        setupVideoSync();

        updateButton(true);
        toast('Dubbing player ochildi!');

    } catch (err) {
        msgEl.textContent = `Xato: ${err.message}`;
        startBtn.disabled = false;
        startBtn.textContent = 'Qayta urinish';
    }
}

function openPlayerPopup(sessionId) {
    const url = `${dubState.apiBase}/instant-dub?session_id=${sessionId}&embedded=1`;
    const w   = Math.min(500, screen.width - 40);
    const h   = Math.min(480, screen.height - 100);
    const l   = screen.width  - w - 20;
    const t   = Math.floor((screen.height - h) / 2);

    dubState.popup = window.open(
        url, 'dubber_player',
        `width=${w},height=${h},left=${l},top=${t},toolbar=0,location=0,menubar=0,status=0`
    );

    if (!dubState.popup) {
        toast('Popup bloklandi! Brauzeringizda popup ruxsat bering.');
    }
}

// ─── Video sync ──────────────────────────────────────────────────────────────

function setupVideoSync() {
    const video = document.querySelector('video');
    if (!video) return;

    const send = (msg) => {
        if (dubState.popup && !dubState.popup.closed) {
            dubState.popup.postMessage({ source: 'dubber-ext', ...msg },
                dubState.apiBase.startsWith('https://') ? dubState.apiBase : '*');
        }
    };

    video.addEventListener('seeked',  () => send({ type: 'seek',  time: video.currentTime }));
    video.addEventListener('pause',   () => send({ type: 'pause' }));
    video.addEventListener('play',    () => send({ type: 'play'  }));

    // Periodic drift correction (every 10s)
    dubState._syncInterval = setInterval(() => {
        if (!dubState.active || !video) return;
        send({ type: 'seek', time: video.currentTime });
    }, 10000);
}

// ─── Stop ────────────────────────────────────────────────────────────────────

function stopDubbing() {
    if (!dubState.active) return;

    if (dubState.sessionId) {
        fetch(`${dubState.apiBase}/api/instant-dub/${dubState.sessionId}/stop`, { method: 'POST' }).catch(() => {});
    }

    if (dubState.popup && !dubState.popup.closed) dubState.popup.close();
    clearInterval(dubState._syncInterval);

    dubState.active    = false;
    dubState.sessionId = null;
    dubState.popup     = null;

    updateButton(false);
    toast("Dubbing to'xtatildi");
}

// ─── YouTube caption extraction ───────────────────────────────────────────────

async function extractYouTubeCaptions() {
    try {
        const tracks = await getYtCaptionTracks();
        if (!tracks || !tracks.length) return null;

        const track = tracks.find(t => t.languageCode?.startsWith('en')) ||
                      tracks.find(t => !t.kind || t.kind !== 'asr') ||
                      tracks[0];
        if (!track?.baseUrl) return null;

        const resp = await fetch(track.baseUrl + '&fmt=json3');
        const data = await resp.json();
        return json3ToSrt(data);
    } catch { return null; }
}

function getYtCaptionTracks() {
    // Ask background service worker to run in MAIN world and read
    // window.ytInitialPlayerResponse directly — bypasses isolated world limit.
    return new Promise(resolve => {
        try {
            chrome.runtime.sendMessage({ type: 'getCaptionTracks' }, tracks => {
                if (chrome.runtime.lastError) { resolve(null); return; }
                resolve(tracks || null);
            });
        } catch { resolve(null); }
    });
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
