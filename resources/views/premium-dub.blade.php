@extends('admin.layout')
@section('title', 'New Dub')

@section('content')
<style>
    .tabs { display:flex; gap:4px; background:rgba(255,255,255,.04); border-radius:9px; padding:4px; margin-bottom:24px; width:fit-content; }
    .tab { padding:7px 18px; border-radius:7px; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; transition:background .15s,color .15s; user-select:none; }
    .tab.active { background:#1e1e2e; color:#a5b4fc; }
    .field { margin-bottom:18px; }
    .field label { display:block; font-size:12px; font-weight:600; color:#94a3b8; letter-spacing:.04em; text-transform:uppercase; margin-bottom:7px; }
    .field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .file-drop { border:1.5px dashed rgba(255,255,255,.1); border-radius:10px; padding:28px; text-align:center; cursor:pointer; transition:border-color .15s,background .15s; }
    .file-drop:hover { border-color:rgba(139,92,246,.4); background:rgba(139,92,246,.03); }
    .file-drop-icon { font-size:28px; margin-bottom:8px; opacity:.4; }
    .file-drop-text { font-size:13px; color:#64748b; }
    .file-drop-name { font-size:13px; color:#a5b4fc; margin-top:6px; font-weight:500; }
    .progress-bar-wrap { margin-top:10px; display:none; }
    .progress-bar-track { height:4px; background:rgba(255,255,255,.07); border-radius:2px; overflow:hidden; }
    .progress-bar-fill { height:100%; background:linear-gradient(90deg,#6b21a8,#3b82f6); border-radius:2px; transition:width .3s; width:0%; }
    .progress-bar-label { font-size:12px; color:#64748b; margin-top:5px; }
    .btn-success { background:linear-gradient(135deg,#065f46,#059669); color:#fff; text-decoration:none; }
    .dub-id-row { display:flex; align-items:center; gap:10px; margin-bottom:16px; }
    .dub-id-label { font-size:12px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
    .dub-id-val { font-size:12px; color:#6b7280; font-family:monospace; }
    .progress-msg { font-size:13px; color:#94a3b8; margin-bottom:16px; min-height:18px; }
    .steps { display:flex; flex-direction:column; gap:6px; }
    .step { display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:9px; font-size:13px; font-weight:500; transition:background .2s,color .2s; }
    .step-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; transition:background .2s; }
    .step.pending { color:#334155; }
    .step.pending .step-dot { background:#1e293b; }
    .step.active { background:rgba(59,130,246,.08); color:#93c5fd; }
    .step.active .step-dot { background:#3b82f6; box-shadow:0 0 6px #3b82f6; }
    .step.done { color:#6ee7b7; }
    .step.done .step-dot { background:#10b981; }
    .step.error { background:rgba(239,68,68,.08); color:#fca5a5; }
    .step.error .step-dot { background:#ef4444; }
</style>

<div class="page-header">
    <h1>New Dub</h1>
    <span style="font-size:.8rem;color:#475569">Upload a video or paste a URL to start dubbing</span>
</div>

<div class="card" style="max-width:680px">
    <div class="tabs">
        <div class="tab active" onclick="switchTab('url')">URL</div>
        <div class="tab" onclick="switchTab('file')">Upload file</div>
    </div>

    <div id="tab-url">
        <div class="field">
            <label>Video URL</label>
            <input id="video-url" type="text" placeholder="https://youtube.com/watch?v=...">
        </div>
    </div>

    <div id="tab-file" style="display:none">
        <div class="field">
            <label>Video file</label>
            <div class="file-drop" onclick="document.getElementById('video-file').click()">
                <input id="video-file" type="file" accept="video/*" style="display:none" onchange="onFileSelected(this)">
                <div class="file-drop-icon">📁</div>
                <div class="file-drop-text">Click to select a video file</div>
                <div class="file-drop-name" id="file-name" style="display:none"></div>
            </div>
            <div class="progress-bar-wrap" id="upload-progress">
                <div class="progress-bar-track"><div class="progress-bar-fill" id="upload-fill"></div></div>
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

    <button id="start-btn" class="btn btn-primary" onclick="startDub()">▶ Start Dubbing</button>
</div>

<div class="card" id="progress-card" style="display:none;max-width:680px">
    <div class="dub-id-row">
        <span class="dub-id-label">Job ID</span>
        <span class="dub-id-val" id="dub-id"></span>
    </div>
    <div class="progress-msg" id="progress-msg"></div>
    <div class="steps">
        <div class="step pending" id="s-downloading"><div class="step-dot"></div> Downloading / uploading video</div>
        <div class="step pending" id="s-separating"><div class="step-dot"></div> Separating vocals (Demucs)</div>
        <div class="step pending" id="s-transcribing"><div class="step-dot"></div> Transcribing (WhisperX)</div>
        <div class="step pending" id="s-translating"><div class="step-dot"></div> Translating</div>
        <div class="step pending" id="s-synthesizing"><div class="step-dot"></div> Synthesizing voices (MMS + OpenVoice)</div>
        <div class="step pending" id="s-mixing"><div class="step-dot"></div> Mixing &amp; encoding</div>
        <div class="step pending" id="s-complete"><div class="step-dot"></div> Complete</div>
    </div>
    <div style="margin-top:20px">
        <a id="download-btn" href="#" style="display:none" class="btn btn-success">⬇ Download MP4</a>
    </div>
</div>

<script>
let pollInterval = null, dubId = null, activeTab = 'url';

function switchTab(tab) {
    activeTab = tab;
    document.getElementById('tab-url').style.display  = tab==='url'  ? '' : 'none';
    document.getElementById('tab-file').style.display = tab==='file' ? '' : 'none';
    document.querySelectorAll('.tab').forEach((el,i) =>
        el.classList.toggle('active', (i===0&&tab==='url')||(i===1&&tab==='file')));
}
function onFileSelected(input) {
    const name = input.files[0]?.name||'';
    const el = document.getElementById('file-name');
    el.textContent = name; el.style.display = name?'':'none';
}
async function startDub() {
    document.getElementById('start-btn').disabled = true;
    document.getElementById('progress-card').style.display = '';
    activeTab==='url' ? await startFromUrl() : await startFromFile();
}
async function startFromUrl() {
    const videoUrl = document.getElementById('video-url').value.trim();
    if (!videoUrl) { alert('Enter a video URL'); document.getElementById('start-btn').disabled=false; return; }
    const resp = await fetch('{{ route('admin.api.premium-dub.start') }}', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},
        body: JSON.stringify({video_url:videoUrl,language:document.getElementById('language').value,translate_from:document.getElementById('translate-from').value}),
    });
    const data = await resp.json();
    if (!data.dub_id) { alert('Error: '+JSON.stringify(data)); document.getElementById('start-btn').disabled=false; return; }
    onStarted(data.dub_id);
}
async function startFromFile() {
    const fileInput = document.getElementById('video-file');
    if (!fileInput.files.length) { alert('Select a video file'); document.getElementById('start-btn').disabled=false; return; }
    document.getElementById('upload-progress').style.display = 'block';
    setStep('s-downloading','active');
    const formData = new FormData();
    formData.append('video', fileInput.files[0]);
    formData.append('language', document.getElementById('language').value);
    formData.append('translate_from', document.getElementById('translate-from').value);
    formData.append('_token', document.querySelector('meta[name=csrf-token]').content);
    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable) {
            const pct = Math.round(e.loaded/e.total*100);
            document.getElementById('upload-fill').style.width = pct+'%';
            document.getElementById('upload-label').textContent = `Uploading… ${pct}%`;
        }
    });
    xhr.addEventListener('load', () => {
        document.getElementById('upload-progress').style.display = 'none';
        if (xhr.status!==200) { alert('Upload failed: '+xhr.responseText); document.getElementById('start-btn').disabled=false; setStep('s-downloading','error'); return; }
        const data = JSON.parse(xhr.responseText);
        if (!data.dub_id) { alert('Error: '+xhr.responseText); return; }
        setStep('s-downloading','done'); onStarted(data.dub_id);
    });
    xhr.addEventListener('error', () => { alert('Upload error'); document.getElementById('start-btn').disabled=false; setStep('s-downloading','error'); });
    xhr.open('POST', '{{ route('admin.api.premium-dub.start-upload') }}');
    xhr.send(formData);
}
function onStarted(id) {
    dubId = id;
    document.getElementById('dub-id').textContent = dubId;
    pollInterval = setInterval(poll, 4000); poll();
}
const stepMap = {downloading:'s-downloading',extracting_audio:'s-downloading',separating_stems:'s-separating',transcribing:'s-transcribing',translating:'s-translating',cloning_voices:'s-synthesizing',synthesizing:'s-synthesizing',mixing:'s-mixing',muxing:'s-mixing',complete:'s-complete'};
const allSteps = ['s-downloading','s-separating','s-transcribing','s-translating','s-synthesizing','s-mixing','s-complete'];
function setStep(id,state) { document.getElementById(id).className='step '+state; }
async function poll() {
    const resp = await fetch(`/admin/api/premium-dub/${dubId}/status`,{headers:{'Accept':'application/json'}});
    const data = await resp.json();
    document.getElementById('progress-msg').textContent = data.progress||'';
    const activeStep = stepMap[data.status]||null;
    const activeIdx  = allSteps.indexOf(activeStep);
    allSteps.forEach((id,i) => {
        if (activeTab==='file'&&id==='s-downloading'&&data.status!=='downloading'&&data.status!=='extracting_audio') return;
        if (data.status==='error'&&id===activeStep) setStep(id,'error');
        else if (i<activeIdx)  setStep(id,'done');
        else if (i===activeIdx) setStep(id,'active');
        else setStep(id,'pending');
    });
    if (data.status==='complete') {
        clearInterval(pollInterval); setStep('s-complete','done');
        const dl=document.getElementById('download-btn');
        dl.href=`/admin/api/premium-dub/${dubId}/download`; dl.style.display='inline-flex';
    } else if (data.status==='error') {
        clearInterval(pollInterval); document.getElementById('start-btn').disabled=false;
    }
}
</script>
@endsection
