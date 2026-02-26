/**
 * Dubber Browser Extension - Background Service Worker
 *
 * Handles progressive dubbing via HTTP polling.
 * Mode 1 (server): sends URL → server downloads & processes → extension polls for chunks.
 * Mode 2 (capture): content script captures audio → sends to server → polls for chunks.
 */

const DEFAULT_SETTINGS = {
  serverUrl: 'http://localhost:8080',
  targetLanguage: 'uz',
  ttsDriver: 'edge',
  autoCloneVoices: true,
  enabled: true,
  volume: {
    original: 0.2,
    dubbed: 1.0
  }
};

// Active dubbing sessions: tabId → { sessionId, mode, pollTimer, lastChunk, chunks[], status }
const activeSessions = new Map();

chrome.runtime.onInstalled.addListener(async () => {
  const stored = await chrome.storage.sync.get('settings');
  if (!stored.settings) {
    await chrome.storage.sync.set({ settings: DEFAULT_SETTINGS });
  }
  console.log('Dubber extension installed');
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const tabId = sender.tab?.id ?? message.tabId;
  handleMessage(message, tabId).then(sendResponse);
  return true;
});

async function handleMessage(message, tabId) {
  const { action, data } = message;

  switch (action) {
    case 'getSettings':
      return getSettings();

    case 'saveSettings':
      return saveSettings(data);

    case 'startDubbing':
      return startDubbing(tabId, data);

    case 'stopDubbing':
      return stopDubbing(tabId);

    case 'getDubbingStatus':
      return getDubbingStatus(tabId ?? message.tabId);

    case 'sendCaptureChunk':
      return sendCaptureChunk(tabId, data);

    case 'getAvailableVoices':
      return getAvailableVoices(data?.language);

    default:
      return { error: `Unknown action: ${action}` };
  }
}

async function getSettings() {
  const stored = await chrome.storage.sync.get('settings');
  return stored.settings || DEFAULT_SETTINGS;
}

async function saveSettings(settings) {
  await chrome.storage.sync.set({ settings: { ...DEFAULT_SETTINGS, ...settings } });
  return { success: true };
}

async function startDubbing(tabId, data) {
  const settings = await getSettings();

  if (!settings.enabled) {
    return { error: 'Dubbing is disabled' };
  }

  // Stop existing session for this tab
  if (activeSessions.has(tabId)) {
    await stopDubbing(tabId);
  }

  try {
    const response = await fetch(`${settings.serverUrl}/api/progressive/start`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        url: data.videoUrl,
        target_language: settings.targetLanguage,
        tts_driver: settings.ttsDriver,
      }),
    });

    if (!response.ok) {
      const err = await response.text();
      throw new Error(`Server error ${response.status}: ${err}`);
    }

    const result = await response.json();

    const session = {
      sessionId: result.session_id,
      tabId,
      mode: result.mode,
      status: 'started',
      startTime: Date.now(),
      lastChunkIndex: -1,
      chunksReady: 0,
      totalChunks: null,
      pollTimer: null,
    };

    activeSessions.set(tabId, session);

    // Notify content script
    chrome.tabs.sendMessage(tabId, {
      action: 'dubbingStarted',
      mode: result.mode,
      sessionId: result.session_id,
    });

    // Start polling for server mode, or start capture for capture mode
    if (result.mode === 'server') {
      startPolling(tabId);
    }
    // For capture mode, content script will start capturing and send chunks via sendCaptureChunk

    // Set up alarm as heartbeat backup to keep service worker alive
    chrome.alarms.create(`poll-${tabId}`, { periodInMinutes: 0.25 });

    return { success: true, sessionId: result.session_id, mode: result.mode };

  } catch (error) {
    console.error('startDubbing error:', error);
    return { error: error.message };
  }
}

function startPolling(tabId) {
  const session = activeSessions.get(tabId);
  if (!session) return;

  // Poll every 2.5 seconds
  session.pollTimer = setInterval(() => pollForChunks(tabId), 2500);
  // Also poll immediately
  pollForChunks(tabId);
}

