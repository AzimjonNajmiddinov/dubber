/**
 * Dubber Browser Extension - Content Script
 *
 * Handles video detection, dubbed audio playback via Web Audio API,
 * subtitle display, video sync (play/pause/seek), and Mode 2 audio capture.
 */

class DubberController {
  constructor() {
    this.activeVideo = null;
    this.originalVolume = 1.0;
    this.isEnabled = false;
    this.sessionId = null;
    this.mode = null;

    // Audio playback
    this.audioContext = null;
    this.gainNode = null;
    this.chunkAudioBuffers = new Map(); // index → AudioBuffer
    this.scheduledSources = [];         // active AudioBufferSourceNodes
    this.chunkMeta = new Map();         // index → { start_time, end_time, ... }

    // Subtitles
    this.subtitles = [];                // { text, startTime, endTime, speaker }
    this.overlay = null;
    this.subtitleElement = null;

    // Capture mode
    this.mediaRecorder = null;
    this.captureChunkIndex = 0;
    this.captureTimer = null;

    // Memory management
    this.maxBufferedChunks = 30;

    this.init();
  }

  init() {
    chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
      this.handleMessage(message);
      sendResponse({ received: true });
    });

    this.observeVideos();

    document.addEventListener('keydown', (e) => {
      if (e.altKey && e.key === 'd') {
        this.toggleDubbing();
      }
    });

    console.log('Dubber content script loaded');
  }

  // ─── Video Detection ───────────────────────────────────────

  observeVideos() {
    document.querySelectorAll('video').forEach(v => this.attachToVideo(v));

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node.nodeName === 'VIDEO') {
            this.attachToVideo(node);
          } else if (node.querySelectorAll) {
            node.querySelectorAll('video').forEach(v => this.attachToVideo(v));
          }
        }
      }
    });

    observer.observe(document.body, { childList: true, subtree: true });
  }

  attachToVideo(video) {
    if (video.dataset.dubberAttached) return;
    video.dataset.dubberAttached = 'true';

    this.addDubButton(video);

    video.addEventListener('play', () => this.onVideoPlay(video));
    video.addEventListener('pause', () => this.onVideoPause(video));
    video.addEventListener('seeked', () => this.onVideoSeek(video));
    video.addEventListener('timeupdate', () => this.onVideoTimeUpdate(video));
  }

  addDubButton(video) {
    const container = video.parentElement;
    if (!container) return;

    const button = document.createElement('button');
    button.className = 'dubber-dub-button';
    button.innerHTML = `
      <svg viewBox="0 0 24 24" width="24" height="24">
        <path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
      </svg>
      <span>Dub</span>
    `;
    button.title = 'Start dubbing (Alt+D)';

    button.addEventListener('click', (e) => {
      e.stopPropagation();
      this.activeVideo = video;
      this.toggleDubbing();
    });

    const wrapper = document.createElement('div');
    wrapper.className = 'dubber-button-wrapper';
    wrapper.appendChild(button);

    container.style.position = 'relative';
    container.appendChild(wrapper);

    this.injectStyles();
  }

  injectStyles() {
    if (document.getElementById('dubber-styles')) return;

    const styles = document.createElement('style');
    styles.id = 'dubber-styles';
    styles.textContent = `
      .dubber-button-wrapper {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 999999;
        opacity: 0;
        transition: opacity 0.3s;
      }
      video:hover + .dubber-button-wrapper,
      .dubber-button-wrapper:hover {
        opacity: 1;
      }
      .dubber-dub-button {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 20px;
        cursor: pointer;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 14px;
        font-weight: 500;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        transition: transform 0.2s, box-shadow 0.2s;
      }
      .dubber-dub-button:hover {
        transform: scale(1.05);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
      }
      .dubber-dub-button.active {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      }
      .dubber-overlay {
        position: absolute;
        bottom: 60px;
        left: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 12px 16px;
        border-radius: 8px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 16px;
        z-index: 999998;
        pointer-events: none;
      }
      .dubber-subtitle {
        position: absolute;
        bottom: 10px;
        left: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        padding: 8px 12px;
        border-radius: 4px;
        font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        font-size: 14px;
        text-align: center;
        z-index: 999997;
        pointer-events: none;
      }
      .dubber-speaker {
        color: #667eea;
        font-weight: 600;
        margin-right: 8px;
      }
    `;
    document.head.appendChild(styles);
  }

  // ─── Dubbing Control ───────────────────────────────────────

  findMainVideo() {
    const videos = document.querySelectorAll('video');
    if (videos.length === 0) return null;
    return Array.from(videos).reduce((a, b) =>
      (a.offsetWidth * a.offsetHeight) > (b.offsetWidth * b.offsetHeight) ? a : b
    );
  }

  async toggleDubbing() {
    if (!this.activeVideo) {
      this.activeVideo = this.findMainVideo();
      if (!this.activeVideo) {
        this.showNotification('No video found on this page');
        return;
      }
    }

    if (this.isEnabled) {
      await this.stopDubbing();
    } else {
      await this.startDubbing();
    }
  }

  async startDubbing() {
    if (!this.activeVideo) return;

    this.isEnabled = true;
    this.originalVolume = this.activeVideo.volume;

    // Lower original video volume
    const settings = await this.getSettings();
    this.activeVideo.volume = settings.volume?.original ?? 0.2;

    // Update button
    const button = this.activeVideo.parentElement?.querySelector('.dubber-dub-button');
    if (button) {
      button.classList.add('active');
      button.querySelector('span').textContent = 'Stop';
    }

    this.showOverlay('Initializing dubbing...');

    // Create audio context
    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
    this.gainNode = this.audioContext.createGain();
    this.gainNode.gain.value = settings.volume?.dubbed ?? 1.0;
    this.gainNode.connect(this.audioContext.destination);

    // Request dubbing from background
    const response = await chrome.runtime.sendMessage({
      action: 'startDubbing',
      data: {
        videoUrl: window.location.href,
        videoDuration: this.activeVideo.duration,
        currentTime: this.activeVideo.currentTime,
      },
    });

    if (response.error) {
      this.showNotification(`Error: ${response.error}`);
      this.stopDubbing();
      return;
    }

    this.sessionId = response.sessionId;
    this.mode = response.mode;

    if (this.mode === 'server') {
      this.showOverlay('Downloading audio...');
    } else {
      this.showOverlay('Capture mode — recording audio...');
      this.startCapture();
    }
  }

  async stopDubbing() {
    this.isEnabled = false;

    // Restore volume
    if (this.activeVideo) {
      this.activeVideo.volume = this.originalVolume;
    }

    // Update button
    const button = this.activeVideo?.parentElement?.querySelector('.dubber-dub-button');
    if (button) {
      button.classList.remove('active');
      button.querySelector('span').textContent = 'Dub';
    }

    // Stop capture if active
    this.stopCapture();

    // Cancel all scheduled audio
    this.cancelAllScheduled();

    // Clear buffers
    this.chunkAudioBuffers.clear();
    this.chunkMeta.clear();
    this.subtitles = [];

    this.hideOverlay();
    this.hideSubtitle();

    // Notify background
    await chrome.runtime.sendMessage({ action: 'stopDubbing' });

    // Close audio context
    if (this.audioContext) {
      this.audioContext.close();
      this.audioContext = null;
      this.gainNode = null;
    }

    this.sessionId = null;
    this.mode = null;
  }

  // ─── Message Handling ──────────────────────────────────────

  handleMessage(message) {
    switch (message.action) {
      case 'dubbingStarted':
        this.sessionId = message.sessionId;
        this.mode = message.mode;
        break;

      case 'newChunkReady':
        this.onNewChunk(message.chunk);
        break;

      case 'dubbingComplete':
        this.showNotification('Dubbing complete');
        break;

      case 'dubbingError':
        this.showNotification(`Dubbing error: ${message.error}`);
        this.stopDubbing();
        break;

      case 'restoreAudio':
        if (this.activeVideo) {
          this.activeVideo.volume = this.originalVolume;
        }
        this.cancelAllScheduled();
        break;

      case 'startDubbingFromPopup':
        this.toggleDubbing();
        break;

      case 'stopDubbing':
        this.stopDubbing();
        break;

      case 'startCapture':
        if (message.sessionId) this.sessionId = message.sessionId;
        this.startCapture();
        break;
    }
  }

  // ─── Chunk Audio Playback ──────────────────────────────────

  async onNewChunk(chunk) {
    if (!this.audioContext || !this.activeVideo) return;

    const index = chunk.index;

    // Store metadata for subtitles
    this.chunkMeta.set(index, chunk);

    // Add subtitle entry
    if (chunk.has_speech && chunk.translated_text) {
      this.subtitles.push({
        text: chunk.translated_text,
        startTime: chunk.start_time,
        endTime: chunk.end_time,
        speaker: chunk.speaker,
      });
    }

    // Hide the "Downloading..." overlay on first chunk
    if (index === 0) {
      this.hideOverlay();
    }

    // Decode audio if present
    if (chunk.has_speech && chunk.audio_base64) {
      try {
        const binaryStr = atob(chunk.audio_base64);
        const bytes = new Uint8Array(binaryStr.length);
        for (let i = 0; i < binaryStr.length; i++) {
          bytes[i] = binaryStr.charCodeAt(i);
        }

        const audioBuffer = await this.audioContext.decodeAudioData(bytes.buffer.slice(0));
        this.chunkAudioBuffers.set(index, audioBuffer);

        // Schedule this chunk for playback
        this.scheduleChunkPlayback(index);

        // Memory management: evict old buffers
        this.evictOldBuffers(index);
      } catch (err) {
        console.error('Failed to decode chunk audio:', index, err);
      }
    }
  }

  scheduleChunkPlayback(index) {
    if (!this.audioContext || !this.activeVideo || this.activeVideo.paused) return;

    const buffer = this.chunkAudioBuffers.get(index);
    const meta = this.chunkMeta.get(index);
    if (!buffer || !meta) return;

    const videoTime = this.activeVideo.currentTime;
    const chunkStart = meta.start_time;
    const chunkEnd = meta.end_time;

    // Skip if chunk already passed
    if (chunkEnd <= videoTime) return;

    const source = this.audioContext.createBufferSource();
    source.buffer = buffer;
    source.connect(this.gainNode);

    if (chunkStart > videoTime) {
      // Schedule for future
      const delay = chunkStart - videoTime;
      const when = this.audioContext.currentTime + delay;
      source.start(when);
    } else {
      // Start immediately with offset into the buffer
      const offset = videoTime - chunkStart;
      if (offset < buffer.duration) {
        source.start(0, offset);
      } else {
        return; // Already past this chunk's audio
      }
    }

    source._chunkIndex = index;
    this.scheduledSources.push(source);

    source.onended = () => {
      this.scheduledSources = this.scheduledSources.filter(s => s !== source);
    };
  }

  rescheduleFromCurrentTime() {
    this.cancelAllScheduled();

    if (!this.audioContext || !this.activeVideo || this.activeVideo.paused) return;

    // Re-schedule all buffered chunks
    for (const index of this.chunkAudioBuffers.keys()) {
      this.scheduleChunkPlayback(index);
    }
  }

  cancelAllScheduled() {
    for (const source of this.scheduledSources) {
      try { source.stop(); } catch (e) { /* already stopped */ }
    }
    this.scheduledSources = [];
  }

  evictOldBuffers(currentIndex) {
    if (this.chunkAudioBuffers.size <= this.maxBufferedChunks) return;

    const keys = [...this.chunkAudioBuffers.keys()].sort((a, b) => a - b);
    while (this.chunkAudioBuffers.size > this.maxBufferedChunks) {
      const oldest = keys.shift();
      if (oldest !== undefined && oldest < currentIndex - 5) {
        this.chunkAudioBuffers.delete(oldest);
      } else {
        break;
      }
    }
  }

  // ─── Video Sync Events ────────────────────────────────────

  onVideoPlay(video) {
    if (!this.isEnabled || video !== this.activeVideo) return;

    if (this.audioContext?.state === 'suspended') {
      this.audioContext.resume();
    }

    this.rescheduleFromCurrentTime();
  }

  onVideoPause(video) {
    if (!this.isEnabled || video !== this.activeVideo) return;

    this.cancelAllScheduled();

    if (this.audioContext?.state === 'running') {
      this.audioContext.suspend();
    }
  }

  onVideoSeek(video) {
    if (!this.isEnabled || video !== this.activeVideo) return;
    this.rescheduleFromCurrentTime();
  }

  onVideoTimeUpdate(video) {
    if (!this.isEnabled || video !== this.activeVideo) return;
    this.updateSubtitleDisplay(video.currentTime);
  }

  // ─── Mode 2: Audio Capture ────────────────────────────────

  startCapture() {
    if (!this.activeVideo || !this.sessionId) return;

    const chunkDuration = 12000; // 12s in ms
    this.captureChunkIndex = 0;

    try {
      const stream = this.activeVideo.captureStream();
      const audioTracks = stream.getAudioTracks();

      if (audioTracks.length === 0) {
        this.showNotification('No audio track available for capture');
        return;
      }

      // Create audio-only stream
      const audioStream = new MediaStream(audioTracks);

      const startRecording = () => {
        if (!this.isEnabled) return;

        this.mediaRecorder = new MediaRecorder(audioStream, {
          mimeType: 'audio/webm;codecs=opus',
        });

        const chunks = [];

        this.mediaRecorder.ondataavailable = (e) => {
          if (e.data.size > 0) chunks.push(e.data);
        };

        this.mediaRecorder.onstop = async () => {
          if (chunks.length === 0) return;

          const blob = new Blob(chunks, { type: 'audio/webm' });
          const reader = new FileReader();

          reader.onloadend = () => {
            const base64 = reader.result.split(',')[1];
            const index = this.captureChunkIndex++;
            const timestamp = index * (chunkDuration / 1000);

            chrome.runtime.sendMessage({
              action: 'sendCaptureChunk',
              data: {
                audioBase64: base64,
                timestamp,
                duration: chunkDuration / 1000,
                index,
              },
            });
          };

          reader.readAsDataURL(blob);
        };

        this.mediaRecorder.start();

        this.captureTimer = setTimeout(() => {
          if (this.mediaRecorder?.state === 'recording') {
            this.mediaRecorder.stop();
          }
          startRecording();
        }, chunkDuration);
      };

      startRecording();

    } catch (err) {
      console.error('Capture failed:', err);
      this.showNotification('Audio capture not available (CORS restriction)');
    }
  }

  stopCapture() {
    if (this.captureTimer) {
      clearTimeout(this.captureTimer);
      this.captureTimer = null;
    }
    if (this.mediaRecorder?.state === 'recording') {
      try { this.mediaRecorder.stop(); } catch (e) { /* ignore */ }
    }
    this.mediaRecorder = null;
  }

  // ─── Subtitles ─────────────────────────────────────────────

  updateSubtitleDisplay(currentTime) {
    const active = this.subtitles.find(
      s => currentTime >= s.startTime && currentTime <= s.endTime
    );

    if (active) {
      this.showSubtitle(active.text, active.speaker);
    } else {
      this.hideSubtitle();
    }
  }

  showSubtitle(text, speaker) {
    if (!this.activeVideo) return;
    const container = this.activeVideo.parentElement;
    if (!container) return;

    if (!this.subtitleElement) {
      this.subtitleElement = document.createElement('div');
      this.subtitleElement.className = 'dubber-subtitle';
      container.appendChild(this.subtitleElement);
    }

    this.subtitleElement.innerHTML = `
      ${speaker ? `<span class="dubber-speaker">${speaker}:</span>` : ''}${text}
    `;
  }

  hideSubtitle() {
    if (this.subtitleElement) {
      this.subtitleElement.remove();
      this.subtitleElement = null;
    }
  }

  // ─── Overlay / Notifications ───────────────────────────────

  showOverlay(message) {
    if (!this.activeVideo) return;
    const container = this.activeVideo.parentElement;
    if (!container) return;

    if (!this.overlay) {
      this.overlay = document.createElement('div');
      this.overlay.className = 'dubber-overlay';
      container.appendChild(this.overlay);
    }
    this.overlay.textContent = message;
  }

  hideOverlay() {
    if (this.overlay) {
      this.overlay.remove();
      this.overlay = null;
    }
  }

  showNotification(message) {
    this.showOverlay(message);
    setTimeout(() => this.hideOverlay(), 3000);
  }

  async getSettings() {
    return await chrome.runtime.sendMessage({ action: 'getSettings' });
  }
}

// Initialize
const dubber = new DubberController();
