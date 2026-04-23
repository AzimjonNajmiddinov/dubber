chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    // 1. Try MAIN world — direct access to window.ytInitialPlayerResponse
    //    Content script then fetches caption URL (same browser session = valid ei token)
    try {
        const results = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                try {
                    const pr = window.ytInitialPlayerResponse;
                    if (!pr) return null;
                    const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || null;
                    const formats = pr.streamingData?.adaptiveFormats || [];
                    const audioFmt = formats
                        .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                        .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                    return { captionTracks, audioUrl: audioFmt?.url || null };
                } catch { return null; }
            },
        });
        const data = results?.[0]?.result;
        if (data?.captionTracks?.length) return data;
    } catch {}

    // 2. Fallback: fetch YouTube page + caption content entirely in background
    //    (background uses its own HTTP session, so we must also fetch caption here)
    if (!videoUrl) return null;
    try {
        const pageResp = await fetch(videoUrl, {
            headers: { 'Accept-Language': 'en-US,en;q=0.9' }
        });
        const html = await pageResp.text();

        const pr = parsePlayerResponse(html);
        if (!pr) return null;

        const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
        const formats = pr.streamingData?.adaptiveFormats || [];
        const audioFmt = formats
            .filter(f => f.mimeType?.startsWith('audio/') && f.url)
            .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
        const audioUrl = audioFmt?.url || null;

        // Pick best caption track
        const track = captionTracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
            || captionTracks.find(t => t.languageCode?.startsWith('en'))
            || captionTracks[0];

        if (!track?.baseUrl) return { captionTracks: null, audioUrl, srt: null };

        // Fetch caption content in same background session (ei token is valid here)
        const capResp = await fetch(track.baseUrl + '&fmt=json3');
        const capData = await capResp.json();
        const srt = json3ToSrt(capData);

        return { captionTracks: null, audioUrl, srt };
    } catch { return null; }
}

function parsePlayerResponse(html) {
    try {
        const marker = 'ytInitialPlayerResponse';
        const idx = html.indexOf(marker);
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
    const h = Math.floor(ms / 3600000), m = Math.floor(ms % 3600000 / 60000),
          s = Math.floor(ms % 60000 / 1000), f = ms % 1000;
    const p = n => String(n).padStart(2, '0');
    return `${p(h)}:${p(m)}:${p(s)},${String(f).padStart(3, '0')}`;
}
