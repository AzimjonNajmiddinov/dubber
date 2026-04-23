chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        getYouTubeData(sender.tab.id, msg.videoUrl).then(sendResponse).catch(() => sendResponse(null));
        return true;
    }
});

async function getYouTubeData(tabId, videoUrl) {
    // Step 1: inject fetch/XHR interceptor + trigger CC button in MAIN world
    try {
        await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                if (window.__dubberInterceptorSet) return;
                window.__dubberInterceptorSet = true;
                window.__dubberSRT = null;

                // Intercept fetch
                const origFetch = window.fetch;
                window.fetch = function(input, init) {
                    const url = typeof input === 'string' ? input : input?.url;
                    const p = origFetch.call(this, input, init);
                    if (url && url.includes('/api/timedtext')) {
                        p.then(r => r.clone().text()).then(t => {
                            if (t && t.length > 10) {
                                window.__dubberSRT = t;
                            }
                        }).catch(() => {});
                    }
                    return p;
                };

                // Intercept XHR
                const origOpen = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function(m, url, ...a) {
                    if (url && url.includes('/api/timedtext')) this.__dubberCaption = true;
                    return origOpen.apply(this, [m, url, ...a]);
                };
                const origSend = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.send = function(...a) {
                    if (this.__dubberCaption) {
                        this.addEventListener('load', function() {
                            if (this.responseText && this.responseText.length > 10) {
                                window.__dubberSRT = this.responseText;
                            }
                        });
                    }
                    return origSend.apply(this, a);
                };

                // Trigger CC button to make player fetch captions
                const ccBtn = document.querySelector('.ytp-subtitles-button');
                const wasActive = ccBtn?.getAttribute('aria-pressed') === 'true';
                if (ccBtn && !wasActive) {
                    ccBtn.click();
                    window.__dubberCCWasOff = true;
                }
            },
        });
    } catch {}

    // Step 2: poll up to 6s for intercepted SRT
    let srtText = null;
    for (let i = 0; i < 12; i++) {
        await new Promise(r => setTimeout(r, 500));
        try {
            const r = await chrome.scripting.executeScript({
                target: { tabId },
                world: 'MAIN',
                func: () => {
                    const v = window.__dubberSRT;
                    if (v) {
                        window.__dubberSRT = null;
                        // Restore CC button if we enabled it
                        if (window.__dubberCCWasOff) {
                            window.__dubberCCWasOff = false;
                            document.querySelector('.ytp-subtitles-button')?.click();
                        }
                        return v;
                    }
                    return null;
                },
            });
            srtText = r?.[0]?.result;
            if (srtText) break;
        } catch {}
    }

    // Step 3: get audioUrl from MAIN world
    let audioUrl = null;
    try {
        const r = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                const pr = window.ytInitialPlayerResponse;
                if (!pr) return null;
                const formats = pr.streamingData?.adaptiveFormats || [];
                const f = formats.filter(f => f.mimeType?.startsWith('audio/') && f.url)
                    .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];
                return f?.url || null;
            },
        });
        audioUrl = r?.[0]?.result || null;
    } catch {}

    if (!srtText) return { srt: null, audioUrl };

    try {
        const data = JSON.parse(srtText);
        return { srt: json3ToSrt(data), audioUrl };
    } catch { return { srt: null, audioUrl }; }
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
