<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dubbed Video #{{ $video->id }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system;
            margin: 0;
            padding: 20px;
            background: #111;
            color: #fff;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 16px 0;
            color: #fff;
        }
        .video-wrapper {
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
        }
        video {
            width: 100%;
            max-height: 80vh;
            display: block;
        }
        .info {
            margin-top: 16px;
            padding: 12px 16px;
            background: #222;
            border-radius: 8px;
            font-size: 14px;
        }
        .info-row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }
        .info-item {
            color: #888;
        }
        .info-item strong {
            color: #fff;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            background: #0a7a2f;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
        }
        .source-url {
            margin-top: 12px;
            font-size: 12px;
            color: #666;
            word-break: break-all;
        }
        .actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
        }
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 6px;
            border: none;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn-primary {
            background: #fff;
            color: #111;
        }
        .btn-secondary {
            background: #333;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Dubbed Video #{{ $video->id }}</h1>

        <div class="video-wrapper">
            <video controls autoplay>
                <source src="{{ $streamUrl }}" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>

        <div class="info">
            <div class="info-row">
                <div class="info-item">
                    Status: <span class="badge">{{ ucfirst(str_replace('_', ' ', $video->status)) }}</span>
                </div>
                <div class="info-item">
                    Target Language: <strong>{{ strtoupper($video->target_language) }}</strong>
                </div>
                <div class="info-item">
                    Created: <strong>{{ $video->created_at->diffForHumans() }}</strong>
                </div>
            </div>
            @if($video->source_url)
            <div class="source-url">
                Source: {{ $video->source_url }}
            </div>
            @endif
        </div>

        <div class="actions">
            <a href="{{ route('videos.download', $video) }}" class="btn btn-primary">Download MP4</a>
            <a href="{{ route('api.stream.status', $video) }}" class="btn btn-secondary">API Status</a>
            <a href="{{ route('videos.index') }}" class="btn btn-secondary">All Videos</a>
        </div>
    </div>
</body>
</html>