async function pollForChunks(tabId) {
  const session = activeSessions.get(tabId);
  if (!session) return;

  const settings = await getSettings();

  try {
    const url = `${settings.serverUrl}/api/progressive/${session.sessionId}/poll?after_chunk=${session.lastChunkIndex}`;
    const response = await fetch(url);

    if (!response.ok) {
      if (response.status === 404) {
        await stopDubbing(tabId);
      }
      return;
    }

    const data = await response.json();

    session.status = data.status;
    session.chunksReady = data.chunks_ready;
    session.totalChunks = data.total_chunks;

    // Forward new chunks to content script
    if (data.chunks && data.chunks.length > 0) {
      for (const chunk of data.chunks) {
        session.lastChunkIndex = Math.max(session.lastChunkIndex, chunk.index);

        chrome.tabs.sendMessage(tabId, {
          action: 'newChunkReady',
          chunk,
        });
      }
    }

    // Check if dubbing is complete
    if (data.status === 'complete') {
      chrome.tabs.sendMessage(tabId, { action: 'dubbingComplete' });
      stopPolling(tabId);
    } else if (data.status === 'error') {
      chrome.tabs.sendMessage(tabId, {
        action: 'dubbingError',
        error: 'Server processing error',
      });
      stopPolling(tabId);
    }

  } catch (error) {
    console.error('Poll error:', error);
  }
}

function stopPolling(tabId) {
  const session = activeSessions.get(tabId);
  if (session?.pollTimer) {
    clearInterval(session.pollTimer);
    session.pollTimer = null;
  }
  chrome.alarms.clear(`poll-${tabId}`);
}

async function stopDubbing(tabId) {
  const session = activeSessions.get(tabId);

  if (session) {
    stopPolling(tabId);

    const settings = await getSettings();

    // Tell server to stop
    try {
      await fetch(`${settings.serverUrl}/api/progressive/${session.sessionId}/stop`, {
        method: 'POST',
      });
    } catch (e) {
      // Ignore — server may already be done
    }

    activeSessions.delete(tabId);
  }

  // Notify content script to restore audio
  try {
    chrome.tabs.sendMessage(tabId, { action: 'restoreAudio' });
  } catch (e) {
    // Tab may have been closed
  }

  return { success: true };
}

async function getDubbingStatus(tabId) {
  const session = activeSessions.get(tabId);

  if (!session) {
    return { active: false };
  }

  return {
    active: true,
    status: session.status,
    mode: session.mode,
    duration: Date.now() - session.startTime,
    chunksReady: session.chunksReady,
    totalChunks: session.totalChunks,
  };
}

async function sendCaptureChunk(tabId, data) {
  const session = activeSessions.get(tabId);

  if (!session) {
    return { error: 'No active dubbing session' };
  }

  const settings = await getSettings();

  try {
    const response = await fetch(
      `${settings.serverUrl}/api/progressive/${session.sessionId}/capture-chunk`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          audio: data.audioBase64,
          timestamp: data.timestamp,
          duration: data.duration,
          index: data.index,
        }),
      }
    );

    if (!response.ok) {
      throw new Error(`Capture chunk failed: ${response.status}`);
    }

    // Start polling if not already polling (first capture chunk triggers it)
    if (!session.pollTimer) {
      startPolling(tabId);
    }

    return { success: true };

  } catch (error) {
    console.error('sendCaptureChunk error:', error);
    return { error: error.message };
  }
}

async function getAvailableVoices(language) {
  const settings = await getSettings();

  try {
    const response = await fetch(`${settings.serverUrl}/api/realtime/voices?language=${language || 'uz'}`);
    if (!response.ok) throw new Error('Failed to fetch voices');
    return await response.json();
  } catch (error) {
    return { error: error.message };
  }
}

// Alarm handler as backup heartbeat for polling
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name.startsWith('poll-')) {
    const tabId = parseInt(alarm.name.replace('poll-', ''), 10);
    if (activeSessions.has(tabId)) {
      pollForChunks(tabId);
    } else {
      chrome.alarms.clear(alarm.name);
    }
  }
});

// Clean up when tab is closed
chrome.tabs.onRemoved.addListener((tabId) => {
  if (activeSessions.has(tabId)) {
    stopDubbing(tabId);
  }
});
