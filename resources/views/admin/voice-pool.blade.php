<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Pool — Admin</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        h1 { color: #333; }
        .card { background: white; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        label { display: block; font-weight: 600; font-size: 13px; color: #374151; margin-bottom: 4px; margin-top: 12px; }
        input, select { width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        .row { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        button.primary { background: #2563eb; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-size: 14px; margin-top: 16px; }
        button.primary:hover { background: #1d4ed8; }
        button.danger { background: #dc2626; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d1fae5; color: #065f46; }
        .alert.error { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; text-transform: uppercase; color: #6b7280; }
        td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; }
        .badge.male { background: #dbeafe; color: #1d4ed8; }
        .badge.female { background: #fce7f3; color: #9d174d; }
        .badge.child { background: #dcfce7; color: #166534; }
        .hint { font-size: 12px; color: #9ca3af; margin-top: 4px; }
        #loading { display: none; color: #6b7280; font-size: 13px; margin-top: 8px; }
        .play-btn { background: #059669; color: white; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; white-space: nowrap; }
        .play-btn.playing { background: #dc2626; }
        /* Tabs */
        .tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; }
        .tab { padding: 10px 20px; cursor: pointer; font-size: 14px; font-weight: 600; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body>
    <h1>🎙️ Voice Pool</h1>
    <p style="color:#6b7280;font-size:14px">
        <a href="/admin/dubs">← Back to admin</a>
    </p>

    @if(session('success'))
        <div class="alert success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <h3 style="margin-top:0">Add voice</h3>

        <div class="tabs">
            <div class="tab active" onclick="switchTab('upload')">📁 Upload file</div>
            <div class="tab" onclick="switchTab('youtube')">▶️ YouTube URL</div>
        </div>

        <!-- Upload tab -->
        <div class="tab-panel active" id="panel-upload">
            <form method="POST" action="{{ route('admin.voice-pool.upload') }}" enctype="multipart/form-data" onsubmit="showLoading()">
                @csrf
                <label>Audio file</label>
                <input type="file" name="audio" accept="audio/*,video/*,.wav,.mp3,.ogg,.flac,.m4a,.webm,.mp4,.mkv" required>
                <div class="hint">Any audio or video file — max 200 MB (audio will be extracted)</div>

                <div class="row" style="margin-top:4px">
                    <div>
                        <label>Voice name</label>
                        <input type="text" name="name" placeholder="actor1" pattern="[a-zA-Z0-9_-]+" value="{{ old('name') }}" required>
                        <div class="hint">Letters, numbers, - _ only</div>
                    </div>
                    <div>
                        <label>Gender</label>
                        <select name="gender">
                            <option value="male" {{ old('gender')=='male'?'selected':'' }}>Male</option>
                            <option value="female" {{ old('gender')=='female'?'selected':'' }}>Female</option>
                            <option value="child" {{ old('gender')=='child'?'selected':'' }}>Child</option>
                        </select>
                    </div>
                    <div>
                        <label>Start (seconds)</label>
                        <input type="number" name="start" placeholder="0" min="0" value="{{ old('start', 0) }}">
                        <div class="hint">Skip intro/music</div>
                    </div>
                    <div>
                        <label>Duration (seconds)</label>
                        <input type="number" name="duration" placeholder="25" min="5" max="60" value="{{ old('duration', 25) }}">
                        <div class="hint">5–60 sec, aim for 20–30</div>
                    </div>
                </div>

                <label>Reference text <span style="font-weight:400;color:#9ca3af">(optional — transcript of the audio clip)</span></label>
                <textarea name="ref_text" rows="2" style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:14px;resize:vertical" placeholder="Type exactly what is said in the audio clip...">{{ old('ref_text') }}</textarea>
                <div class="hint">Helps F5-TTS clone the voice accurately. Leave blank to auto-detect.</div>

                <button type="submit" class="primary">⬆️ Upload &amp; Add</button>
                <div id="loading">⏳ Processing... this takes a few seconds</div>
            </form>
        </div>

        <!-- YouTube tab -->
        <div class="tab-panel" id="panel-youtube">
            <form method="POST" action="{{ route('admin.voice-pool.add') }}" onsubmit="showLoading()">
                @csrf

                <label>YouTube URL</label>
                <input type="text" name="youtube_url" placeholder="https://youtube.com/watch?v=..." value="{{ old('youtube_url') }}" required>
                <div class="hint">Paste any YouTube video URL. The audio will be downloaded and trimmed.</div>

                <div class="row" style="margin-top:4px">
                    <div>
                        <label>Voice name</label>
                        <input type="text" name="name" placeholder="actor1" pattern="[a-zA-Z0-9_-]+" value="{{ old('name') }}" required>
                        <div class="hint">Letters, numbers, - _ only</div>
                    </div>
                    <div>
                        <label>Gender</label>
                        <select name="gender">
                            <option value="male" {{ old('gender')=='male'?'selected':'' }}>Male</option>
                            <option value="female" {{ old('gender')=='female'?'selected':'' }}>Female</option>
                            <option value="child" {{ old('gender')=='child'?'selected':'' }}>Child</option>
                        </select>
                    </div>
                    <div>
                        <label>Start (seconds)</label>
                        <input type="number" name="start" placeholder="0" min="0" value="{{ old('start', 0) }}">
                        <div class="hint">Skip intro/music</div>
                    </div>
                    <div>
                        <label>Duration (seconds)</label>
                        <input type="number" name="duration" placeholder="25" min="5" max="60" value="{{ old('duration', 25) }}">
                        <div class="hint">5–60 sec, aim for 20–30</div>
                    </div>
                </div>

                <label>Reference text <span style="font-weight:400;color:#9ca3af">(optional — transcript of the audio clip)</span></label>
                <textarea name="ref_text" rows="2" style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:14px;resize:vertical" placeholder="Type exactly what is said in the audio clip...">{{ old('ref_text') }}</textarea>
                <div class="hint">Helps F5-TTS clone the voice accurately. Leave blank to auto-detect.</div>

                <button type="submit" class="primary">⬇️ Download &amp; Add</button>
                <div id="loading">⏳ Downloading... this takes 10–30 seconds</div>
            </form>
        </div>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Current pool ({{ count($pool) }} voices)</h3>

        @if(empty($pool))
            <p style="color:#9ca3af;font-size:14px">No voices yet. Add some above.</p>
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
                        <td><b>{{ $voice['name'] }}</b></td>
                        <td><span class="badge {{ $voice['gender'] }}">{{ $voice['gender'] }}</span></td>
                        <td>{{ $voice['duration'] }}</td>
                        <td>{{ $voice['size'] }}</td>
                        <td style="color:#374151;font-size:13px" id="speed-label-{{ $voice['gender'] }}-{{ $voice['name'] }}">{{ $voice['speed'] }}×</td>
                        <td style="max-width:220px">
                            <div style="display:flex;gap:4px;align-items:flex-start">
                                <textarea id="ref-{{ $voice['gender'] }}-{{ $voice['name'] }}" rows="2" style="flex:1;font-size:11px;padding:4px 6px;border:1px solid #ddd;border-radius:4px;resize:vertical;color:#374151" placeholder="Type transcript…">{{ $voice['ref_text'] ?? '' }}</textarea>
                                <button type="button" onclick="saveRefText('{{ $voice['gender'] }}','{{ $voice['name'] }}')" style="background:#2563eb;color:white;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:11px;white-space:nowrap">💾</button>
                            </div>
                        </td>
                        <td style="display:flex;gap:6px;align-items:center">
                            <button type="button" class="play-btn" onclick="togglePlay(this, '{{ route('admin.voice-pool.play', [$voice['gender'], $voice['name']]) }}')">▶ Play</button>
                            <form method="POST" action="{{ route('admin.voice-pool.delete', [$voice['gender'], $voice['name']]) }}" style="display:inline" onsubmit="return confirm('Delete {{ $voice['name'] }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if(count($pool) > 0)
        <div style="margin-top:16px;font-size:13px;color:#6b7280">
            <b>Assignment order:</b> Speaker 0 → {{ collect($pool)->where('gender','male')->values()->first()['name'] ?? '?' }},
            Speaker 1 → {{ collect($pool)->where('gender','male')->values()->skip(1)->first()['name'] ?? collect($pool)->where('gender','female')->values()->first()['name'] ?? '?' }},
            etc. Cycles through pool by gender if more speakers than voices.
        </div>
        @endif
    </div>

    <div class="card" id="test-card">
        <h3 style="margin-top:0">🧪 Test synthesis <span style="font-size:12px;font-weight:400;color:#6b7280">MMS + OpenVoice v2</span></h3>
        <div class="row" style="margin-bottom:12px">
            <div>
                <label>Voice</label>
                <select id="test-voice">
                    @foreach($pool as $voice)
                    <option value="{{ $voice['gender'] }}|{{ $voice['name'] }}">{{ $voice['name'] }} ({{ $voice['gender'] }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Language</label>
                <select id="test-lang">
                    <option value="uz">Uzbek (uz)</option>
                    <option value="ru">Russian (ru)</option>
                    <option value="en">English (en)</option>
                    <option value="tr">Turkish (tr)</option>
                </select>
            </div>
        </div>
        <label>Speed <span id="speed-display" style="font-weight:400;color:#6b7280">1.0×</span></label>
        <div style="display:flex;align-items:center;gap:10px">
            <input type="range" id="test-speed" min="0.5" max="2.0" step="0.05" value="1.0" style="flex:1;padding:0" oninput="document.getElementById('speed-display').textContent=parseFloat(this.value).toFixed(2)+'×'">
            <button type="button" style="background:#059669;color:white;border:none;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px;white-space:nowrap" onclick="saveSpeed()">💾 Save</button>
            <span id="save-status" style="font-size:12px;color:#6b7280"></span>
        </div>
        <div class="hint">Adjust until the speaking pace sounds natural, then save — it will be used in all future dubs with this voice.</div>

        <label style="margin-top:16px">Voice similarity (tau) <span id="tau-display" style="font-weight:400;color:#6b7280">0.9</span></label>
        <input type="range" id="test-tau" min="0.0" max="1.0" step="0.05" value="0.9" style="width:100%;padding:0" oninput="document.getElementById('tau-display').textContent=parseFloat(this.value).toFixed(2)">
        <div class="hint">Higher = more similar to reference voice, but may introduce artifacts. Try 0.7–1.0.</div>

        <label style="margin-top:12px">Text</label>
        <textarea id="test-text" rows="3" style="width:100%;padding:9px 12px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:14px;font-family:sans-serif" placeholder="Enter text to synthesize...">Salom, men o'zbek tilida gapiraman. Bu sinov matni.</textarea>
        <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
            <button class="primary" onclick="runTest()" id="test-btn">▶ Synthesize</button>
            <span id="test-status" style="font-size:13px;color:#6b7280"></span>
        </div>
        <div id="test-player" style="margin-top:14px;display:none">
            <audio id="test-audio" controls style="width:100%"></audio>
        </div>
        @if(empty($pool))
        <p style="color:#9ca3af;font-size:13px;margin-top:8px">Add voices to the pool first.</p>
        @endif
    </div>

    <script>
        let currentAudio = null;
        let currentBtn = null;

        async function saveRefText(gender, name) {
            const ta = document.getElementById('ref-' + gender + '-' + name);
            const btn = ta.nextElementSibling;
            btn.textContent = '⏳';
            btn.disabled = true;
            const res = await fetch(`/admin/voice-pool/${gender}/${name}/ref-text`, {
                method: 'POST',
                headers: {'Content-Type':'application/json','X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
                body: JSON.stringify({ref_text: ta.value}),
            });
            btn.disabled = false;
            btn.textContent = res.ok ? '✅' : '❌';
            setTimeout(() => btn.textContent = '💾', 1500);
        }

        function togglePlay(btn, url) {
            if (currentAudio && currentBtn) {
                currentAudio.pause();
                currentBtn.textContent = '▶ Play';
                currentBtn.classList.remove('playing');
                if (currentBtn === btn) { currentAudio = null; currentBtn = null; return; }
            }
            const audio = new Audio(url);
            audio.play();
            btn.textContent = '⏹ Stop';
            btn.classList.add('playing');
            currentAudio = audio;
            currentBtn = btn;
            audio.onended = () => { btn.textContent = '▶ Play'; btn.classList.remove('playing'); currentAudio = null; currentBtn = null; };
        }

        // Speed and tau data per voice (preloaded from server)
        const voiceSpeeds = {
            @foreach($pool as $v)
            '{{ $v['gender'] }}|{{ $v['name'] }}': {{ $v['speed'] }},
            @endforeach
        };
        const voiceTaus = {
            @foreach($pool as $v)
            '{{ $v['gender'] }}|{{ $v['name'] }}': {{ $v['tau'] }},
            @endforeach
        };

        function prefillVoiceParams(voiceVal) {
            const speed = voiceSpeeds[voiceVal] ?? 1.0;
            const tau   = voiceTaus[voiceVal]   ?? 0.9;
            document.getElementById('test-speed').value = speed;
            document.getElementById('speed-display').textContent = speed.toFixed(2) + '×';
            document.getElementById('test-tau').value = tau;
            document.getElementById('tau-display').textContent = tau.toFixed(2);
            document.getElementById('save-status').textContent = '';
        }

        // When voice selection changes, pre-fill sliders
        document.getElementById('test-voice').addEventListener('change', function() {
            prefillVoiceParams(this.value);
        });

        // Pre-fill on load
        (function() {
            const sel = document.getElementById('test-voice');
            if (sel && sel.value) prefillVoiceParams(sel.value);
        })();

        async function saveSpeed() {
            const voiceVal = document.getElementById('test-voice').value;
            if (!voiceVal) return;
            const [gender, name] = voiceVal.split('|');
            const speed = parseFloat(document.getElementById('test-speed').value);
            const tau   = parseFloat(document.getElementById('test-tau').value);
            const saveStatus = document.getElementById('save-status');
            saveStatus.textContent = '⏳ Saving…';

            try {
                const resp = await fetch(`/admin/voice-pool/${gender}/${name}/speed`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ speed, tau }),
                });
                if (resp.ok) {
                    voiceSpeeds[voiceVal] = speed;
                    voiceTaus[voiceVal]   = tau;
                    const label = document.getElementById(`speed-label-${gender}-${name}`);
                    if (label) label.textContent = speed.toFixed(2) + '×';
                    saveStatus.textContent = '✅ Saved';
                } else {
                    saveStatus.textContent = '❌ Failed';
                }
            } catch(e) {
                saveStatus.textContent = '❌ ' + e.message;
            }
        }

        async function runTest() {
            const voiceVal = document.getElementById('test-voice').value;
            if (!voiceVal) { alert('No voices in pool.'); return; }
            const [gender, name] = voiceVal.split('|');
            const text = document.getElementById('test-text').value.trim();
            const lang = document.getElementById('test-lang').value;
            if (!text) { alert('Enter text first.'); return; }

            const btn = document.getElementById('test-btn');
            const status = document.getElementById('test-status');
            btn.disabled = true;
            status.textContent = '⏳ Synthesizing…';

            try {
                const resp = await fetch('{{ route('admin.voice-pool.test') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ gender, name, text, language: lang, speed: parseFloat(document.getElementById('test-speed').value), tau: parseFloat(document.getElementById('test-tau').value) }),
                });

                if (!resp.ok) {
                    const err = await resp.json().catch(() => ({ error: resp.statusText }));
                    status.textContent = '❌ ' + (err.error || 'Error');
                    return;
                }

                const blob = await resp.blob();
                const url = URL.createObjectURL(blob);
                const audio = document.getElementById('test-audio');
                audio.src = url;
                document.getElementById('test-player').style.display = 'block';
                audio.play();
                status.textContent = '✅ Done';
            } catch (e) {
                status.textContent = '❌ ' + e.message;
            } finally {
                btn.disabled = false;
            }
        }

        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        function switchTab(name) {
            document.querySelectorAll('.tab').forEach((t, i) => {
                t.classList.toggle('active', ['upload','youtube'][i] === name);
            });
            document.querySelectorAll('.tab-panel').forEach(p => {
                p.classList.toggle('active', p.id === 'panel-' + name);
            });
        }
    </script>
</body>
</html>
