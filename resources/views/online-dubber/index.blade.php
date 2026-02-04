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
        .error-message {
            color: #ef4444;
            font-size: 14px;
            margin-top: 8px;
            text-align: left;
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
        <p class="subtitle">Paste any video URL, pick a language, and get a dubbed video.<br>Supports YouTube, Vimeo, and direct video links.</p>

        <div class="form-card">
            <form action="{{ route('dub.submit') }}" method="POST">
                @csrf
                <div class="input-group">
                    <div>
                        <input type="url" name="url" id="videoUrl"
                               placeholder="Paste YouTube or video URL..."
                               value="{{ old('url') }}"
                               class="@error('url') input-error @enderror"
                               required>
                        @error('url')
                            <div class="error-message">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="language-selector">
                        <button type="button" class="lang-option {{ old('target_language', 'uz') === 'uz' ? 'active' : '' }}" data-lang="uz">
                            🇺🇿 Uzbek
                        </button>
                        <button type="button" class="lang-option {{ old('target_language') === 'ru' ? 'active' : '' }}" data-lang="ru">
                            🇷🇺 Russian
                        </button>
                        <button type="button" class="lang-option {{ old('target_language') === 'en' ? 'active' : '' }}" data-lang="en">
                            🇺🇸 English
                        </button>
                    </div>
                    @error('target_language')
                        <div class="error-message">{{ $message }}</div>
                    @enderror
                </div>

                <input type="hidden" name="target_language" id="targetLanguage" value="{{ old('target_language', 'uz') }}">

                <button type="submit" class="btn btn-primary">
                    Start Dubbing
                </button>
            </form>
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
                        <span class="recent-url">{{ $dub->source_url }}</span>
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
        const langOptions = document.querySelectorAll('.lang-option');
        const langInput = document.getElementById('targetLanguage');

        langOptions.forEach(btn => {
            btn.addEventListener('click', () => {
                langOptions.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                langInput.value = btn.dataset.lang;
            });
        });

        document.getElementById('videoUrl').focus();
    </script>
</body>
</html>
