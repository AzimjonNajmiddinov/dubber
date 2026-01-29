/**
 * Dubber Browser Extension - Content Script
 *
 * Injects into web pages to detect videos, capture audio,
 * and play dubbed audio in sync with the video.
 */

class DubberController {
  constructor() {
    this.activeVideo = null;
    this.originalVolume = 1.0;
    this.audioContext = null;
    this.sourceNode = null;
    this.dubbedAudioQueue = [];
    this.isEnabled = false;
    this.overlay = null;
    this.transcriptionElement = null;

    this.init();
  }

  init() {
    // Listen for messages from background script
    chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
      this.handleMessage(message);
      sendResponse({ received: true });
    });

    // Observe DOM for new videos
    this.observeVideos();

    // Add keyboard shortcut
    document.addEventListener('keydown', (e) => {
      // Alt+D to toggle dubbing
      if (e.altKey && e.key === 'd') {
        this.toggleDubbing();
      }
    });

    console.log('Dubber content script loaded');
  }

  observeVideos() {
    // Find existing videos
    document.querySelectorAll('video').forEach(video => {
      this.attachToVideo(video);
    });

    // Watch for new videos
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeName === 'VIDEO') {
            this.attachToVideo(node);
          } else if (node.querySelectorAll) {
            node.querySelectorAll('video').forEach(video => {
              this.attachToVideo(video);
            });
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  attachToVideo(video) {
    if (video.dataset.dubberAttached) return;
    video.dataset.dubberAttached = 'true';

    // Add dubbing button overlay
    this.addDubButton(video);

    // Track video events
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

    // Position relative to video
    const wrapper = document.createElement('div');
    wrapper.className = 'dubber-button-wrapper';
    wrapper.appendChild(button);

    container.style.position = 'relative';
    container.appendChild(wrapper);

    // Inject styles
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

      .dubber-transcription {
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

  async toggleDubbing() {
    if (!this.activeVideo) {
      // Find the main video on the page
      const videos = document.querySelectorAll('video');
      if (videos.length === 0) {
        this.showNotification('No video found on this page');
        return;
      }
      // Use the largest/main video
      this.activeVideo = Array.from(videos).reduce((a, b) =>
        (a.offsetWidth * a.offsetHeight) > (b.offsetWidth * b.offsetHeight) ? a : b
      );
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

    // Update button state
    const button = this.activeVideo.parentElement?.querySelector('.dubber-dub-button');
    if (button) {
      button.classList.add('active');
      button.querySelector('span').textContent = 'Stop';
    }

    // Show overlay
    this.showOverlay('Initializing dubbing...');

    // Create audio context for dubbed audio playback
    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();

    // Start dubbing session via background script
    const response = await chrome.runtime.sendMessage({
      action: 'startDubbing',
      data: {
        videoUrl: window.location.href,
        videoDuration: this.activeVideo.duration,
        currentTime: this.activeVideo.currentTime
      }
    });

    if (response.error) {
      this.showNotification(`Error: ${response.error}`);
      this.stopDubbing();
      return;
    }

    // Start capturing and sending audio
    this.startAudioCapture();
  }

  async stopDubbing() {
    this.isEnabled = false;

    // Restore original volume
    if (this.activeVideo) {
      this.activeVideo.volume = this.originalVolume;
    }

    // Update button state
    const button = this.activeVideo?.parentElement?.querySelector('.dubber-dub-button');
    if (button) {
      button.classList.remove('active');
      button.querySelector('span').textContent = 'Dub';
    }

    // Stop audio capture
    this.stopAudioCapture();

    // Hide overlay
    this.hideOverlay();
    this.hideTranscription();

    // Notify background script
    await chrome.runtime.sendMessage({ action: 'stopDubbing' });

    // Close audio context
    if (this.audioContext) {
      this.audioContext.close();
      this.audioContext = null;
    }
  }

  startAudioCapture() {
    // For now, we'll rely on server-side audio extraction
    // In a full implementation, we could use MediaRecorder to capture audio
    this.showOverlay('Processing video audio...');
  }

  stopAudioCapture() {
    // Stop any ongoing capture
  }

  handleMessage(message) {
    switch (message.action) {
      case 'dubbingReady':
        this.showOverlay('Dubbing active');
        setTimeout(() => this.hideOverlay(), 2000);
        break;

      case 'playDubbedSegment':
        this.playDubbedSegment(message.segment);
        break;

      case 'showTranscription':
        this.showTranscription(message.text, message.speaker);
        break;

      case 'dubbingError':
        this.showNotification(`Dubbing error: ${message.error}`);
        this.stopDubbing();
        break;

      case 'dubbingComplete':
        this.showNotification('Dubbing complete');
        break;

      case 'restoreAudio':
        if (this.activeVideo) {
          this.activeVideo.volume = this.originalVolume;
        }
        break;
    }
  }

  async playDubbedSegment(segment) {
    if (!this.audioContext || !this.activeVideo) return;

    try {
      // Decode the audio data
      const audioData = Uint8Array.from(atob(segment.audio), c => c.charCodeAt(0));
      const audioBuffer = await this.audioContext.decodeAudioData(audioData.buffer);

      // Schedule playback at the right time
      const source = this.audioContext.createBufferSource();
      source.buffer = audioBuffer;
      source.connect(this.audioContext.destination);

      // Calculate when to play based on video position
      const videoTime = this.activeVideo.currentTime;
      const segmentStart = segment.startTime;

      if (segmentStart > videoTime) {
        // Schedule for future
        const delay = segmentStart - videoTime;
        source.start(this.audioContext.currentTime + delay);
      } else if (segmentStart + segment.duration > videoTime) {
        // Start immediately with offset
        const offset = videoTime - segmentStart;
        source.start(0, offset);
      }
      // Otherwise segment already passed, skip it

    } catch (error) {
      console.error('Error playing dubbed segment:', error);
    }
  }

  showTranscription(text, speaker) {
    if (!this.activeVideo) return;

    const container = this.activeVideo.parentElement;
    if (!container) return;

    if (!this.transcriptionElement) {
      this.transcriptionElement = document.createElement('div');
      this.transcriptionElement.className = 'dubber-transcription';
      container.appendChild(this.transcriptionElement);
    }

    this.transcriptionElement.innerHTML = `
      ${speaker ? `<span class="dubber-speaker">${speaker}:</span>` : ''}
      ${text}
    `;
  }

  hideTranscription() {
    if (this.transcriptionElement) {
      this.transcriptionElement.remove();
      this.transcriptionElement = null;
    }
  }

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
    // Use overlay temporarily for notifications
    this.showOverlay(message);
    setTimeout(() => this.hideOverlay(), 3000);
  }

  async getSettings() {
    const response = await chrome.runtime.sendMessage({ action: 'getSettings' });
    return response;
  }

  onVideoPlay(video) {
    if (this.isEnabled && video === this.activeVideo) {
      // Resume audio context if suspended
      if (this.audioContext?.state === 'suspended') {
        this.audioContext.resume();
      }
    }
  }

  onVideoPause(video) {
    if (this.isEnabled && video === this.activeVideo) {
      // Pause dubbed audio playback
      // In a full implementation, we'd pause scheduled audio
    }
  }

  onVideoSeek(video) {
    if (this.isEnabled && video === this.activeVideo) {
      // Clear queued audio and request new segments from current position
      this.dubbedAudioQueue = [];
    }
  }

  onVideoTimeUpdate(video) {
    // Track video progress for syncing
  }
}

// Initialize
const dubber = new DubberController();
