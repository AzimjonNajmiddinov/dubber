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

    // Step 3: get audioUrl + detectedLanguage + captionBaseUrl from MAIN world
    let audioUrl = null;
    let detectedLanguage = null;
    let captionBaseUrl = null;
    try {
        const r = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: () => {
                const pr = window.ytInitialPlayerResponse;
                if (!pr) return null;

                // Audio URL — pick highest-bitrate audio-only stream
                const formats = pr.streamingData?.adaptiveFormats || [];
                const audioFmt = formats
                    .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                    .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];

                // Caption language + baseUrl — prefer manual over auto-generated
                const tracks = pr.captions?.playerCaptionsTracklistRenderer?.captionTracks || [];
                const defaultTrack = tracks.find(t => t.isDefault) || tracks.find(t => !t.kind || t.kind !== 'asr') || tracks[0];
                const lang = defaultTrack?.languageCode?.slice(0, 2) || null;
                const baseUrl = defaultTrack?.baseUrl || null;

                return { audioUrl: audioFmt?.url || null, detectedLanguage: lang, captionBaseUrl: baseUrl };
            },
        });
        const result = r?.[0]?.result;
        audioUrl        = result?.audioUrl        || null;
        detectedLanguage = result?.detectedLanguage || null;
        captionBaseUrl  = result?.captionBaseUrl  || null;
    } catch {}

    // Step 4: if intercept missed, fetch caption directly via baseUrl (runs in extension context, no IP block)
    if (!srtText && captionBaseUrl) {
        try {
            const url = captionBaseUrl.includes('fmt=') ? captionBaseUrl : captionBaseUrl + '&fmt=json3';
            const resp = await fetch(url);
            if (resp.ok) srtText = await resp.text();
        } catch {}
    }

    if (!srtText) return { srt: null, audioUrl, detectedLanguage };

    try {
        const data = JSON.parse(srtText);
        return { srt: json3ToSrt(data), audioUrl, detectedLanguage };
    } catch { return { srt: null, audioUrl, detectedLanguage }; }
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
