<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Live Dubbing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 0;
            background: #111;
            color: #fff;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 600px;
            width: 100%;
            padding: 20px;
            text-align: center;
        }
        h1 {
            font-size: 32px;
            margin: 0 0 8px 0;
            font-weight: 600;
        }
        .subtitle {
            color: #888;
            font-size: 16px;
            margin-bottom: 40px;
        }
        .input-group {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        input[type="url"] {
            flex: 1;
            padding: 14px 16px;
            border: 1px solid #333;
            border-radius: 8px;
            background: #222;
            color: #fff;
            font-size: 16px;
            outline: none;
        }
        input[type="url"]::placeholder {
            color: #666;
        }
        input[type="url"]:focus {
            border-color: #555;
        }
        select {
            padding: 14px 16px;
            border: 1px solid #333;
            border-radius: 8px;
            background: #222;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            outline: none;
        }
        .btn {
            padding: 14px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #fff;
            color: #111;
        }
        .btn-primary:hover {
            background: #eee;
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .error {
            color: #ef4444;
            font-size: 14px;
            margin-top: 12px;
        }
        .loading {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 24px;
            padding: 16px;
            background: #1a1a1a;
            border-radius: 8px;
        }
        .loading.active {
            display: flex;
        }
        .spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #333;
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .status-text {
            color: #888;
            font-size: 14px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Live Dubbing</h1>
        <p class="subtitle">Paste a YouTube or video URL and watch it dubbed in real-time</p>

        <form id="dubForm">
            <div class="input-group">
                <input type="url" name="url" id="videoUrl" placeholder="https://youtube.com/watch?v=..." required>
                <select name="target_language" id="targetLanguage">
                    <option value="uz">Uzbek</option>
                    <option value="ru">Russian</option>
                    <option value="en">English</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" id="playBtn">Play</button>
            <div class="error" id="error" style="display: none;"></div>
        </form>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <span class="status-text" id="statusText">Starting...</span>
        </div>
    </div>

    <div class="footer">
        <a href="{{ route('videos.index') }}">Back to Dashboard</a>
    </div>

    <script>
        const form = document.getElementById('dubForm');
        const urlInput = document.getElementById('videoUrl');
        const langSelect = document.getElementById('targetLanguage');
        const playBtn = document.getElementById('playBtn');
        const errorDiv = document.getElementById('error');
        const loadingDiv = document.getElementById('loading');
        const statusText = document.getElementById('statusText');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Reset state
            errorDiv.style.display = 'none';
            playBtn.disabled = true;
            loadingDiv.classList.add('active');
            statusText.textContent = 'Initializing...';

            try {
                // Start the dubbing process
                const response = await fetch('/api/live/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        url: urlInput.value,
                        target_language: langSelect.value,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to start dubbing');
                }

                // Redirect to segment player
                statusText.textContent = 'Redirecting to player...';
                window.location.href = '/player/' + data.video_id + '/segments';

            } catch (err) {
                errorDiv.textContent = err.message || 'An error occurred';
                errorDiv.style.display = 'block';
                playBtn.disabled = false;
                loadingDiv.classList.remove('active');
            }
        });

        // Focus URL input on load
        urlInput.focus();
    </script>
</body>
</html>
