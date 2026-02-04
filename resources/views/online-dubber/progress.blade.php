<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $ready ? 'Dubbed Video' : 'Dubbing in Progress' }} - Online Video Dubber</title>
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
            max-width: 720px;
            width: 100%;
            padding: 48px 24px 24px;
        }
        .back-link {
            display: inline-block;
            color: #888;
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 24px;
            transition: color 0.2s;
        }
        .back-link:hover { color: #fff; }
        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .source-url {
            color: #888;
            font-size: 14px;
            margin-bottom: 32px;
            word-break: break-all;
        }
        .card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 32px;
            backdrop-filter: blur(10px);
        }

        /* Progress */
        .progress-section { text-align: center; }
        .progress-bar-track {
            width: 100%;
            height: 8px;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        .progress-label {
            font-size: 16px;
            color: #ccc;
            margin-bottom: 8px;
        }
        .progress-percent {
            font-size: 14px;
            color: #666;
            margin-bottom: 32px;
        }

        /* Pipeline steps */
        .pipeline {
            display: flex;
            justify-content: space-between;
            gap: 4px;
            margin-bottom: 8px;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px 4px;
            font-size: 11px;
            color: #555;
            border-radius: 6px;
            transition: all 0.3s;
        }
        .step.active {
            color: #22c55e;
            background: rgba(34,197,94,0.1);
        }
        .step.done {
            color: #16a34a;
            background: rgba(34,197,94,0.05);
        }
        .step-icon {
            font-size: 18px;
            margin-bottom: 4px;
        }

        /* Spinner */
        .spinner-wrap {
            display: flex;
            justify-content: center;
            margin: 24px 0;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #333;
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Video player */
        .player-section { text-align: center; }
        .video-wrapper {
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            background: #000;
        }
        .video-wrapper video {
            width: 100%;
            display: block;
        }
        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: #000;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(34, 197, 94, 0.3);
        }
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: #ccc;
            border: 1px solid rgba(255,255,255,0.15);
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }

        /* Failed state */
        .failed-section { text-align: center; }
        .failed-icon { font-size: 48px; margin-bottom: 16px; }
        .failed-title {
            font-size: 22px;
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 8px;
        }
        .failed-message {
            color: #888;
            font-size: 15px;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .footer {
            text-align: center;
            margin-top: 32px;
            color: #444;
            font-size: 13px;
        }
        .footer a { color: #666; text-decoration: none; }
        .footer a:hover { color: #888; }

        @media (max-width: 500px) {
            .pipeline { flex-wrap: wrap; }
            .step { flex: 0 0 30%; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="{{ route('dub.index') }}" class="back-link">&larr; Dub Another Video</a>

        <h1>
            @if($failed)
                Dubbing Failed
            @elseif($ready)
                Your Dubbed Video
            @else
                Dubbing in Progress...
            @endif
        </h1>
        <p class="source-url">{{ $video->source_url }}</p>

        <div class="card">
            @if($failed)
                {{-- Failed state --}}
                <div class="failed-section">
                    <div class="failed-icon">&#x26A0;</div>
                    <div class="failed-title">{{ $video->status === 'download_failed' ? 'Download Failed' : 'Processing Failed' }}</div>
                    <div class="failed-message">
                        @if($video->status === 'download_failed')
                            Could not download the video. The URL may be invalid, private, or geo-restricted.
                            Make sure the video is publicly accessible and try again.
                        @else
                            An error occurred while processing the video.
                            This can happen with unsupported formats or very long videos.
                        @endif
                    </div>
                    <a href="{{ route('dub.index') }}" class="btn btn-primary">Try Again</a>
                </div>

            @elseif($ready)
                {{-- Player state --}}
                <div class="player-section">
                    <div class="video-wrapper">
                        <video controls autoplay>
                            <source src="{{ $streamUrl }}" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                    <div class="actions">
                        <a href="{{ $streamUrl }}" download="dubbed_{{ $video->id }}.mp4" class="btn btn-primary">Download Video</a>
                        <a href="{{ route('dub.index') }}" class="btn btn-secondary">Dub Another</a>
                    </div>
                </div>

            @else
                {{-- Progress state --}}
                <div class="progress-section">
                    <div class="spinner-wrap"><div class="spinner"></div></div>
                    <div class="progress-label" id="progressLabel">{{ $label }}</div>
                    <div class="progress-percent" id="progressPercent">{{ $progress }}%</div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="progressBar" style="width: {{ $progress }}%"></div>
                    </div>

                    <div class="pipeline" id="pipeline">
                        @php
                            $steps = [
                                ['key' => 'download', 'icon' => '&#x2B07;', 'label' => 'Download', 'threshold' => 5],
                                ['key' => 'transcribe', 'icon' => '&#x1F50A;', 'label' => 'Transcribe', 'threshold' => 20],
                                ['key' => 'translate', 'icon' => '&#x1F310;', 'label' => 'Translate', 'threshold' => 40],
                                ['key' => 'voice', 'icon' => '&#x1F3A4;', 'label' => 'Voice', 'threshold' => 55],
                                ['key' => 'mix', 'icon' => '&#x1F3B5;', 'label' => 'Mix', 'threshold' => 70],
                                ['key' => 'done', 'icon' => '&#x2705;', 'label' => 'Done', 'threshold' => 95],
                            ];
                        @endphp
                        @foreach($steps as $step)
                            <div class="step {{ $progress >= $step['threshold'] ? ($progress > $step['threshold'] ? 'done' : 'active') : '' }}"
                                 data-threshold="{{ $step['threshold'] }}">
                                <div class="step-icon">{!! $step['icon'] !!}</div>
                                {{ $step['label'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="footer">
            <a href="{{ route('dub.index') }}">Online Dubber</a> &middot;
            <a href="{{ route('videos.index') }}">Dashboard</a>
        </div>
    </div>

    @unless($ready || $failed)
    <script>
        const videoId = {{ $video->id }};
        const statusUrl = '/api/stream/' + videoId + '/status';
        const progressBar = document.getElementById('progressBar');
        const progressLabel = document.getElementById('progressLabel');
        const progressPercent = document.getElementById('progressPercent');
        const steps = document.querySelectorAll('.step');

        function updateSteps(progress) {
            steps.forEach(step => {
                const threshold = parseInt(step.dataset.threshold);
                step.classList.remove('active', 'done');
                if (progress > threshold) {
                    step.classList.add('done');
                } else if (progress >= threshold) {
                    step.classList.add('active');
                }
            });
        }

        async function poll() {
            try {
                const res = await fetch(statusUrl);
                if (!res.ok) return;
                const data = await res.json();

                progressBar.style.width = data.progress + '%';
                progressLabel.textContent = data.label;
                progressPercent.textContent = data.progress + '%';
                updateSteps(data.progress);

                if (data.ready) {
                    // Reload page to show the player
                    window.location.reload();
                    return;
                }

                if (data.status === 'failed' || data.status === 'download_failed') {
                    window.location.reload();
                    return;
                }
            } catch (e) {
                // Ignore fetch errors, will retry
            }

            setTimeout(poll, 3000);
        }

        setTimeout(poll, 3000);
    </script>
    @endunless
</body>
</html>
