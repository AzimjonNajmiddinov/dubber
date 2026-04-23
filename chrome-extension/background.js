chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    if (!videoUrl) return null;

    // Step 1: get captionTracks + audioUrl from page via MAIN world (sync, no XHR)
    let captionTracks = [], audioUrl = null;
    try {
        const results = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                try {
                    const pr = window.ytInitialPlayerResponse;
                    if (!pr) return null;

                    const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
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

                    // Sync XHR in page context — cookies included automatically
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', track.baseUrl + '&fmt=json3', false);
                    xhr.send();

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
        const d = results?.[0]?.result;
        if (d?.captionTracks?.length) {
            captionTracks = d.captionTracks;
            audioUrl = d.audioUrl;
        }
    } catch {}

    // Step 2: if no captionTracks from page, fetch YouTube page HTML
    if (!captionTracks.length) {
        try {
            const pageResp = await fetch(videoUrl, { headers: { 'Accept-Language': 'en-US,en;q=0.9' } });
            const html = await pageResp.text();
            const pr = parsePlayerResponse(html);
            captionTracks = pr?.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
            if (!audioUrl) {
                const formats = pr?.streamingData?.adaptiveFormats || [];
                const audioFmt = formats
                    .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                    .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                audioUrl = audioFmt?.url || null;
            }
        } catch {}
    }

    // Step 3: pick best caption track
    const track = captionTracks.find(t => t.languageCode?.startsWith('en') && t.kind !== 'asr')
        || captionTracks.find(t => t.languageCode?.startsWith('en'))
        || captionTracks[0];

    if (!track?.baseUrl) return { srt: null, audioUrl };

    // Step 4: get YouTube cookies and fetch caption URL with them
    try {
        const cookies = await chrome.cookies.getAll({ domain: '.youtube.com' });
        const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

        const capResp = await fetch(track.baseUrl + '&fmt=json3', {
            headers: {
                'Cookie': cookieHeader,
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            },
        });
        const text = await capResp.text();
        if (!text) return { srt: null, audioUrl };

        const data = JSON.parse(text);
        const srt = json3ToSrt(data);
        return { srt, audioUrl };
    } catch { return { srt: null, audioUrl }; }
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
