/**
 * Dubber Browser Extension - Popup Script
 */

class PopupController {
  constructor() {
    this.settings = null;
    this.isDubbing = false;
    this.init();
  }

  async init() {
    await this.loadSettings();
    this.setupEventListeners();
    await this.checkDubbingStatus();
  }

  async loadSettings() {
    const response = await chrome.runtime.sendMessage({ action: 'getSettings' });
    this.settings = response;
    this.applySettingsToUI();
  }

  applySettingsToUI() {
    if (!this.settings) return;

    document.getElementById('enableToggle').checked = this.settings.enabled;
    document.getElementById('targetLanguage').value = this.settings.targetLanguage;
    document.getElementById('ttsDriver').value = this.settings.ttsDriver;
    document.getElementById('autoClone').checked = this.settings.autoCloneVoices;

    const originalVol = Math.round((this.settings.volume?.original ?? 0.2) * 100);
    const dubbedVol = Math.round((this.settings.volume?.dubbed ?? 1.0) * 100);

    document.getElementById('originalVolume').value = originalVol;
    document.getElementById('originalVolumeValue').textContent = `${originalVol}%`;

    document.getElementById('dubbedVolume').value = dubbedVol;
    document.getElementById('dubbedVolumeValue').textContent = `${dubbedVol}%`;
  }

  setupEventListeners() {
    // Enable toggle
    document.getElementById('enableToggle').addEventListener('change', (e) => {
      this.updateSetting('enabled', e.target.checked);
    });

    // Target language
    document.getElementById('targetLanguage').addEventListener('change', (e) => {
      this.updateSetting('targetLanguage', e.target.value);
    });

    // TTS driver
    document.getElementById('ttsDriver').addEventListener('change', (e) => {
      this.updateSetting('ttsDriver', e.target.value);
    });

    // Auto clone
    document.getElementById('autoClone').addEventListener('change', (e) => {
      this.updateSetting('autoCloneVoices', e.target.checked);
    });

    // Original volume slider
    document.getElementById('originalVolume').addEventListener('input', (e) => {
      const value = parseInt(e.target.value);
      document.getElementById('originalVolumeValue').textContent = `${value}%`;
      this.updateVolume('original', value / 100);
    });

    // Dubbed volume slider
    document.getElementById('dubbedVolume').addEventListener('input', (e) => {
      const value = parseInt(e.target.value);
      document.getElementById('dubbedVolumeValue').textContent = `${value}%`;
      this.updateVolume('dubbed', value / 100);
    });

    // Action button
    document.getElementById('actionButton').addEventListener('click', () => {
      this.toggleDubbing();
    });
  }

  async updateSetting(key, value) {
    this.settings[key] = value;
    await chrome.runtime.sendMessage({
      action: 'saveSettings',
      data: this.settings
    });
  }

  async updateVolume(type, value) {
    if (!this.settings.volume) {
      this.settings.volume = {};
    }
    this.settings.volume[type] = value;
    await this.updateSetting('volume', this.settings.volume);
  }

  async checkDubbingStatus() {
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (!tab) return;

      const response = await chrome.runtime.sendMessage({
        action: 'getDubbingStatus',
        tabId: tab.id
      });

      this.isDubbing = response.active;
      this.updateStatusUI(response);
    } catch (error) {
      console.error('Error checking status:', error);
    }
  }

  startAutoRefresh() {
    this.refreshTimer = setInterval(() => this.checkDubbingStatus(), 2000);
  }

  stopAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  updateStatusUI(status) {
    const statusEl = document.getElementById('status');
    const button = document.getElementById('actionButton');

    if (status.active) {
      const chunks = status.chunksReady ?? 0;
      const total = status.totalChunks;
      const mode = status.mode ?? '';
      const modeLabel = mode === 'capture' ? 'capture' : 'server';

      let statusText = this.formatStatus(status.status);
      if (chunks > 0) {
        statusText += total ? ` (${chunks}/${total} chunks)` : ` (${chunks} chunks)`;
      }
      statusText += ` [${modeLabel}]`;

      statusEl.textContent = statusText;
      statusEl.className = 'status-value status-active';
      button.textContent = 'Stop Dubbing';
      button.classList.add('stop');

      this.startAutoRefresh();
    } else {
      statusEl.textContent = 'Inactive';
      statusEl.className = 'status-value status-inactive';
      button.textContent = 'Start Dubbing';
      button.classList.remove('stop');

      this.stopAutoRefresh();
    }
  }

  formatStatus(status) {
    const statusMap = {
      'initializing': 'Initializing...',
      'started': 'Starting...',
      'downloading': 'Downloading...',
      'processing': 'Processing...',
      'complete': 'Complete',
      'error': 'Error',
      'stopped': 'Stopped',
    };
    return statusMap[status] || status;
  }

  async toggleDubbing() {
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (!tab) return;

      if (this.isDubbing) {
        await chrome.tabs.sendMessage(tab.id, { action: 'stopDubbing' });
        this.isDubbing = false;
      } else {
        await chrome.tabs.sendMessage(tab.id, { action: 'startDubbingFromPopup' });
        this.isDubbing = true;
      }

      setTimeout(() => this.checkDubbingStatus(), 500);
    } catch (error) {
      console.error('Error toggling dubbing:', error);
      alert('Please navigate to a page with video content first.');
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  new PopupController();
});
