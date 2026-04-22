chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getYouTubeData') {
        chrome.scripting.executeScript({
            target: { tabId: sender.tab.id },
            world: 'MAIN',
            func: () => {
                try {
                    const pr = window.ytInitialPlayerResponse;
                    if (!pr) return null;

                    const captionTracks = pr.captions
                        ?.playerCaptionsTracklistRenderer
                        ?.captionTracks || null;

                    // Pick best audio-only stream URL (highest bitrate)
                    const formats = pr.streamingData?.adaptiveFormats || [];
                    const audioFmt = formats
                        .filter(f => f.mimeType?.startsWith('audio/') && f.url)
                        .sort((a, b) => (b.bitrate || 0) - (a.bitrate || 0))[0];

                    return {
                        captionTracks,
                        audioUrl: audioFmt?.url || null,
                        duration: pr.videoDetails?.lengthSeconds || null,
                    };
                } catch { return null; }
            },
        }).then(results => {
            sendResponse(results?.[0]?.result || null);
        }).catch(() => sendResponse(null));
        return true;
    }
});
