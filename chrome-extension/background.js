chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    // MAIN world (synchronous): read ytInitialPlayerResponse + fetch caption via sync XHR
    // Sync XHR runs in page context → includes YouTube cookies → returns caption data
    try {
        const results = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                try {
                    const pr = window.ytInitialPlayerResponse;
                    if (!pr) return null;

                    const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
                    console.log('[Dubber-sync] tracks:', captionTracks.length);
                    const formats = pr.streamingData?.adaptiveFormats || [];
                    const audioFmt = formats
                        .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                        .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                    const audioUrl = audioFmt?.url || null;

                    if (!captionTracks.length) return { srt: null, audioUrl };

                    const track = captionTracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
                        || captionTracks.find(t => t.languageCode?.startsWith('en'))
                        || captionTracks[0];

                    if (!track?.baseUrl) return { srt: null, audioUrl };

                    // Synchronous XHR — page context, has YouTube cookies
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', track.baseUrl + '&fmt=json3', false);
                    xhr.send();
                    console.log('[Dubber-sync] XHR status:', xhr.status, 'len:', xhr.responseText.length, 'url:', track.baseUrl.slice(0, 60));

                    if (!xhr.responseText) return { srt: null, audioUrl };

                    const data = JSON.parse(xhr.responseText);
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

                    return { srt: srt || null, audioUrl };
                } catch { return null; }
            },
        });
        const data = results?.[0]?.result;
        if (data?.srt) return data;
        if (data) return data;
    } catch {}

    // Fallback: fetch YouTube page HTML in background, parse captionTracks
    // (caption URL fetch also happens here — ei token from same fetch session)
    if (!videoUrl) return null;
    try {
        const pageResp = await fetch(videoUrl, { headers: { 'Accept-Language': 'en-US,en;q=0.9' } });
        const html = await pageResp.text();
        const pr = parsePlayerResponse(html);
        if (!pr) return null;

        const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
        const formats = pr.streamingData?.adaptiveFormats || [];
        const audioFmt = formats
            .filter(f => f.mimeType?.startsWith('audio/') && f.url)
            .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
        const audioUrl = audioFmt?.url || null;

        const track = captionTracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
            || captionTracks.find(t => t.languageCode?.startsWith('en'))
            || captionTracks[0];

        if (!track?.baseUrl) return { srt: null, audioUrl };

        const capResp = await fetch(track.baseUrl + '&fmt=json3');
        const capData = await capResp.json();
        const srt = json3ToSrt(capData);
        return { srt, audioUrl };
    } catch { return null; }
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
