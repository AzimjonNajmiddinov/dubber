@extends('admin.layout')
@section('title', 'Voice Pool')

@section('content')
<style>
    .vp-row { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; }
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
                    <th>Duration</th>
                    <th>Size</th>
                    <th>Speed</th>
                    <th>Ref text</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($pool as $voice)
                <tr>
                    <td><b style="color:#e2e8f0">{{ $voice['name'] }}</b></td>
                    <td><span class="badge badge-{{ $voice['gender'] }}">{{ $voice['gender'] }}</span></td>
                    <td>{{ $voice['duration'] }}</td>
                    <td>{{ $voice['size'] }}</td>
                    <td id="speed-label-{{ $voice['gender'] }}-{{ $voice['name'] }}" style="font-size:13px">{{ $voice['speed'] }}×</td>
                    <td style="max-width:220px">
                        <div style="display:flex;gap:4px;align-items:flex-start">
                            <textarea id="ref-{{ $voice['gender'] }}-{{ $voice['name'] }}" rows="2" style="flex:1;font-size:11px;padding:4px 6px;resize:vertical" placeholder="Type transcript…">{{ $voice['ref_text'] ?? '' }}</textarea>
                            <button type="button" onclick="saveRefText('{{ $voice['gender'] }}','{{ $voice['name'] }}')" class="btn btn-secondary btn-sm">💾</button>
                        </div>
                    </td>
                    <td style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <button type="button" class="play-btn" onclick="togglePlay(this,'{{ route('admin.voice-pool.play',[$voice['gender'],$voice['name']]) }}')">▶ Play</button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="reregisterVoice('{{ $voice['gender'] }}','{{ $voice['name'] }}',this)" title="Clear cached voice ID so next dub re-clones with new base_src_se">↺ Re-reg</button>
                        <form method="POST" action="{{ route('admin.voice-pool.delete',[$voice['gender'],$voice['name']]) }}" style="display:inline" onsubmit="return confirm('Delete {{ $voice['name'] }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card" id="test-card">
    <h3 style="font-size:.95rem;font-weight:700;color:#e2e8f0;margin-bottom:4px">Test synthesis</h3>
    <p style="font-size:12px;color:#475569;margin-bottom:16px">MMS + OpenVoice v2</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Voice</label>
            <select id="test-voice">
                @foreach($pool as $voice)
                <option value="{{ $voice['gender'] }}|{{ $voice['name'] }}">{{ $voice['name'] }} ({{ $voice['gender'] }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Language</label>
            <select id="test-lang">
                <option value="uz">Uzbek</option>
                <option value="ru">Russian</option>
                <option value="en">English</option>
            </select>
        </div>
    </div>

    <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Speed <span id="speed-display" style="font-weight:400;text-transform:none;color:#64748b">1.0×</span></label>
        <input type="range" id="test-speed" min="0.5" max="2.0" step="0.05" value="1.0" style="width:100%;padding:0" oninput="document.getElementById('speed-display').textContent=parseFloat(this.value).toFixed(2)+'×'">
    </div>
    <div style="margin-bottom:16px">
        <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Voice similarity (tau) <span id="tau-display" style="font-weight:400;text-transform:none;color:#64748b">0.9</span></label>
        <input type="range" id="test-tau" min="0.0" max="1.0" step="0.05" value="0.9" style="width:100%;padding:0" oninput="document.getElementById('tau-display').textContent=parseFloat(this.value).toFixed(2)">
        <div class="hint">Higher = more similar to reference. 0.0 = raw MMS TTS.</div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
        <button type="button" class="btn btn-secondary btn-sm" onclick="saveSpeed()">💾 Save speed &amp; tau</button>
        <span id="save-status" style="font-size:12px;color:#64748b"></span>
    </div>

    <div style="margin-bottom:12px">
        <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:6px">Text</label>
        <textarea id="test-text" rows="3" placeholder="Enter text to synthesize...">Salom, men o'zbek tilida gapiraman. Bu sinov matni.</textarea>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-primary" onclick="runTest()" id="test-btn">▶ Synthesize</button>
        <span id="test-status" style="font-size:13px;color:#64748b"></span>
    </div>
    <div id="test-player" style="margin-top:14px;display:none">
        <audio id="test-audio" controls style="width:100%"></audio>
    </div>
</div>

<script>
let currentAudio = null, currentBtn = null;

async function reregisterVoice(gender, name, btn) {
    btn.textContent = '⏳'; btn.disabled = true;
    const res = await fetch(`/admin/voice-pool/${gender}/${name}/reregister`, {
        method:'POST', headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
    });
    btn.disabled = false;
    if (res.ok) { btn.textContent = '✅ Done'; setTimeout(() => { btn.textContent = '↺ Re-reg'; }, 2000); }
    else { btn.textContent = '❌'; setTimeout(() => { btn.textContent = '↺ Re-reg'; }, 2000); }
}

async function saveRefText(gender, name) {
    const ta = document.getElementById('ref-'+gender+'-'+name);
    const btn = ta.nextElementSibling;
    btn.textContent = '⏳'; btn.disabled = true;
    const res = await fetch(`/admin/voice-pool/${gender}/${name}/ref-text`, {
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
        body: JSON.stringify({ref_text: ta.value}),
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
    audio.onended = () => { btn.textContent='▶ Play'; btn.classList.remove('playing'); currentAudio=null; currentBtn=null; };
}

const voiceSpeeds = { @foreach($pool as $v) '{{ $v['gender'] }}|{{ $v['name'] }}': {{ $v['speed'] }}, @endforeach };
const voiceTaus   = { @foreach($pool as $v) '{{ $v['gender'] }}|{{ $v['name'] }}': {{ $v['tau'] }}, @endforeach };

function prefillVoiceParams(voiceVal) {
    const speed = voiceSpeeds[voiceVal] ?? 1.0;
    const tau   = voiceTaus[voiceVal]   ?? 0.9;
    document.getElementById('test-speed').value = speed;
    document.getElementById('speed-display').textContent = speed.toFixed(2)+'×';
    document.getElementById('test-tau').value = tau;
    document.getElementById('tau-display').textContent = tau.toFixed(2);
    document.getElementById('save-status').textContent = '';
}
document.getElementById('test-voice').addEventListener('change', function() { prefillVoiceParams(this.value); });
(function(){ const sel=document.getElementById('test-voice'); if(sel&&sel.value) prefillVoiceParams(sel.value); })();

async function saveSpeed() {
    const voiceVal = document.getElementById('test-voice').value; if (!voiceVal) return;
    const [gender,name] = voiceVal.split('|');
    const speed = parseFloat(document.getElementById('test-speed').value);
    const tau   = parseFloat(document.getElementById('test-tau').value);
    const saveStatus = document.getElementById('save-status');
    saveStatus.textContent = '⏳ Saving…';
    try {
        const resp = await fetch(`/admin/voice-pool/${gender}/${name}/speed`, {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
            body: JSON.stringify({speed,tau}),
        });
        if (resp.ok) {
            voiceSpeeds[voiceVal]=speed; voiceTaus[voiceVal]=tau;
            const label=document.getElementById(`speed-label-${gender}-${name}`);
            if(label) label.textContent=speed.toFixed(2)+'×';
            saveStatus.textContent='✅ Saved';
        } else { saveStatus.textContent='❌ Failed'; }
    } catch(e) { saveStatus.textContent='❌ '+e.message; }
}

async function runTest() {
    const voiceVal = document.getElementById('test-voice').value;
    if (!voiceVal) { alert('No voices in pool.'); return; }
    const [gender,name] = voiceVal.split('|');
    const text = document.getElementById('test-text').value.trim();
    const lang = document.getElementById('test-lang').value;
    if (!text) { alert('Enter text first.'); return; }
    const btn=document.getElementById('test-btn'), status=document.getElementById('test-status');
    btn.disabled=true; status.textContent='⏳ Synthesizing…';
    try {
        const resp = await fetch('{{ route('admin.voice-pool.test') }}', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
            body: JSON.stringify({gender,name,text,language:lang,speed:parseFloat(document.getElementById('test-speed').value),tau:parseFloat(document.getElementById('test-tau').value)}),
        });
        if (!resp.ok) { const err=await resp.json().catch(()=>({error:resp.statusText})); status.textContent='❌ '+(err.error||'Error'); return; }
        const blob=await resp.blob(); const url=URL.createObjectURL(blob);
        const audio=document.getElementById('test-audio'); audio.src=url;
        document.getElementById('test-player').style.display='block'; audio.play(); status.textContent='✅ Done';
    } catch(e) { status.textContent='❌ '+e.message; }
    finally { btn.disabled=false; }
}

function showLoading() { document.getElementById('loading').style.display='block'; }

function switchTab(name) {
    document.querySelectorAll('.tab').forEach((t,i) => t.classList.toggle('active',['upload','youtube'][i]===name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active',p.id==='panel-'+name));
}
</script>
@endsection
