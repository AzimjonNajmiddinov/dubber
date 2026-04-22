chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'getCaptionTracks') {
        chrome.scripting.executeScript({
            target: { tabId: sender.tab.id },
            world: 'MAIN',
            func: () => {
                try {
                    return window.ytInitialPlayerResponse
                        ?.captions
                        ?.playerCaptionsTracklistRenderer
                        ?.captionTracks || null;
                } catch { return null; }
            },
        }).then(results => {
            sendResponse(results?.[0]?.result || null);
        }).catch(() => sendResponse(null));
        return true; // keep message channel open for async response
    }
});
