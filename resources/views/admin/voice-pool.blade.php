@extends('admin.layout')
@section('title', 'Voice Pool')

@section('content')
<style>
    .hint { font-size:12px; color:#475569; margin-top:4px; }
    #loading { display:none; font-size:13px; color:#64748b; margin-top:8px; }
    .play-btn { background:#059669; color:#fff; border:none; padding:5px 12px; border-radius:6px; cursor:pointer; font-size:12px; white-space:nowrap; }
    .play-btn.playing { background:#dc2626; }
    .badge-male   { background:rgba(59,130,246,.15); color:#93c5fd; }
    .badge-female { background:rgba(236,72,153,.15); color:#f9a8d4; }
    .badge-child  { background:rgba(34,197,94,.15);  color:#86efac; }
    .tabs { display:flex; gap:0; margin-bottom:20px; border-bottom:1px solid #1e1e2e; }
    .tab { padding:10px 20px; cursor:pointer; font-size:14px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s,border-color .15s; }
    .tab.active { color:#a5b4fc; border-bottom-color:#6366f1; }
    .tab-panel { display:none; }
    .tab-panel.active { display:block; }
    .vp-row { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; }

    /* Inline param controls */
    .param-block { margin-bottom:6px; }
    .param-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; display:flex; justify-content:space-between; margin-bottom:2px; }
    .param-label span { color:#a5b4fc; font-weight:700; font-family:monospace; }
    input[type=range].slim { width:100%; height:4px; padding:0; cursor:pointer; accent-color:#6366f1; }
    .seed-input { width:80px; background:#1a1a2e; border:1px solid #2d2d44; color:#e2e8f0; border-radius:4px; padding:3px 6px; font-size:12px; }
    .save-row { display:flex; align-items:center; gap:6px; margin-top:8px; }
    .save-status { font-size:11px; color:#64748b; }
    .params-cell { min-width:220px; }
    .actions-cell { display:flex; gap:5px; align-items:center; flex-wrap:wrap; }
</style>

<div class="page-header">
    <h1>Voice Pool</h1>
</div>

<div class="card" style="margin-bottom:20px">
    <h3 style="font-size:.95rem;font-weight:700;color:#e2e8f0;margin-bottom:16px">Add voice</h3>

    <div class="tabs">
        <div class="tab active" onclick="switchTab('upload')">Upload file</div>
        <div class="tab" onclick="switchTab('youtube')">YouTube URL</div>
    </div>

    <div class="tab-panel active" id="panel-upload">
        <form method="POST" action="{{ route('admin.voice-pool.upload') }}" enctype="multipart/form-data" onsubmit="showLoading()">
            @csrf
            <div style="margin-bottom:12px">
                <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Audio file</label>
                <input type="file" name="audio" accept="audio/*,video/*,.wav,.mp3,.ogg,.flac,.m4a,.webm,.mp4,.mkv" required>
                <div class="hint">Any audio or video file — max 200 MB</div>
            </div>
            <div class="vp-row">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Voice name</label>
                    <input type="text" name="name" placeholder="actor1" pattern="[a-zA-Z0-9_-]+" value="{{ old('name') }}" required>
                    <div class="hint">Letters, numbers, - _ only</div>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Gender</label>
                    <select name="gender">
                        <option value="male"   {{ old('gender')=='male'   ?'selected':'' }}>Male</option>
                        <option value="female" {{ old('gender')=='female' ?'selected':'' }}>Female</option>
                        <option value="child"  {{ old('gender')=='child'  ?'selected':'' }}>Child</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Start (seconds)</label>
                    <input type="number" name="start" placeholder="0" min="0" value="{{ old('start', 0) }}">
                    <div class="hint">Skip intro/music</div>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Duration (seconds)</label>
                    <input type="number" name="duration" placeholder="25" min="5" max="60" value="{{ old('duration', 25) }}">
                    <div class="hint">5–60 sec, aim 20–30</div>
                </div>
            </div>
            <div style="margin-top:12px">
                <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Reference text <span style="font-weight:400;text-transform:none;color:#475569">(optional)</span></label>
                <textarea name="ref_text" rows="2" placeholder="Type exactly what is said in the audio clip...">{{ old('ref_text') }}</textarea>
                <div class="hint">Helps voice cloning. Leave blank to auto-detect.</div>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px">⬆ Upload &amp; Add</button>
            <div id="loading">⏳ Processing...</div>
        </form>
    </div>

    <div class="tab-panel" id="panel-youtube">
        <form method="POST" action="{{ route('admin.voice-pool.add') }}" onsubmit="showLoading()">
            @csrf
            <div style="margin-bottom:12px">
                <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">YouTube URL</label>
                <input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=..." value="{{ old('youtube_url') }}" required>
            </div>
            <div class="vp-row">
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Voice name</label>
                    <input type="text" name="name" placeholder="actor1" pattern="[a-zA-Z0-9_-]+" value="{{ old('name') }}" required>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Gender</label>
                    <select name="gender">
                        <option value="male"   {{ old('gender')=='male'   ?'selected':'' }}>Male</option>
                        <option value="female" {{ old('gender')=='female' ?'selected':'' }}>Female</option>
                        <option value="child"  {{ old('gender')=='child'  ?'selected':'' }}>Child</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Start (seconds)</label>
                    <input type="number" name="start" placeholder="0" min="0" value="{{ old('start', 0) }}">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Duration (seconds)</label>
                    <input type="number" name="duration" placeholder="25" min="5" max="60" value="{{ old('duration', 25) }}">
                </div>
            </div>
            <div style="margin-top:12px">
                <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Reference text <span style="font-weight:400;text-transform:none;color:#475569">(optional)</span></label>
                <textarea name="ref_text" rows="2" placeholder="Type exactly what is said in the audio clip...">{{ old('ref_text') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px">⬇ Download &amp; Add</button>
            <div id="loading">⏳ Downloading... 10–30 seconds</div>
        </form>
    </div>
</div>

<div class="card">
    <h3 style="font-size:.95rem;font-weight:700;color:#e2e8f0;margin-bottom:16px">Pool ({{ count($pool) }} voices)</h3>

    @if(empty($pool))
        <p style="color:#475569;font-size:14px">No voices yet. Add some above.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Gender</th>
                    <th>Dur / Size</th>
                    <th>Parameters</th>
                    <th>Ref text</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pool as $voice)
                <tr id="row-{{ $voice['gender'] }}-{{ $voice['name'] }}">
                    <td><b style="color:#e2e8f0">{{ $voice['name'] }}</b></td>
                    <td><span class="badge badge-{{ $voice['gender'] }}">{{ $voice['gender'] }}</span></td>
                    <td style="white-space:nowrap;font-size:12px;color:#94a3b8">
                        {{ $voice['duration'] }}<br>{{ $voice['size'] }}
                    </td>

                    {{-- ── Inline parameter controls ── --}}
                    <td class="params-cell">
                        {{-- Speed --}}
                        <div class="param-block">
                            <div class="param-label">Speed <span id="spd-val-{{ $voice['gender'] }}-{{ $voice['name'] }}">{{ number_format($voice['speed'],2) }}×</span></div>
                            <input type="range" class="slim"
                                id="spd-{{ $voice['gender'] }}-{{ $voice['name'] }}"
                                min="0.5" max="2.0" step="0.05"
                                value="{{ $voice['speed'] }}"
                                oninput="document.getElementById('spd-val-{{ $voice['gender'] }}-{{ $voice['name'] }}').textContent=parseFloat(this.value).toFixed(2)+'×'">
                        </div>
                        {{-- Tau --}}
                        <div class="param-block">
                            <div class="param-label">Tau (similarity) <span id="tau-val-{{ $voice['gender'] }}-{{ $voice['name'] }}">{{ number_format($voice['tau'],2) }}</span></div>
                            <input type="range" class="slim"
                                id="tau-{{ $voice['gender'] }}-{{ $voice['name'] }}"
                                min="0.0" max="1.0" step="0.05"
                                value="{{ $voice['tau'] }}"
                                oninput="document.getElementById('tau-val-{{ $voice['gender'] }}-{{ $voice['name'] }}').textContent=parseFloat(this.value).toFixed(2)">
                        </div>
                        {{-- Seed --}}
                        <div class="param-block" style="display:flex;align-items:center;gap:8px">
                            <div class="param-label" style="margin-bottom:0;min-width:36px">Seed</div>
                            <input type="number" class="seed-input"
                                id="seed-{{ $voice['gender'] }}-{{ $voice['name'] }}"
                                min="0" max="99999" placeholder="random"
                                value="{{ $voice['seed'] ?? '' }}">
                            <div class="hint" style="margin:0">blank = random</div>
                        </div>
                        {{-- Save --}}
                        <div class="save-row">
                            <button class="btn btn-secondary btn-sm" onclick="saveParams('{{ $voice['gender'] }}','{{ $voice['name'] }}',this)">💾 Save</button>
                            <span class="save-status" id="ss-{{ $voice['gender'] }}-{{ $voice['name'] }}"></span>
                        </div>
                    </td>

                    {{-- Ref text --}}
                    <td style="max-width:200px">
                        <div style="display:flex;gap:4px;align-items:flex-start">
                            <textarea id="ref-{{ $voice['gender'] }}-{{ $voice['name'] }}" rows="2" style="flex:1;font-size:11px;padding:4px 6px;resize:vertical" placeholder="Transcript…">{{ $voice['ref_text'] ?? '' }}</textarea>
                            <button type="button" onclick="saveRefText('{{ $voice['gender'] }}','{{ $voice['name'] }}')" class="btn btn-secondary btn-sm">💾</button>
                        </div>
                    </td>

                    {{-- Actions --}}
                    <td class="actions-cell">
                        <button type="button" class="play-btn" onclick="togglePlay(this,'{{ route('admin.voice-pool.play',[$voice['gender'],$voice['name']]) }}')">▶ Play</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="testVoice('{{ $voice['gender'] }}','{{ $voice['name'] }}')" title="Quick test synthesis">🔊 Test</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="reregisterVoice('{{ $voice['gender'] }}','{{ $voice['name'] }}',this)" title="Re-clone voice">↺</button>
                        <form method="POST" action="{{ route('admin.voice-pool.delete',[$voice['gender'],$voice['name']]) }}" style="display:inline" onsubmit="return confirm('Delete {{ $voice['name'] }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">✕</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- Quick test panel (shown when clicking 🔊 Test) --}}
<div class="card" id="test-card" style="display:none;position:sticky;bottom:16px;z-index:100;border:1px solid #6366f1">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <h3 style="font-size:.95rem;font-weight:700;color:#e2e8f0;margin:0">Test: <span id="test-title" style="color:#a5b4fc"></span></h3>
        <button onclick="document.getElementById('test-card').style.display='none'" style="background:none;border:none;color:#64748b;cursor:pointer;font-size:18px">✕</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Language</label>
            <select id="test-lang">
                <option value="uz">Uzbek</option>
                <option value="ru">Russian</option>
                <option value="en">English</option>
            </select>
        </div>
        <div style="display:flex;align-items:flex-end">
            <button class="btn btn-primary" onclick="runTest()" id="test-btn" style="width:100%">▶ Synthesize</button>
        </div>
    </div>

    <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Text</label>
        <textarea id="test-text" rows="2" placeholder="Salom, men o'zbek tilida gapiraman...">Salom, men o'zbek tilida gapiraman. Bu sinov matni.</textarea>
    </div>

    <div style="display:flex;align-items:center;gap:10px">
        <span id="test-status" style="font-size:13px;color:#64748b"></span>
    </div>
    <div id="test-player" style="margin-top:10px;display:none">
        <audio id="test-audio" controls style="width:100%"></audio>
    </div>
</div>

<script>
let currentAudio = null, currentBtn = null;
let testGender = null, testName = null;

function testVoice(gender, name) {
    testGender = gender; testName = name;
    document.getElementById('test-title').textContent = name + ' (' + gender + ')';
    document.getElementById('test-card').style.display = 'block';
    document.getElementById('test-player').style.display = 'none';
    document.getElementById('test-status').textContent = '';
    document.getElementById('test-card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function runTest() {
    if (!testGender || !testName) return;
    const text = document.getElementById('test-text').value.trim();
    const lang = document.getElementById('test-lang').value;
    if (!text) { alert('Enter text first.'); return; }

    const speed = parseFloat(document.getElementById('spd-' + testGender + '-' + testName)?.value ?? 1.0);
    const tau   = parseFloat(document.getElementById('tau-' + testGender + '-' + testName)?.value ?? 0.4);
    const seedEl = document.getElementById('seed-' + testGender + '-' + testName);
    const seed  = seedEl?.value !== '' ? parseInt(seedEl.value) : null;

    const btn = document.getElementById('test-btn'), status = document.getElementById('test-status');
    btn.disabled = true; status.textContent = '⏳ Synthesizing…';

    try {
        const resp = await fetch('{{ route('admin.voice-pool.test') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ gender: testGender, name: testName, text, language: lang, speed, tau, seed }),
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({ error: resp.statusText }));
            status.textContent = '❌ ' + (err.error || 'Error'); return;
        }
        const blob = await resp.blob();
        const audio = document.getElementById('test-audio');
        audio.src = URL.createObjectURL(blob);
        document.getElementById('test-player').style.display = 'block';
        audio.play();
        status.textContent = '✅ Done';
    } catch (e) {
        status.textContent = '❌ ' + e.message;
    } finally {
        btn.disabled = false;
    }
}

async function saveParams(gender, name, btn) {
    const speed = parseFloat(document.getElementById('spd-' + gender + '-' + name).value);
    const tau   = parseFloat(document.getElementById('tau-' + gender + '-' + name).value);
    const seedEl = document.getElementById('seed-' + gender + '-' + name);
    const seed   = seedEl.value !== '' ? parseInt(seedEl.value) : null;
    const status = document.getElementById('ss-' + gender + '-' + name);

    btn.disabled = true; status.textContent = '⏳';
    try {
        const resp = await fetch('/admin/voice-pool/' + gender + '/' + name + '/speed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ speed, tau, seed }),
        });
        status.textContent = resp.ok ? '✅ Saved' : '❌ Failed';
    } catch (e) {
        status.textContent = '❌ ' + e.message;
    } finally {
        btn.disabled = false;
        setTimeout(() => { status.textContent = ''; }, 3000);
    }
}

async function saveRefText(gender, name) {
    const ta = document.getElementById('ref-' + gender + '-' + name);
    const btn = ta.nextElementSibling;
    btn.textContent = '⏳'; btn.disabled = true;
    const res = await fetch('/admin/voice-pool/' + gender + '/' + name + '/ref-text', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        body: JSON.stringify({ ref_text: ta.value }),
    });
    btn.disabled = false; btn.textContent = res.ok ? '✅' : '❌';
    setTimeout(() => btn.textContent = '💾', 1500);
}

function togglePlay(btn, url) {
    if (currentAudio && currentBtn) {
        currentAudio.pause(); currentBtn.textContent = '▶ Play'; currentBtn.classList.remove('playing');
        if (currentBtn === btn) { currentAudio = null; currentBtn = null; return; }
    }
    const audio = new Audio(url); audio.play();
    btn.textContent = '⏹ Stop'; btn.classList.add('playing');
    currentAudio = audio; currentBtn = btn;
    audio.onended = () => { btn.textContent = '▶ Play'; btn.classList.remove('playing'); currentAudio = null; currentBtn = null; };
}

async function reregisterVoice(gender, name, btn) {
    btn.textContent = '⏳'; btn.disabled = true;
    const res = await fetch('/admin/voice-pool/' + gender + '/' + name + '/reregister', {
        method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
    });
    btn.disabled = false;
    btn.textContent = res.ok ? '✅' : '❌';
    setTimeout(() => { btn.textContent = '↺'; }, 2000);
}

function showLoading() { document.getElementById('loading').style.display = 'block'; }

function switchTab(name) {
    document.querySelectorAll('.tab').forEach((t, i) => t.classList.toggle('active', ['upload', 'youtube'][i] === name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + name));
}
</script>
@endsection
