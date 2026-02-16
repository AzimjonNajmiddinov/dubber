<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Online Video Dubber - AI Video Dubbing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            max-width: 640px;
            width: 100%;
            padding: 48px 24px 24px;
            text-align: center;
        }
        .logo { font-size: 48px; margin-bottom: 8px; }
        h1 {
            font-size: 36px;
            margin: 0 0 8px;
            font-weight: 700;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .subtitle {
            color: #888;
            font-size: 18px;
            margin-bottom: 40px;
            line-height: 1.5;
        }
        .form-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 32px;
            backdrop-filter: blur(10px);
        }
        .input-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 24px;
        }
        input[type="url"] {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #333;
            border-radius: 12px;
            background: rgba(0,0,0,0.5);
            color: #fff;
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        input[type="url"]::placeholder { color: #666; }
        input[type="url"]:focus { border-color: #22c55e; }
        input[type="url"].input-error { border-color: #ef4444; }
        .language-selector {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .lang-option {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid #333;
            border-radius: 12px;
            background: transparent;
            color: #888;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .lang-option:hover { border-color: #555; color: #fff; }
        .lang-option.active {
            border-color: #22c55e;
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        .btn {
            width: 100%;
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #000;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .error-message {
            color: #ef4444;
            font-size: 14px;
            margin-top: 8px;
            text-align: left;
        }
        .mode-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #333;
        }
        .mode-tab {
            flex: 1;
            padding: 12px 16px;
            background: transparent;
            color: #888;
            border: none;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .mode-tab:first-child { border-right: 1px solid #333; }
        .mode-tab.active {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
        }
        .mode-tab:hover:not(.active) { color: #fff; }
        .mode-panel { display: none; }
        .mode-panel.active { display: block; }
        .file-drop {
            border: 2px dashed #333;
            border-radius: 12px;
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: rgba(0,0,0,0.3);
        }
        .file-drop:hover, .file-drop.dragover {
            border-color: #22c55e;
            background: rgba(34, 197, 94, 0.05);
        }
        .file-drop-icon { font-size: 36px; margin-bottom: 8px; }
        .file-drop-text { color: #888; font-size: 14px; }
        .file-drop-text strong { color: #22c55e; }
        .file-name {
            margin-top: 8px;
            font-size: 14px;
            color: #22c55e;
            font-weight: 500;
        }
        .file-input { display: none; }
        .progress-bar-track {
            width: 100%;
            height: 8px;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 40px;
        }
        .feature { text-align: center; padding: 16px; }
        .feature-icon { font-size: 32px; margin-bottom: 8px; }
        .feature-title { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 4px; }
        .feature-desc { font-size: 12px; color: #666; }

        /* Recent dubs */
        .recent-section {
            margin-top: 48px;
            text-align: left;
        }
        .recent-section h2 {
            font-size: 20px;
            font-weight: 600;
            color: #ccc;
            margin-bottom: 16px;
        }
        .recent-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .recent-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            text-decoration: none;
            color: #ccc;
            transition: background 0.2s;
        }
        .recent-item:hover { background: rgba(255,255,255,0.08); }
        .recent-url {
            flex: 1;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .recent-lang {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            font-weight: 600;
        }
        .badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-done { background: rgba(34,197,94,0.15); color: #22c55e; }
        .badge-processing { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .badge-failed { background: rgba(239,68,68,0.15); color: #ef4444; }
        .badge-pending { background: rgba(250,204,21,0.15); color: #facc15; }

        .footer {
            margin-top: 48px;
            padding-bottom: 24px;
            color: #444;
            font-size: 13px;
        }
        .footer a { color: #666; text-decoration: none; }
        .footer a:hover { color: #888; }

        @media (max-width: 500px) {
            .features { grid-template-columns: 1fr; }
            .language-selector { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🎬</div>
        <h1>Online Video Dubber</h1>
        <p class="subtitle">Upload a video file or paste a URL, pick a language, and get a dubbed video.</p>

        <div class="form-card">
            <div class="mode-tabs">
                <button type="button" class="mode-tab active" data-mode="upload">Upload File</button>
                <button type="button" class="mode-tab" data-mode="url">Paste URL</button>
            </div>

            <!-- File Upload Panel (uses JS chunked upload) -->
            <div class="mode-panel active" id="panel-upload">
                <div class="input-group">
                    <div class="file-drop" id="fileDrop">
                        <div class="file-drop-icon">📁</div>
                        <div class="file-drop-text">
                            <strong>Click to choose</strong> or drag & drop<br>
                            MP4, MKV, AVI, WebM, MOV (max 500MB)
                        </div>
                        <div class="file-name" id="fileName"></div>
                    </div>
                    <input type="file" id="videoFile" class="file-input"
                           accept=".mp4,.mkv,.avi,.webm,.mov">
                    <div class="error-message" id="uploadError" style="display:none"></div>

                    <div class="language-selector">
                        <button type="button" class="lang-option active" data-lang="uz">🇺🇿 Uzbek</button>
                        <button type="button" class="lang-option" data-lang="ru">🇷🇺 Russian</button>
                        <button type="button" class="lang-option" data-lang="en">🇺🇸 English</button>
                    </div>
                </div>

                <button type="button" class="btn btn-primary" id="uploadBtn" onclick="startChunkedUpload()">
                    Start Dubbing
                </button>

                <!-- Upload progress (hidden until upload starts) -->
                <div id="uploadProgress" style="display:none; margin-top:16px">
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="uploadBar" style="width:0%"></div>
                    </div>
                    <div style="color:#888; font-size:13px; margin-top:8px" id="uploadStatus">Uploading... 0%</div>
                </div>
            </div>

            <!-- URL Panel (standard form POST) -->
            <div class="mode-panel" id="panel-url">
                <form action="{{ route('dub.submit') }}" method="POST" id="urlForm">
                    @csrf
                    <div class="input-group">
                        <input type="url" name="url" id="videoUrl"
                               placeholder="Paste YouTube or video URL..."
                               value="{{ old('url') }}"
                               class="@error('url') input-error @enderror"
                               required>
                        @error('url')
                            <div class="error-message">{{ $message }}</div>
                        @enderror

                        <div class="language-selector">
                            <button type="button" class="lang-option active" data-lang="uz">🇺🇿 Uzbek</button>
                            <button type="button" class="lang-option" data-lang="ru">🇷🇺 Russian</button>
                            <button type="button" class="lang-option" data-lang="en">🇺🇸 English</button>
                        </div>
                        @error('target_language')
                            <div class="error-message">{{ $message }}</div>
                        @enderror
                    </div>

                    <input type="hidden" name="target_language" id="urlTargetLanguage" value="uz">

                    <button type="submit" class="btn btn-primary" id="urlSubmitBtn">
                        Start Dubbing
                    </button>
                </form>
            </div>
        </div>

        <div class="features">
            <div class="feature">
                <div class="feature-icon">🎙️</div>
                <div class="feature-title">Multi-Speaker</div>
                <div class="feature-desc">Detects and dubs each speaker with a unique voice</div>
            </div>
            <div class="feature">
                <div class="feature-icon">🌐</div>
                <div class="feature-title">3 Languages</div>
                <div class="feature-desc">Dub into Uzbek, Russian, or English</div>
            </div>
            <div class="feature">
                <div class="feature-icon">📥</div>
                <div class="feature-title">Download</div>
                <div class="feature-desc">Watch online or download the dubbed video</div>
            </div>
        </div>

        @if($recentDubs->isNotEmpty())
        <div class="recent-section">
            <h2>Recent Dubs</h2>
            <div class="recent-list">
                @foreach($recentDubs as $dub)
                    <a href="{{ route('dub.progress', $dub) }}" class="recent-item">
                        <span class="recent-url">{{ $dub->source_url ?: ('Uploaded: ' . basename($dub->original_path ?? 'video')) }}</span>
                        <span class="recent-lang">{{ $dub->target_language }}</span>
                        @if(in_array($dub->status, ['dubbed_complete', 'lipsync_done', 'done']))
                            <span class="badge badge-done">Done</span>
                        @elseif(in_array($dub->status, ['failed', 'download_failed']))
                            <span class="badge badge-failed">Failed</span>
                        @elseif($dub->status === 'pending')
                            <span class="badge badge-pending">Pending</span>
                        @else
                            <span class="badge badge-processing">Processing</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
        @endif

        <div class="footer">
            <a href="{{ route('videos.index') }}">Dashboard</a>
        </div>
    </div>

    <script>
        const CHUNK_SIZE = 2 * 1024 * 1024; // 2MB chunks (well under nginx limit)
        const CSRF_TOKEN = '{{ csrf_token() }}';
        let selectedLang = 'uz';

        // Language selector (both panels share lang-option class)
        document.querySelectorAll('.lang-option').forEach(btn => {
            btn.addEventListener('click', () => {
                // Only update buttons in the same panel
                const panel = btn.closest('.mode-panel') || btn.closest('.form-card');
                panel.querySelectorAll('.lang-option').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                selectedLang = btn.dataset.lang;
                // Sync hidden input for URL form
                const urlLang = document.getElementById('urlTargetLanguage');
                if (urlLang) urlLang.value = selectedLang;
            });
        });

        // Mode tabs
        document.querySelectorAll('.mode-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.querySelectorAll('.mode-panel').forEach(p => p.classList.remove('active'));
                document.getElementById('panel-' + tab.dataset.mode).classList.add('active');
                if (tab.dataset.mode === 'url') {
                    document.getElementById('videoUrl').focus();
                }
            });
        });

        // File drop zone
        const fileDrop = document.getElementById('fileDrop');
        const fileInput = document.getElementById('videoFile');
        const fileNameEl = document.getElementById('fileName');

        fileDrop.addEventListener('click', () => fileInput.click());
        fileDrop.addEventListener('dragover', (e) => { e.preventDefault(); fileDrop.classList.add('dragover'); });
        fileDrop.addEventListener('dragleave', () => fileDrop.classList.remove('dragover'));
        fileDrop.addEventListener('drop', (e) => {
            e.preventDefault();
            fileDrop.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showFileName(e.dataTransfer.files[0]);
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) showFileName(fileInput.files[0]);
        });

        function showFileName(file) {
            const sizeMB = (file.size / 1024 / 1024).toFixed(1);
            fileNameEl.textContent = file.name + ' (' + sizeMB + ' MB)';
        }

        // URL form submit - loading state
        const urlForm = document.getElementById('urlForm');
        if (urlForm) {
            urlForm.addEventListener('submit', function() {
                document.getElementById('urlSubmitBtn').disabled = true;
                document.getElementById('urlSubmitBtn').textContent = 'Starting...';
            });
        }

        // Chunked upload
        function generateId() {
            const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            let id = '';
            for (let i = 0; i < 16; i++) id += chars[Math.floor(Math.random() * chars.length)];
            return id;
        }

        async function startChunkedUpload() {
            const file = fileInput.files[0];
            const errorEl = document.getElementById('uploadError');
            const btn = document.getElementById('uploadBtn');

            errorEl.style.display = 'none';

            if (!file) {
                errorEl.textContent = 'Please select a video file first.';
                errorEl.style.display = 'block';
                return;
            }

            const maxSize = 500 * 1024 * 1024;
            if (file.size > maxSize) {
                errorEl.textContent = 'File is too large. Maximum size is 500MB.';
                errorEl.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Uploading...';
            document.getElementById('uploadProgress').style.display = 'block';

            const uploadId = generateId();
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            let uploaded = 0;

            try {
                for (let i = 0; i < totalChunks; i++) {
                    const start = i * CHUNK_SIZE;
                    const end = Math.min(start + CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);

                    const formData = new FormData();
                    formData.append('chunk', chunk, 'chunk');
                    formData.append('upload_id', uploadId);
                    formData.append('chunk_index', i);
                    formData.append('total_chunks', totalChunks);
                    formData.append('_token', CSRF_TOKEN);

                    const res = await fetch('{{ route("dub.chunk") }}', {
                        method: 'POST',
                        body: formData,
                    });

                    if (!res.ok) {
                        throw new Error('Chunk ' + i + ' upload failed (HTTP ' + res.status + ')');
                    }

                    uploaded++;
                    const pct = Math.round((uploaded / totalChunks) * 95); // 95% = all chunks done
                    document.getElementById('uploadBar').style.width = pct + '%';
                    document.getElementById('uploadStatus').textContent = 'Uploading... ' + pct + '%';
                }

                // All chunks uploaded - tell server to assemble
                document.getElementById('uploadStatus').textContent = 'Assembling file...';
                document.getElementById('uploadBar').style.width = '97%';

                const completeData = new FormData();
                completeData.append('upload_id', uploadId);
                completeData.append('total_chunks', totalChunks);
                completeData.append('filename', file.name);
                completeData.append('target_language', selectedLang);
                completeData.append('_token', CSRF_TOKEN);

                const completeRes = await fetch('{{ route("dub.complete") }}', {
                    method: 'POST',
                    body: completeData,
                });

                if (!completeRes.ok) {
                    const errData = await completeRes.json().catch(() => ({}));
                    throw new Error(errData.error || 'Assembly failed');
                }

                const result = await completeRes.json();
                document.getElementById('uploadBar').style.width = '100%';
                document.getElementById('uploadStatus').textContent = 'Done! Redirecting...';

                if (result.redirect) {
                    window.location.href = result.redirect;
                }
            } catch (err) {
                errorEl.textContent = 'Upload failed: ' + err.message;
                errorEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Start Dubbing';
                document.getElementById('uploadProgress').style.display = 'none';
            }
        }
    </script>
</body>
</html>
