/**
 * Dubber Browser Extension - Background Service Worker
 *
 * Handles communication between content scripts and the Dubber backend
 * for real-time video dubbing.
 */

// Default settings
const DEFAULT_SETTINGS = {
  serverUrl: 'http://localhost:8080',
  targetLanguage: 'uz',
  ttsDriver: 'xtts',
  autoCloneVoices: true,
  enabled: true,
  volume: {
    original: 0.2,
    dubbed: 1.0
  }
};

// Active dubbing sessions
const activeSessions = new Map();

// Initialize settings on install
chrome.runtime.onInstalled.addListener(async () => {
  const stored = await chrome.storage.sync.get('settings');
  if (!stored.settings) {
    await chrome.storage.sync.set({ settings: DEFAULT_SETTINGS });
  }
  console.log('Dubber extension installed');
});

// Handle messages from content scripts and popup
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  handleMessage(message, sender).then(sendResponse);
  return true; // Keep channel open for async response
});

async function handleMessage(message, sender) {
  const { action, data } = message;

  switch (action) {
    case 'getSettings':
      return getSettings();

    case 'saveSettings':
      return saveSettings(data);

    case 'startDubbing':
      return startDubbing(sender.tab.id, data);

    case 'stopDubbing':
      return stopDubbing(sender.tab.id);

    case 'getDubbingStatus':
      return getDubbingStatus(sender.tab.id);

    case 'processAudioChunk':
      return processAudioChunk(sender.tab.id, data);

    case 'getAvailableVoices':
      return getAvailableVoices(data.language);

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

  // Create new dubbing session
  const session = {
    tabId,
    videoUrl: data.videoUrl,
    startTime: Date.now(),
    status: 'initializing',
    segments: [],
    currentPosition: 0,
    websocket: null
  };

  activeSessions.set(tabId, session);

  try {
    // Connect to dubbing server via WebSocket for real-time processing
    const wsUrl = settings.serverUrl.replace('http', 'ws') + '/ws/dub';
    session.websocket = new WebSocket(wsUrl);

    session.websocket.onopen = () => {
      session.status = 'connected';
      session.websocket.send(JSON.stringify({
        type: 'init',
        videoUrl: data.videoUrl,
        targetLanguage: settings.targetLanguage,
        ttsDriver: settings.ttsDriver,
        autoClone: settings.autoCloneVoices
      }));
    };

    session.websocket.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      handleServerMessage(tabId, msg);
    };

    session.websocket.onerror = (error) => {
      console.error('WebSocket error:', error);
      session.status = 'error';
    };

    session.websocket.onclose = () => {
      session.status = 'disconnected';
    };

    return { success: true, sessionId: tabId };

  } catch (error) {
    activeSessions.delete(tabId);
    return { error: error.message };
  }
}

async function stopDubbing(tabId) {
  const session = activeSessions.get(tabId);

  if (session) {
    if (session.websocket) {
      session.websocket.close();
    }
    activeSessions.delete(tabId);
  }

  // Notify content script to restore original audio
  chrome.tabs.sendMessage(tabId, {
    action: 'restoreAudio'
  });

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
    duration: Date.now() - session.startTime,
    segmentsProcessed: session.segments.length
  };
}

async function processAudioChunk(tabId, data) {
  const session = activeSessions.get(tabId);

  if (!session || !session.websocket || session.websocket.readyState !== WebSocket.OPEN) {
    return { error: 'No active dubbing session' };
  }

  // Send audio chunk to server for processing
  session.websocket.send(JSON.stringify({
    type: 'audio_chunk',
    timestamp: data.timestamp,
    duration: data.duration,
    audio: data.audioBase64 // Base64 encoded audio
  }));

  return { success: true };
}

async function getAvailableVoices(language) {
  const settings = await getSettings();

  try {
    const response = await fetch(`${settings.serverUrl}/api/voices?language=${language}`);
    if (!response.ok) {
      throw new Error('Failed to fetch voices');
    }
    return await response.json();
  } catch (error) {
    return { error: error.message };
  }
}

function handleServerMessage(tabId, message) {
  const session = activeSessions.get(tabId);
  if (!session) return;

  switch (message.type) {
    case 'ready':
      session.status = 'ready';
      chrome.tabs.sendMessage(tabId, { action: 'dubbingReady' });
      break;

    case 'segment':
      // New dubbed audio segment ready
      session.segments.push(message.segment);
      chrome.tabs.sendMessage(tabId, {
        action: 'playDubbedSegment',
        segment: message.segment
      });
      break;

    case 'transcription':
      // Live transcription update
      chrome.tabs.sendMessage(tabId, {
        action: 'showTranscription',
        text: message.text,
        speaker: message.speaker
      });
      break;

    case 'error':
      session.status = 'error';
      chrome.tabs.sendMessage(tabId, {
        action: 'dubbingError',
        error: message.error
      });
      break;

    case 'complete':
      session.status = 'complete';
      chrome.tabs.sendMessage(tabId, {
        action: 'dubbingComplete'
      });
      break;
  }
}

// Clean up when tab is closed
chrome.tabs.onRemoved.addListener((tabId) => {
  if (activeSessions.has(tabId)) {
    const session = activeSessions.get(tabId);
    if (session.websocket) {
      session.websocket.close();
    }
    activeSessions.delete(tabId);
  }
});
