<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium Dub</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .card { background: white; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        input[type=text], input[type=file], select { width: 100%; padding: 10px; margin: 8px 0 16px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        input[type=file] { background: #f9fafb; cursor: pointer; }
        button { background: #2563eb; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 15px; }
        button:disabled { background: #93c5fd; cursor: not-allowed; }
        label { font-weight: 600; font-size: 14px; color: #374151; display: block; margin-top: 4px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .tab { padding: 8px 18px; border-radius: 6px; border: 1px solid #ddd; background: #f3f4f6; cursor: pointer; font-size: 14px; font-weight: 600; color: #6b7280; }
        .tab.active { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
        #upload-progress { display: none; margin: 8px 0 16px; }
        progress { width: 100%; height: 8px; border-radius: 4px; }
        #upload-label { font-size: 13px; color: #6b7280; margin-top: 4px; }
        #status { margin-top: 20px; }
        .step { padding: 10px 16px; margin: 6px 0; border-radius: 6px; font-size: 14px; }
        .step.pending { background: #f3f4f6; color: #6b7280; }
        .step.active  { background: #dbeafe; color: #1d4ed8; }
        .step.done    { background: #d1fae5; color: #065f46; }
        .step.error   { background: #fee2e2; color: #991b1b; }
        #progress-text { color: #6b7280; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <h1>🎬 Premium Dub</h1>

    <div class="card">
        <div class="tabs">
            <div class="tab active" onclick="switchTab('url')">🔗 URL</div>
            <div class="tab" onclick="switchTab('file')">📁 Upload file</div>
        </div>

        <div id="tab-url">
            <label>Video URL (YouTube or direct link)</label>
            <input id="video-url" type="text" placeholder="https://youtube.com/watch?v=...">
        </div>

        <div id="tab-file" style="display:none">
            <label>Video file</label>
            <input id="video-file" type="file" accept="video/*">
            <div id="upload-progress">
                <progress id="upload-bar" value="0" max="100"></progress>
                <div id="upload-label">Uploading...</div>
            </div>
        </div>

        <label>Dub language</label>
        <select id="language">
            <option value="uz">Uzbek (uz)</option>
            <option value="ru">Russian (ru)</option>
            <option value="en">English (en)</option>
        </select>

        <label>Translate from (auto = detect)</label>
        <select id="translate-from">
            <option value="auto">Auto detect</option>
            <option value="ru">Russian</option>
            <option value="en">English</option>
            <option value="uz">Uzbek</option>
        </select>

        <button id="start-btn" onclick="startDub()">Start Dubbing</button>
    </div>

    <div id="status" style="display:none">
        <div class="card">
            <b>Dub ID:</b> <span id="dub-id" style="font-family:monospace;font-size:13px"></span>
            <div id="progress-text"></div>

            <div style="margin-top:16px">
                <div class="step" id="s-downloading">⬇️ Downloading / uploading video</div>
                <div class="step" id="s-separating">🎵 Separating stems (Demucs)</div>
                <div class="step" id="s-transcribing">📝 Transcribing (WhisperX)</div>
                <div class="step" id="s-translating">🌐 Translating</div>
                <div class="step" id="s-synthesizing">🗣️ Synthesizing voices (MMS TTS)</div>
                <div class="step" id="s-mixing">🎬 Mixing audio</div>
                <div class="step" id="s-complete">✅ Complete</div>
            </div>

            <a id="download-btn" href="#" style="display:none">
                <button style="background:#16a34a;border:none;cursor:pointer;margin-top:12px">⬇️ Download MP4</button>
            </a>
        </div>
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

        async function startDub() {
            document.getElementById('start-btn').disabled = true;
            document.getElementById('status').style.display = 'block';

            if (activeTab === 'url') {
                await startFromUrl();
            } else {
                await startFromFile();
            }
        }

        async function startFromUrl() {
            const videoUrl = document.getElementById('video-url').value.trim();
            if (!videoUrl) { alert('Enter video URL'); document.getElementById('start-btn').disabled = false; return; }

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
                    document.getElementById('upload-bar').value = pct;
                    document.getElementById('upload-label').textContent = `Uploading... ${pct}%`;
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

            document.getElementById('progress-text').textContent = data.progress || '';

            const activeStep = stepMap[data.status] || null;
            const activeIdx = allSteps.indexOf(activeStep);

            allSteps.forEach((id, i) => {
                const el = document.getElementById(id);
                if (activeTab === 'file' && id === 's-downloading') return; // already set after upload
                el.className = 'step';
                if (data.status === 'error' && id === activeStep) {
                    el.className = 'step error';
                } else if (i < activeIdx) {
                    el.className = 'step done';
                } else if (i === activeIdx) {
                    el.className = 'step active';
                } else {
                    el.className = 'step pending';
                }
            });

            if (data.status === 'complete') {
                clearInterval(pollInterval);
                setStep('s-complete', 'done');
                document.getElementById('download-btn').href = `/admin/api/premium-dub/${dubId}/download`;
                document.getElementById('download-btn').style.display = 'block';
            } else if (data.status === 'error') {
                clearInterval(pollInterval);
                document.getElementById('start-btn').disabled = false;
            }
        }
    </script>
</body>
</html>
