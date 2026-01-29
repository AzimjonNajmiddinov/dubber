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

  updateStatusUI(status) {
    const statusEl = document.getElementById('status');
    const button = document.getElementById('actionButton');

    if (status.active) {
      statusEl.textContent = this.formatStatus(status.status);
      statusEl.className = 'status-value status-active';
      button.textContent = 'Stop Dubbing';
      button.classList.add('stop');
    } else {
      statusEl.textContent = 'Inactive';
      statusEl.className = 'status-value status-inactive';
      button.textContent = 'Start Dubbing';
      button.classList.remove('stop');
    }
  }

  formatStatus(status) {
    const statusMap = {
      'initializing': 'Initializing...',
      'connected': 'Connected',
      'ready': 'Active',
      'processing': 'Processing...',
      'error': 'Error',
      'complete': 'Complete'
    };
    return statusMap[status] || status;
  }

  async toggleDubbing() {
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (!tab) return;

      if (this.isDubbing) {
        // Stop dubbing
        await chrome.tabs.sendMessage(tab.id, { action: 'stopDubbing' });
        this.isDubbing = false;
      } else {
        // Start dubbing via content script
        await chrome.tabs.sendMessage(tab.id, { action: 'startDubbingFromPopup' });
        this.isDubbing = true;
      }

      // Refresh status
      setTimeout(() => this.checkDubbingStatus(), 500);
    } catch (error) {
      console.error('Error toggling dubbing:', error);
      // Content script might not be loaded
      alert('Please navigate to a page with video content first.');
    }
  }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  new PopupController();
});
