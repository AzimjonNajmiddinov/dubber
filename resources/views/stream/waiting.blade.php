<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Processing Video #{{ $video->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="5">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 20px;
            background: #111;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 500px;
            text-align: center;
        }
        h1 {
            font-size: 24px;
            margin: 0 0 8px 0;
        }
        .subtitle {
            color: #888;
            margin-bottom: 32px;
        }
        .progress-container {
            background: #333;
            border-radius: 999px;
            height: 12px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #0a7a2f, #22c55e);
            border-radius: 999px;
            transition: width 0.5s ease;
        }
        .progress-text {
            font-size: 14px;
            color: #888;
        }
        .status {
            margin-top: 24px;
            padding: 16px;
            background: #222;
            border-radius: 8px;
        }
        .status-label {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .status-detail {
            font-size: 13px;
            color: #888;
        }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #444;
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            vertical-align: middle;
            margin-right: 8px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .source-url {
            margin-top: 16px;
            font-size: 12px;
            color: #555;
            word-break: break-all;
        }
        .error {
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Processing Video</h1>
        <p class="subtitle">Your dubbed video is being prepared</p>

        <div class="progress-container">
            <div class="progress-bar" style="width: {{ $progress }}%;"></div>
        </div>
        <div class="progress-text">{{ $progress }}% complete</div>

        <div class="status">
            @if(str_contains($video->status ?? '', 'failed'))
                <div class="status-label error">Processing Failed</div>
                <div class="status-detail">{{ $label }}</div>
                <div class="status-detail" style="margin-top: 8px;">
                    Try a different video with clear speech (interviews, tutorials work best).
                </div>
            @else
                <div class="status-label">
                    <span class="spinner"></span>
                    {{ $label }}
                </div>
                <div class="status-detail">Page auto-refreshes every 5 seconds</div>
            @endif
        </div>

        @if($video->source_url)
        <div class="source-url">
            Source: {{ $video->source_url }}
        </div>
        @endif

        <div style="margin-top: 24px;">
            <a href="{{ route('videos.index') }}" style="color: #888; text-decoration: none; font-size: 14px;">← Back to Home</a>
        </div>
    </div>

    <script>
        // Also poll via JS for smoother updates
        const videoId = {{ $video->id }};

        async function checkStatus() {
            try {
                const res = await fetch(`{{ url('/api/stream') }}/${videoId}/status`);
                const data = await res.json();

                if (data.ready) {
                    window.location.reload();
                }

                // Update progress bar
                document.querySelector('.progress-bar').style.width = data.progress + '%';
                document.querySelector('.progress-text').textContent = data.progress + '% complete';
                document.querySelector('.status-label').innerHTML =
                    '<span class="spinner"></span>' + data.label;
            } catch (e) {
                // ignore
            }
        }

        setInterval(checkStatus, 3000);
    </script>
</body>
</html>
