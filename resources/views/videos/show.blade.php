<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Video #{{ $video->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system; margin: 24px; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .muted { color: #666; font-size: 13px; }
        .btn { display: inline-block; padding: 8px 12px; border-radius: 8px; border: 1px solid #222; text-decoration: none; color: #111; background:#fff; cursor:pointer; }
        .btn-primary { background: #111; color: #fff; }
        .btn-disabled { opacity: .45; pointer-events: none; cursor: default; }
        .row { display:flex; gap: 18px; flex-wrap:wrap; align-items:flex-start; }
        .kv { min-width: 320px; }
        .status-label { font-weight: 600; margin-bottom: 6px; }
        .bar { width: 320px; height: 10px; background: #eee; border-radius: 999px; overflow: hidden; }
        .bar > div { height: 10px; background: #111; width: 0%; }
        table { width:100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e5e5; padding: 8px; vertical-align: top; }
        thead th { background:#f5f5f5; text-align:left; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono"; font-size: 12px; }
        .pill { display:inline-block; padding: 2px 8px; border-radius: 999px; background:#f3f3f3; border:1px solid #e5e5e5; font-size:12px; }
        .ok { color: #0a7a2f; font-weight: 700; }
        .pending { color:#888; font-weight: 700; }
        .err { color:#b10000; font-weight: 700; }
        .actions { display:flex; gap: 8px; flex-wrap:wrap; align-items:center; }
        audio { width: 220px; max-width: 100%; }
        .small { font-size: 12px; }
        .speaker-card { background: #fafafa; border: 1px solid #e5e5e5; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
        .speaker-card h4 { margin: 0 0 8px 0; font-size: 14px; }
        .speaker-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .speaker-row label { font-size: 12px; color: #666; display: block; margin-bottom: 2px; }
        .speaker-row select, .speaker-row input { padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        .speaker-row select { min-width: 180px; }
        .speaker-row input[type="text"] { width: 80px; }
        .gender-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .gender-male { background: #e3f2fd; color: #1565c0; }
        .gender-female { background: #fce4ec; color: #c2185b; }
        .gender-unknown { background: #f5f5f5; color: #666; }
        .emotion-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; background: #fff3e0; color: #e65100; }
        .btn-success { background: #0a7a2f; color: #fff; border-color: #0a7a2f; }
        .btn-warning { background: #f59e0b; color: #fff; border-color: #f59e0b; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .save-indicator { font-size: 11px; margin-left: 8px; }
        .speakers-section { margin-top: 26px; }
    </style>
</head>
<body>

<a class="btn" href="{{ route('videos.index') }}">← Back</a>

<div class="card">
    <h2 style="margin-top:0;">Video #{{ $video->id }}</h2>

    <div class="row">
        <div class="kv">
            <div class="muted">Target language: <span class="pill">{{ $video->target_language ?? '-' }}</span></div>
            <div class="muted">Status: <span class="pill status-label">{{ $video->status }}</span></div>
            <div class="muted">Original: <span class="mono">{{ $video->original_path }}</span></div>
            <div class="muted">Dubbed: <span class="mono dubbed-path">{{ $video->dubbed_path ?? '-' }}</span></div>
            <div class="muted">Lipsynced: <span class="mono lipsynced-path">{{ $video->lipsynced_path ?? '-' }}</span></div>

            <div style="margin-top:12px;">
                <div class="bar"><div class="bar-fill"></div></div>
                <div class="muted progress-text">0%</div>
            </div>

            <div style="margin-top:12px;" class="actions">
                <a class="btn btn-primary btn-download btn-disabled" href="#">Download dubbed</a>
                <a class="btn btn-primary btn-download-lipsynced btn-disabled" href="#">Download lipsynced</a>
                <button class="btn btn-refresh" type="button">Refresh segments</button>
            </div>

            <div class="muted small" style="margin-top:10px;">
                Tip: Lipsynced version has synchronized lip movements. Dubbed version has original video with translated audio.
            </div>
        </div>
    </div>

    {{-- ================= SPEAKERS ================= --}}
    <div class="speakers-section">
        <h3 style="margin:0 0 10px 0;">Speaker Voice Settings</h3>
        <div class="muted small" style="margin-bottom: 10px;">
            Configure TTS voice for each detected speaker, then click "Regenerate Dubbing" to apply changes.
        </div>

        <div id="speakersContainer">
            <div class="muted">Loading speakers…</div>
        </div>

        <div style="margin-top: 12px; display: flex; gap: 10px; align-items: center;">
            <button class="btn btn-success btn-regenerate" type="button" disabled>🔄 Regenerate Dubbing</button>
            <span class="muted small regenerate-status"></span>
        </div>
    </div>

    {{-- ================= SEGMENTS ================= --}}
    <div style="margin-top: 26px;">
        <h3 style="margin:0 0 10px 0;">Video Segments</h3>

        <div class="muted small" id="segmentsMeta">Loading…</div>

        <div style="margin-top:10px; overflow:auto;">
            <table>
                <thead>
                <tr>
                    <th style="width:120px;">Time</th>
                    <th style="width:170px;">Speaker</th>
                    <th>Original</th>
                    <th>Translated</th>
                    <th style="width:220px;">TTS Audio</th>
                    <th style="width:90px;">TTS</th>
                </tr>
                </thead>
                <tbody id="segmentsBody">
                <tr>
                    <td colspan="6" class="muted">Loading segments…</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const videoId = {{ (int) $video->id }};

    function escapeHtml(str) {
        return String(str ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function fmtTime(t) {
        const n = Number(t);
        if (!Number.isFinite(n)) return '—';
        return n.toFixed(2);
    }

    function setStatusUI(data) {
        const labelEl = document.querySelector('.status-label');
        const pctEl = document.querySelector('.progress-text');
        const fillEl = document.querySelector('.bar-fill');
        const dubbedPathEl = document.querySelector('.dubbed-path');
        const lipsyncedPathEl = document.querySelector('.lipsynced-path');

        labelEl.textContent = data.label || data.status || '—';

        const pct = Math.max(0, Math.min(100, Number(data.progress || 0)));
        fillEl.style.width = pct + '%';
        pctEl.textContent = pct + '%';

        if (typeof data.dubbed_path === 'string' && data.dubbed_path) {
            dubbedPathEl.textContent = data.dubbed_path;
        }

        if (typeof data.lipsynced_path === 'string' && data.lipsynced_path) {
            lipsyncedPathEl.textContent = data.lipsynced_path;
        }

        // Dubbed download button
        const btn = document.querySelector('.btn-download');
        if (data.can_download && data.download_url) {
            btn.classList.remove('btn-disabled');
            btn.href = data.download_url;
        } else {
            btn.classList.add('btn-disabled');
            btn.href = '#';
        }

        // Lipsynced download button
        const btnLipsync = document.querySelector('.btn-download-lipsynced');
        if (data.download_lipsynced_url && data.lipsynced_path) {
            btnLipsync.classList.remove('btn-disabled');
            btnLipsync.href = data.download_lipsynced_url;
        } else {
            btnLipsync.classList.add('btn-disabled');
            btnLipsync.href = '#';
        }

        // Enable regenerate button if video has been dubbed
        const regenerateBtn = document.querySelector('.btn-regenerate');
        const canRegenerate = ['tts_generated', 'mixed', 'dubbed_complete', 'lipsync_processing', 'lipsync_done', 'done'].includes(data.status);
        if (canRegenerate && speakers.length > 0) {
            regenerateBtn.disabled = false;
        }
    }

    async function pollStatus() {
        try {
            const res = await fetch(`/videos/${videoId}/status`, {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) return;
            const data = await res.json();
            setStatusUI(data);
        } catch (e) {
            // ignore
        }
    }

    function ttsCell(ttsUrl) {
        if (!ttsUrl) {
            return `<span class="muted">—</span>`;
        }
        return `
            <audio controls preload="none" src="${escapeHtml(ttsUrl)}"></audio>
            <div class="muted small mono">${escapeHtml(ttsUrl)}</div>
        `;
    }

    function ttsStatusIcon(hasTts) {
        if (hasTts) return `<span class="ok">✔</span>`;
        return `<span class="pending">…</span>`;
    }

    async function loadSegments() {
        const metaEl = document.getElementById('segmentsMeta');
        const body = document.getElementById('segmentsBody');

        metaEl.textContent = 'Loading…';
        body.innerHTML = `<tr><td colspan="6" class="muted">Loading segments…</td></tr>`;

        try {
            const res = await fetch(`/videos/${videoId}/segments`, {
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) {
                metaEl.innerHTML = `<span class="err">Failed to load segments</span> (HTTP ${res.status})`;
                body.innerHTML = `<tr><td colspan="6" class="muted">No data</td></tr>`;
                return;
            }

            const segments = await res.json();
            if (!Array.isArray(segments) || segments.length === 0) {
                metaEl.textContent = 'No segments yet.';
                body.innerHTML = `<tr><td colspan="6" class="muted">No segments yet</td></tr>`;
                return;
            }

            metaEl.textContent = `Loaded ${segments.length} segment(s).`;

            body.innerHTML = '';
            for (const s of segments) {
                const spkKey = s?.speaker?.key ?? '—';
                const spkVoice = s?.speaker?.voice ?? '—';

                const start = fmtTime(s.start);
                const end = fmtTime(s.end);

                // IMPORTANT:
                // Your API should return a full URL for playback, not just "audio/tts/..".
                // If you only return relative storage paths, convert them server-side to url().
                const ttsUrl = s.tts_audio_url || s.tts_url || s.tts_audio_path_url || s.tts_audio_path || null;

                body.innerHTML += `
                    <tr>
                        <td class="muted mono">${start} – ${end}</td>
                        <td>
                            <strong class="mono">${escapeHtml(spkKey)}</strong><br>
                            <span class="muted small">${escapeHtml(spkVoice)}</span>
                        </td>
                        <td>${escapeHtml(s.text || '')}</td>
                        <td>
                            ${s.translated_text
                    ? escapeHtml(s.translated_text)
                    : '<span class="muted">—</span>'}
                        </td>
                        <td>${ttsCell(ttsUrl)}</td>
                        <td style="text-align:center;">${ttsStatusIcon(!!s.tts_audio_path)}</td>
                    </tr>
                `;
            }

        } catch (e) {
            metaEl.innerHTML = `<span class="err">Failed to load segments</span>`;
            body.innerHTML = `<tr><td colspan="6" class="muted">No data</td></tr>`;
        }
    }

    document.querySelector('.btn-refresh').addEventListener('click', () => {
        loadSegments();
        loadSpeakers();
        pollStatus();
    });

    // ================= SPEAKERS =================
    let availableVoices = {};
    let speakers = [];

    async function loadVoices() {
        try {
            const res = await fetch('/api/voices', { headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                availableVoices = await res.json();
            }
        } catch (e) {
            console.error('Failed to load voices:', e);
        }
    }

    function buildVoiceOptions(currentVoice, speakerGender) {
        let options = '';

        // Flatten all voices for the dropdown
        for (const lang of Object.keys(availableVoices)) {
            const langVoices = availableVoices[lang];
            const langLabel = { 'uz': 'Uzbek', 'ru': 'Russian', 'en': 'English' }[lang] || lang.toUpperCase();

            for (const gender of ['male', 'female']) {
                const voices = langVoices[gender] || [];
                for (const v of voices) {
                    const selected = v.id === currentVoice ? 'selected' : '';
                    // Highlight recommended voice based on detected gender
                    const recommended = (lang === 'uz' && gender === speakerGender) ? ' ★' : '';
                    options += `<option value="${escapeHtml(v.id)}" ${selected}>${escapeHtml(v.name)}${recommended}</option>`;
                }
            }
        }

        return options;
    }

    function renderSpeakers() {
        const container = document.getElementById('speakersContainer');

        if (speakers.length === 0) {
            container.innerHTML = '<div class="muted">No speakers detected yet.</div>';
            return;
        }

        let html = '';
        for (const spk of speakers) {
            const genderClass = spk.gender === 'male' ? 'gender-male' : (spk.gender === 'female' ? 'gender-female' : 'gender-unknown');
            const genderLabel = spk.gender || 'unknown';
            const confidence = spk.gender_confidence ? `${(spk.gender_confidence * 100).toFixed(0)}%` : '';

            html += `
                <div class="speaker-card" data-speaker-id="${spk.id}">
                    <h4>
                        ${escapeHtml(spk.label || spk.external_key)}
                        <span class="gender-badge ${genderClass}">${genderLabel} ${confidence}</span>
                        ${spk.emotion && spk.emotion !== 'neutral' ? `<span class="emotion-badge">${escapeHtml(spk.emotion)}</span>` : ''}
                    </h4>
                    <div class="speaker-row">
                        <div>
                            <label>TTS Voice</label>
                            <select class="voice-select" data-field="tts_voice">
                                ${buildVoiceOptions(spk.tts_voice, spk.gender)}
                            </select>
                        </div>
                        <div>
                            <label>Rate</label>
                            <select class="rate-select" data-field="tts_rate">
                                <option value="-20%" ${spk.tts_rate === '-20%' ? 'selected' : ''}>-20% (Slow)</option>
                                <option value="-10%" ${spk.tts_rate === '-10%' ? 'selected' : ''}>-10%</option>
                                <option value="+0%" ${!spk.tts_rate || spk.tts_rate === '+0%' ? 'selected' : ''}>Normal</option>
                                <option value="+10%" ${spk.tts_rate === '+10%' ? 'selected' : ''}>+10%</option>
                                <option value="+20%" ${spk.tts_rate === '+20%' ? 'selected' : ''}>+20% (Fast)</option>
                                <option value="+30%" ${spk.tts_rate === '+30%' ? 'selected' : ''}>+30%</option>
                            </select>
                        </div>
                        <div>
                            <label>Pitch</label>
                            <select class="pitch-select" data-field="tts_pitch">
                                <option value="-30Hz" ${spk.tts_pitch === '-30Hz' ? 'selected' : ''}>-30Hz (Lower)</option>
                                <option value="-15Hz" ${spk.tts_pitch === '-15Hz' ? 'selected' : ''}>-15Hz</option>
                                <option value="+0Hz" ${!spk.tts_pitch || spk.tts_pitch === '+0Hz' ? 'selected' : ''}>Normal</option>
                                <option value="+15Hz" ${spk.tts_pitch === '+15Hz' ? 'selected' : ''}>+15Hz</option>
                                <option value="+30Hz" ${spk.tts_pitch === '+30Hz' ? 'selected' : ''}>+30Hz (Higher)</option>
                            </select>
                        </div>
                        <div>
                            <label>Emotion</label>
                            <select class="emotion-select" data-field="emotion">
                                <option value="neutral" ${!spk.emotion || spk.emotion === 'neutral' ? 'selected' : ''}>Neutral</option>
                                <option value="happy" ${spk.emotion === 'happy' ? 'selected' : ''}>Happy 😊</option>
                                <option value="excited" ${spk.emotion === 'excited' ? 'selected' : ''}>Excited 🎉</option>
                                <option value="sad" ${spk.emotion === 'sad' ? 'selected' : ''}>Sad 😢</option>
                                <option value="angry" ${spk.emotion === 'angry' ? 'selected' : ''}>Angry 😠</option>
                                <option value="fear" ${spk.emotion === 'fear' ? 'selected' : ''}>Fear 😨</option>
                                <option value="surprise" ${spk.emotion === 'surprise' ? 'selected' : ''}>Surprise 😮</option>
                            </select>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-save-speaker" type="button">Save</button>
                            <span class="save-indicator"></span>
                        </div>
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;

        // Add event listeners for save buttons
        container.querySelectorAll('.btn-save-speaker').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const card = e.target.closest('.speaker-card');
                const speakerId = card.dataset.speakerId;
                await saveSpeaker(card, speakerId);
            });
        });
    }

    async function saveSpeaker(card, speakerId) {
        const indicator = card.querySelector('.save-indicator');
        const btn = card.querySelector('.btn-save-speaker');

        const data = {
            tts_voice: card.querySelector('.voice-select').value,
            tts_rate: card.querySelector('.rate-select').value,
            tts_pitch: card.querySelector('.pitch-select').value,
            emotion: card.querySelector('.emotion-select').value,
        };

        indicator.textContent = 'Saving...';
        indicator.style.color = '#666';
        btn.disabled = true;

        try {
            const res = await fetch(`/videos/${videoId}/speakers/${speakerId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify(data),
            });

            if (res.ok) {
                indicator.textContent = '✓ Saved';
                indicator.style.color = '#0a7a2f';

                // Update local speaker data
                const spk = speakers.find(s => s.id == speakerId);
                if (spk) {
                    Object.assign(spk, data);
                }

                // Enable regenerate button
                document.querySelector('.btn-regenerate').disabled = false;
            } else {
                indicator.textContent = '✗ Failed';
                indicator.style.color = '#b10000';
            }
        } catch (e) {
            indicator.textContent = '✗ Error';
            indicator.style.color = '#b10000';
        }

        btn.disabled = false;
        setTimeout(() => { indicator.textContent = ''; }, 3000);
    }

    async function loadSpeakers() {
        try {
            const res = await fetch(`/videos/${videoId}/speakers`, { headers: { 'Accept': 'application/json' } });
            if (res.ok) {
                speakers = await res.json();
                renderSpeakers();

                // Enable regenerate button if we have speakers and video is dubbed
                if (speakers.length > 0) {
                    pollStatus().then(() => {
                        const status = document.querySelector('.status-label').textContent;
                        if (['tts_generated', 'mixed', 'dubbed_complete', 'lipsync_processing', 'lipsync_done', 'done'].some(s => status.toLowerCase().includes(s.replace('_', ' ')))) {
                            document.querySelector('.btn-regenerate').disabled = false;
                        }
                    });
                }
            }
        } catch (e) {
            console.error('Failed to load speakers:', e);
        }
    }

    async function regenerateDubbing() {
        const btn = document.querySelector('.btn-regenerate');
        const status = document.querySelector('.regenerate-status');

        btn.disabled = true;
        status.textContent = 'Starting regeneration...';

        try {
            const res = await fetch(`/videos/${videoId}/regenerate`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
            });

            const data = await res.json();

            if (res.ok && data.success) {
                status.textContent = '✓ Regeneration started! Refresh to see progress.';
                status.style.color = '#0a7a2f';
                // Start polling status more frequently
                pollStatus();
            } else {
                status.textContent = '✗ ' + (data.message || 'Failed to start regeneration');
                status.style.color = '#b10000';
                btn.disabled = false;
            }
        } catch (e) {
            status.textContent = '✗ Error starting regeneration';
            status.style.color = '#b10000';
            btn.disabled = false;
        }
    }

    document.querySelector('.btn-regenerate').addEventListener('click', regenerateDubbing);

    // Initial load
    loadVoices().then(() => {
        loadSpeakers();
    });
    pollStatus();
    loadSegments();

    // Poll status periodically (segments are refreshed manually to reduce load)
    setInterval(pollStatus, 2000);
</script>

</body>
</html>
