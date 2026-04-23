chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    if (!videoUrl) return null;

    // Step 1: get captionTracks + audioUrl from MAIN world (sync)
    let captionTrack = null, audioUrl = null;
    try {
        const r = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                const pr = window.ytInitialPlayerResponse;
                if (!pr) return null;
                const tracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
                const formats = pr.streamingData?.adaptiveFormats || [];
                const audioFmt = formats
                    .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                    .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                const track = tracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
                    || tracks.find(t => t.languageCode?.startsWith('en'))
                    || tracks[0];
                return { track: track || null, audioUrl: audioFmt?.url || null };
            },
        });
        const d = r?.[0]?.result;
        if (d?.track) { captionTrack = d.track; audioUrl = d.audioUrl; }
    } catch {}

    // If no track from page, try fetching page HTML
    if (!captionTrack) {
        try {
            const html = await (await fetch(videoUrl, { headers: { 'Accept-Language': 'en-US,en;q=0.9' } })).text();
            const pr = parsePlayerResponse(html);
            const tracks = pr?.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
            captionTrack = tracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
                || tracks.find(t => t.languageCode?.startsWith('en'))
                || tracks[0] || null;
            if (!audioUrl) {
                const formats = pr?.streamingData?.adaptiveFormats || [];
                const fmt = formats.filter(f => f.mimeType?.startsWith('audio/') && f.url)
                    .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                audioUrl = fmt?.url || null;
            }
        } catch {}
    }

    if (!captionTrack?.baseUrl) return { srt: null, audioUrl };

    const captionUrl = captionTrack.baseUrl + '&fmt=json3';

    // Step 2: trigger async fetch in MAIN world (page context = cookies), store in global
    try {
        await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: (url) => {
                window.__dubberSRT = 'loading';
                fetch(url).then(r => r.text()).then(t => {
                    window.__dubberSRT = t || 'empty';
                }).catch(e => {
                    window.__dubberSRT = 'err:' + e.message;
                });
            },
            args: [captionUrl],
        });
    } catch { return { srt: null, audioUrl }; }

    // Step 3: poll for result (up to 5s)
    for (let i = 0; i < 10; i++) {
        await new Promise(r => setTimeout(r, 500));
        try {
            const r2 = await chrome.scripting.executeScript({
                target: { tabId },
                world: 'MAIN',
                func: () => {
                    const v = window.__dubberSRT;
                    if (v && v !== 'loading') { delete window.__dubberSRT; return v; }
                    return null;
                },
            });
            const text = r2?.[0]?.result;
            if (text && text !== 'empty' && !text.startsWith('err:')) {
                const data = JSON.parse(text);
                return { srt: json3ToSrt(data), audioUrl };
            }
            if (text && text !== 'loading') break; // empty or error — stop polling
        } catch {}
    }

    return { srt: null, audioUrl };
}

function parsePlayerResponse(html) {
    try {
        const idx = html.indexOf('ytInitialPlayerResponse');
        if (idx === -1) return null;
        const objStart = html.indexOf('{', idx);
        if (objStart === -1) return null;
        let depth = 0, i = objStart;
        for (; i < html.length; i++) {
            const c = html[i];
            if (c === '{' || c === '[') depth++;
            else if (c === '}' || c === ']') { if (--depth === 0) break; }
        }
        return JSON.parse(html.slice(objStart, i + 1));
    } catch { return null; }
}

function json3ToSrt(data) {
    let srt = '', idx = 1;
    const p = n => String(n).padStart(2, '0');
    const fmt = ms => `${p(Math.floor(ms/3600000))}:${p(Math.floor(ms%3600000/60000))}:${p(Math.floor(ms%60000/1000))},${String(ms%1000).padStart(3,'0')}`;
    for (const ev of data.events || []) {
        if (!ev.segs || !ev.dDurationMs) continue;
        const text = ev.segs.map(s => (s.utf8 || '').replace(/\n/g, ' ')).join('').trim();
        if (!text) continue;
        const s = ev.tStartMs || 0, e = s + ev.dDurationMs;
        srt += `${idx}\n${fmt(s)} --> ${fmt(e)}\n${text}\n\n`;
        idx++;
    }
    return srt || null;
}
