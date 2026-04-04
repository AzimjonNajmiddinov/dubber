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
                        <td>
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

    <script>
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
