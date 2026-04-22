chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    // 1. Try MAIN world — direct access to window.ytInitialPlayerResponse
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

    // 2. Fallback: fetch the YouTube page HTML and parse ytInitialPlayerResponse
    if (!videoUrl) return null;
    try {
        const resp = await fetch(videoUrl, {
            headers: { 'Accept-Language': 'en-US,en;q=0.9' }
        });
        const html = await resp.text();

        // Find ytInitialPlayerResponse = {...} using bracket depth
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

        const pr = JSON.parse(html.slice(objStart, i + 1));
        const captionTracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || null;
        const formats = pr.streamingData?.adaptiveFormats || [];
        const audioFmt = formats
            .filter(f => f.mimeType?.startsWith('audio/') && f.url)
            .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];

        return { captionTracks: captionTracks?.length ? captionTracks : null, audioUrl: audioFmt?.url || null };
    } catch { return null; }
}
