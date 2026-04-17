<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dubber</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;
            background: #0a0a0f;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* ── Layout ── */
        .layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .sidebar {
            background: #0f0f18;
            border-right: 1px solid rgba(255,255,255,0.06);
            display: flex;
            flex-direction: column;
            padding: 28px 20px;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 36px;
            padding: 0 4px;
        }
        .logo-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #6b21a8, #3b82f6);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .logo-name {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: #f1f5f9;
        }
        .nav-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 8px;
            padding: 0 8px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #94a3b8;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            text-decoration: none;
        }
        .nav-item:hover { background: rgba(255,255,255,0.05); color: #e2e8f0; }
        .nav-item.active { background: rgba(99,46,255,0.15); color: #a78bfa; }
        .nav-icon { font-size: 15px; width: 20px; text-align: center; flex-shrink: 0; }

        .sidebar-spacer { flex: 1; }
        .sidebar-footer {
            padding: 14px 10px 0;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-footer form { display: inline; }
        .logout-btn {
            background: none; border: none; cursor: pointer;
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0;
            font-size: 13px; color: #64748b;
            transition: color 0.15s;
            width: 100%;
        }
        .logout-btn:hover { color: #94a3b8; }

        /* ── Main ── */
        .main {
            display: flex;
            flex-direction: column;
            padding: 40px 48px;
            max-width: 820px;
        }
        .page-header {
            margin-bottom: 32px;
        }
        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -0.02em;
        }
        .page-sub {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }

        /* ── Card ── */
        .card {
            background: #111120;
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 20px;
        }

        /* ── Tabs ── */
        .tabs {
            display: flex;
            gap: 4px;
            background: rgba(255,255,255,0.04);
            border-radius: 9px;
            padding: 4px;
            margin-bottom: 24px;
            width: fit-content;
        }
        .tab {
            padding: 7px 18px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            user-select: none;
        }
        .tab.active {
            background: #1e1e35;
            color: #c4b5fd;
        }

        /* ── Form ── */
        .field { margin-bottom: 18px; }
        .field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 7px;
        }
        .field input[type=text],
        .field select {
            width: 100%;
            padding: 10px 14px;
            background: #0a0a14;
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 8px;
            font-size: 14px;
            color: #e2e8f0;
            outline: none;
            transition: border-color 0.15s;
            appearance: none;
        }
        .field input[type=text]::placeholder { color: #334155; }
        .field input[type=text]:focus,
        .field select:focus { border-color: rgba(139,92,246,0.5); }

        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .file-drop {
            border: 1.5px dashed rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 28px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }
        .file-drop:hover { border-color: rgba(139,92,246,0.4); background: rgba(139,92,246,0.03); }
        .file-drop input { display: none; }
        .file-drop-icon { font-size: 28px; margin-bottom: 8px; opacity: 0.4; }
        .file-drop-text { font-size: 13px; color: #64748b; }
        .file-drop-name { font-size: 13px; color: #a78bfa; margin-top: 6px; font-weight: 500; }

        .progress-bar-wrap { margin-top: 10px; display: none; }
        .progress-bar-track {
            height: 4px;
            background: rgba(255,255,255,0.07);
            border-radius: 2px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #6b21a8, #3b82f6);
            border-radius: 2px;
            transition: width 0.3s;
            width: 0%;
        }
        .progress-bar-label { font-size: 12px; color: #64748b; margin-top: 5px; }

        /* ── Button ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: opacity 0.15s, transform 0.1s;
        }
        .btn:active { transform: scale(0.98); }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
        .btn-primary {
            background: linear-gradient(135deg, #6b21a8, #3b82f6);
            color: #fff;
        }
        .btn-success {
            background: linear-gradient(135deg, #065f46, #059669);
            color: #fff;
            text-decoration: none;
        }

        /* ── Progress ── */
        .progress-card { display: none; }
        .dub-id-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .dub-id-label { font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
        .dub-id-val { font-size: 12px; color: #6b7280; font-family: monospace; }

        .progress-msg { font-size: 13px; color: #94a3b8; margin-bottom: 18px; min-height: 18px; }

        .steps { display: flex; flex-direction: column; gap: 6px; }
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
        }
        .step-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .step.pending { color: #334155; }
        .step.pending .step-dot { background: #1e293b; }
        .step.active  { background: rgba(59,130,246,0.08); color: #93c5fd; }
        .step.active  .step-dot { background: #3b82f6; box-shadow: 0 0 6px #3b82f6; }
        .step.done    { color: #6ee7b7; }
        .step.done    .step-dot { background: #10b981; }
        .step.error   { background: rgba(239,68,68,0.08); color: #fca5a5; }
        .step.error   .step-dot { background: #ef4444; }

        .actions { margin-top: 20px; }

        @media (max-width: 700px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { padding: 24px 20px; }
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo">
            <div class="logo-icon">🎬</div>
            <span class="logo-name">Dubber</span>
        </div>

        <div class="nav-label">Tools</div>
        <a class="nav-item active" href="{{ route('admin.premium-dub') }}">
            <span class="nav-icon">🎙</span> New Dub
        </a>
        <a class="nav-item" href="{{ route('admin.voice-pool.index') }}">
            <span class="nav-icon">🎤</span> Voice Pool
        </a>

        <div class="sidebar-spacer"></div>

        <div class="sidebar-footer">
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="logout-btn" type="submit">
                    <span>↩</span> Sign out
                </button>
            </form>
        </div>
    </aside>

    <!-- Main -->
    <main class="main">
        <div class="page-header">
            <h1 class="page-title">New Dub</h1>
            <p class="page-sub">Upload a video or paste a URL to start dubbing</p>
        </div>

        <div class="card">
            <div class="tabs">
                <div class="tab active" onclick="switchTab('url')">URL</div>
                <div class="tab" onclick="switchTab('file')">Upload file</div>
            </div>

            <!-- URL tab -->
            <div id="tab-url">
                <div class="field">
                    <label>Video URL</label>
                    <input id="video-url" type="text" placeholder="https://youtube.com/watch?v=...">
                </div>
            </div>

            <!-- File tab -->
            <div id="tab-file" style="display:none">
                <div class="field">
                    <label>Video file</label>
                    <div class="file-drop" onclick="document.getElementById('video-file').click()">
                        <input id="video-file" type="file" accept="video/*" onchange="onFileSelected(this)">
                        <div class="file-drop-icon">📁</div>
                        <div class="file-drop-text">Click to select a video file</div>
                        <div class="file-drop-name" id="file-name" style="display:none"></div>
                    </div>
                    <div class="progress-bar-wrap" id="upload-progress">
                        <div class="progress-bar-track">
                            <div class="progress-bar-fill" id="upload-fill"></div>
                        </div>
                        <div class="progress-bar-label" id="upload-label">Uploading...</div>
                    </div>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>Dub language</label>
                    <select id="language">
                        <option value="uz">Uzbek</option>
                        <option value="ru">Russian</option>
                        <option value="en">English</option>
                    </select>
                </div>
                <div class="field">
                    <label>Translate from</label>
                    <select id="translate-from">
                        <option value="auto">Auto detect</option>
                        <option value="ru">Russian</option>
                        <option value="en">English</option>
                        <option value="uz">Uzbek</option>
                    </select>
                </div>
            </div>

            <button id="start-btn" class="btn btn-primary" onclick="startDub()">
                <span>▶</span> Start Dubbing
            </button>
        </div>

        <!-- Progress -->
        <div class="card progress-card" id="progress-card">
            <div class="dub-id-row">
                <span class="dub-id-label">Job ID</span>
                <span class="dub-id-val" id="dub-id"></span>
            </div>
            <div class="progress-msg" id="progress-msg"></div>

            <div class="steps">
                <div class="step pending" id="s-downloading">
                    <div class="step-dot"></div> Downloading / uploading video
                </div>
                <div class="step pending" id="s-separating">
                    <div class="step-dot"></div> Separating vocals (Demucs)
                </div>
                <div class="step pending" id="s-transcribing">
                    <div class="step-dot"></div> Transcribing (WhisperX)
                </div>
                <div class="step pending" id="s-translating">
                    <div class="step-dot"></div> Translating
                </div>
                <div class="step pending" id="s-synthesizing">
                    <div class="step-dot"></div> Synthesizing voices (MMS + OpenVoice)
                </div>
                <div class="step pending" id="s-mixing">
                    <div class="step-dot"></div> Mixing &amp; encoding
                </div>
                <div class="step pending" id="s-complete">
                    <div class="step-dot"></div> Complete
                </div>
            </div>

            <div class="actions">
                <a id="download-btn" href="#" style="display:none" class="btn btn-success">
                    <span>⬇</span> Download MP4
                </a>
            </div>
        </div>
    </main>
</div>

<script>
    let pollInterval = null;
    let dubId = null;
    let activeTab = 'url';

    function switchTab(tab) {
        activeTab = tab;
        document.getElementById('tab-url').style.display  = tab === 'url'  ? '' : 'none';
        document.getElementById('tab-file').style.display = tab === 'file' ? '' : 'none';
        document.querySelectorAll('.tab').forEach((el, i) => {
            el.classList.toggle('active', (i === 0 && tab === 'url') || (i === 1 && tab === 'file'));
        });
    }

    function onFileSelected(input) {
        const name = input.files[0]?.name || '';
        const el = document.getElementById('file-name');
        el.textContent = name;
        el.style.display = name ? '' : 'none';
    }

    async function startDub() {
        document.getElementById('start-btn').disabled = true;
        document.getElementById('progress-card').style.display = '';
        if (activeTab === 'url') {
            await startFromUrl();
        } else {
            await startFromFile();
        }
    }

    async function startFromUrl() {
        const videoUrl = document.getElementById('video-url').value.trim();
        if (!videoUrl) {
            alert('Enter a video URL');
            document.getElementById('start-btn').disabled = false;
            return;
        }
        const resp = await fetch('{{ route('admin.api.premium-dub.start') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                video_url: videoUrl,
                language: document.getElementById('language').value,
                translate_from: document.getElementById('translate-from').value,
            }),
        });
        const data = await resp.json();
        if (!data.dub_id) { alert('Error: ' + JSON.stringify(data)); document.getElementById('start-btn').disabled = false; return; }
        onStarted(data.dub_id);
    }

    async function startFromFile() {
        const fileInput = document.getElementById('video-file');
        if (!fileInput.files.length) { alert('Select a video file'); document.getElementById('start-btn').disabled = false; return; }

        document.getElementById('upload-progress').style.display = 'block';
        setStep('s-downloading', 'active');

        const formData = new FormData();
        formData.append('video', fileInput.files[0]);
        formData.append('language', document.getElementById('language').value);
        formData.append('translate_from', document.getElementById('translate-from').value);
        formData.append('_token', document.querySelector('meta[name=csrf-token]').content);

        const xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const pct = Math.round(e.loaded / e.total * 100);
                document.getElementById('upload-fill').style.width = pct + '%';
                document.getElementById('upload-label').textContent = `Uploading… ${pct}%`;
            }
        });
        xhr.addEventListener('load', () => {
            document.getElementById('upload-progress').style.display = 'none';
            if (xhr.status !== 200) {
                alert('Upload failed: ' + xhr.responseText);
                document.getElementById('start-btn').disabled = false;
                setStep('s-downloading', 'error');
                return;
            }
            const data = JSON.parse(xhr.responseText);
            if (!data.dub_id) { alert('Error: ' + xhr.responseText); return; }
            setStep('s-downloading', 'done');
            onStarted(data.dub_id);
        });
        xhr.addEventListener('error', () => {
            alert('Upload error');
            document.getElementById('start-btn').disabled = false;
            setStep('s-downloading', 'error');
        });
        xhr.open('POST', '{{ route('admin.api.premium-dub.start-upload') }}');
        xhr.send(formData);
    }

    function onStarted(id) {
        dubId = id;
        document.getElementById('dub-id').textContent = dubId;
        pollInterval = setInterval(poll, 4000);
        poll();
    }

    const stepMap = {
        downloading:      's-downloading',
        extracting_audio: 's-downloading',
        separating_stems: 's-separating',
        transcribing:     's-transcribing',
        translating:      's-translating',
        cloning_voices:   's-synthesizing',
        synthesizing:     's-synthesizing',
        mixing:           's-mixing',
        muxing:           's-mixing',
        complete:         's-complete',
    };
    const allSteps = ['s-downloading','s-separating','s-transcribing','s-translating','s-synthesizing','s-mixing','s-complete'];

    function setStep(id, state) {
        document.getElementById(id).className = 'step ' + state;
    }

    async function poll() {
        const resp = await fetch(`/admin/api/premium-dub/${dubId}/status`, { headers: { 'Accept': 'application/json' } });
        const data = await resp.json();

        document.getElementById('progress-msg').textContent = data.progress || '';

        const activeStep = stepMap[data.status] || null;
        const activeIdx  = allSteps.indexOf(activeStep);

        allSteps.forEach((id, i) => {
            if (activeTab === 'file' && id === 's-downloading' && data.status !== 'downloading' && data.status !== 'extracting_audio') return;
            if (data.status === 'error' && id === activeStep) {
                setStep(id, 'error');
            } else if (i < activeIdx) {
                setStep(id, 'done');
            } else if (i === activeIdx) {
                setStep(id, 'active');
            } else {
                setStep(id, 'pending');
            }
        });

        if (data.status === 'complete') {
            clearInterval(pollInterval);
            setStep('s-complete', 'done');
            const dl = document.getElementById('download-btn');
            dl.href = `/admin/api/premium-dub/${dubId}/download`;
            dl.style.display = 'inline-flex';
        } else if (data.status === 'error') {
            clearInterval(pollInterval);
            document.getElementById('start-btn').disabled = false;
        }
    }
</script>
</body>
</html>
