<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Movie Dubber - AI Video Dubbing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 640px;
            width: 100%;
            padding: 24px;
            text-align: center;
        }
        .logo {
            font-size: 48px;
            margin-bottom: 8px;
        }
        h1 {
            font-size: 36px;
            margin: 0 0 8px 0;
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
        input[type="url"]::placeholder {
            color: #666;
        }
        input[type="url"]:focus {
            border-color: #22c55e;
        }
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
        .lang-option:hover {
            border-color: #555;
            color: #fff;
        }
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
        .error {
            color: #ef4444;
            font-size: 14px;
            margin-top: 16px;
            padding: 12px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 8px;
        }
        .loading {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin-top: 24px;
            padding: 24px;
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
        }
        .loading.active {
            display: flex;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #333;
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .status-text {
            color: #888;
            font-size: 15px;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 40px;
        }
        .feature {
            text-align: center;
            padding: 16px;
        }
        .feature-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .feature-title {
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 4px;
        }
        .feature-desc {
            font-size: 12px;
            color: #666;
        }
        .footer {
            position: fixed;
            bottom: 20px;
            color: #444;
            font-size: 13px;
        }
        .footer a {
            color: #666;
            text-decoration: none;
        }
        .footer a:hover {
            color: #888;
        }
        @media (max-width: 500px) {
            .features { grid-template-columns: 1fr; }
            .language-selector { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">🎬</div>
        <h1>Movie Dubber</h1>
        <p class="subtitle">AI-powered video dubbing with multiple speakers.<br>Paste any video URL and watch it dubbed instantly.</p>

        <div class="form-card">
            <form id="dubForm">
                <div class="input-group">
                    <input type="url" name="url" id="videoUrl"
                           placeholder="Paste YouTube or video URL..."
                           required>

                    <div class="language-selector">
                        <button type="button" class="lang-option active" data-lang="uz">
                            🇺🇿 Uzbek
                        </button>
                        <button type="button" class="lang-option" data-lang="ru">
                            🇷🇺 Russian
                        </button>
                        <button type="button" class="lang-option" data-lang="en">
                            🇺🇸 English
                        </button>
                    </div>
                </div>

                <input type="hidden" name="target_language" id="targetLanguage" value="uz">

                <button type="submit" class="btn btn-primary" id="playBtn">
                    Start Dubbing
                </button>

                <div class="error" id="error" style="display: none;"></div>
            </form>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <span class="status-text" id="statusText">Initializing...</span>
            </div>
        </div>

        <div class="features">
            <div class="feature">
                <div class="feature-icon">🎙️</div>
                <div class="feature-title">Multi-Speaker</div>
                <div class="feature-desc">Detects and dubs each speaker with unique voice</div>
            </div>
            <div class="feature">
                <div class="feature-icon">⚡</div>
                <div class="feature-title">Real-time</div>
                <div class="feature-desc">Start watching while dubbing continues</div>
            </div>
            <div class="feature">
                <div class="feature-icon">📥</div>
                <div class="feature-title">Download</div>
                <div class="feature-desc">Download dubbed video when complete</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <a href="{{ route('videos.index') }}">Dashboard</a>
    </div>

    <script>
        const form = document.getElementById('dubForm');
        const urlInput = document.getElementById('videoUrl');
        const langInput = document.getElementById('targetLanguage');
        const langOptions = document.querySelectorAll('.lang-option');
        const playBtn = document.getElementById('playBtn');
        const errorDiv = document.getElementById('error');
        const loadingDiv = document.getElementById('loading');
        const statusText = document.getElementById('statusText');

        // Language selector
        langOptions.forEach(btn => {
            btn.addEventListener('click', () => {
                langOptions.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                langInput.value = btn.dataset.lang;
            });
        });

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            errorDiv.style.display = 'none';
            playBtn.disabled = true;
            loadingDiv.classList.add('active');
            statusText.textContent = 'Starting dubbing process...';

            try {
                const response = await fetch('/api/live/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        url: urlInput.value,
                        target_language: langInput.value,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to start dubbing');
                }

                statusText.textContent = 'Redirecting to player...';
                window.location.href = '/player/' + data.video_id + '/segments';

            } catch (err) {
                errorDiv.textContent = err.message || 'An error occurred';
                errorDiv.style.display = 'block';
                playBtn.disabled = false;
                loadingDiv.classList.remove('active');
            }
        });

        urlInput.focus();
    </script>
</body>
</html>
