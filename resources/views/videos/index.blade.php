<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Movie Dubber</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
body { font-family: ui-sans-serif, system-ui, -apple-system; margin: 24px; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
.row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .muted { color: #666; font-size: 13px; }
    .btn { display: inline-block; padding: 8px 12px; border-radius: 8px; border: 1px solid #222; text-decoration: none; color: #111; background:#fff; }
.btn-primary { background: #111; color: #fff; }
    .btn-disabled { opacity: .45; pointer-events: none; }
        .bar { width: 260px; height: 10px; background: #eee; border-radius: 999px; overflow: hidden; }
    .bar > div { height: 10px; background: #111; width: 0%; }
    table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
</style>
</head>
<body>

@if(session('success'))
    <div class="card" style="border-color:#bde5bd;background:#f3fff3;">
        {{ session('success') }}
    </div>
@endif

<div class="card">
    <h2 style="margin-top:0;">Upload video</h2>
    <form action="{{ route('videos.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <input type="file" name="video" required>
            <select name="target_language" required>
                <option value="Uzbek">Uzbek</option>
                <option value="English">English</option>
                <option value="Russian">Russian</option>
            </select>
            <button class="btn btn-primary" type="submit">Upload & Start</button>
        </div>
        @error('video') <div class="muted">{{ $message }}</div> @enderror
        @error('target_language') <div class="muted">{{ $message }}</div> @enderror
    </form>
    <p class="muted" style="margin-bottom:0;">Tip: for best quality keep videos ≤ 5 minutes.</p>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent videos</h2>

    @if($videos->isEmpty())
        <p class="muted">No uploads yet.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Target</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @foreach($videos as $v)
                <tr data-video-id="{{ $v->id }}">
                    <td>#{{ $v->id }}</td>
                    <td>{{ $v->target_language ?? '-' }}</td>
                    <td>
                        <div class="status-label">{{ $v->status }}</div>
                        <div class="muted">Updated: {{ $v->updated_at }}</div>
                    </td>
                    <td>
                        <div class="bar"><div class="bar-fill"></div></div>
                        <div class="muted progress-text">0%</div>
                    </td>
                    <td>
                        <a class="btn" href="{{ route('videos.show', $v) }}">View</a>
                        <a class="btn btn-download btn-disabled" href="#">Dubbed</a>
                        <a class="btn btn-primary btn-download-lipsynced btn-disabled" href="#">Lipsynced</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<script>
    function updateRow(row, data) {
        row.querySelector('.status-label').textContent = data.label || data.status;
        const fill = row.querySelector('.bar-fill');
        const pct = Math.max(0, Math.min(100, data.progress || 0));
        fill.style.width = pct + '%';
        row.querySelector('.progress-text').textContent = pct + '%';

        // Dubbed download button
        const btn = row.querySelector('.btn-download');
        if (data.can_download && data.download_url) {
            btn.classList.remove('btn-disabled');
            btn.href = data.download_url;
        } else {
            btn.classList.add('btn-disabled');
            btn.href = '#';
        }

        // Lipsynced download button
        const btnLipsync = row.querySelector('.btn-download-lipsynced');
        if (data.download_lipsynced_url && data.lipsynced_path) {
            btnLipsync.classList.remove('btn-disabled');
            btnLipsync.href = data.download_lipsynced_url;
        } else {
            btnLipsync.classList.add('btn-disabled');
            btnLipsync.href = '#';
        }
    }

    async function poll() {
        const rows = document.querySelectorAll('tr[data-video-id]');
        for (const row of rows) {
            const id = row.getAttribute('data-video-id');
            try {
                const res = await fetch(`/videos/${id}/status`, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) continue;
                const data = await res.json();
                updateRow(row, data);
            } catch (e) {}
        }
    }

    poll();
    setInterval(poll, 2000);
</script>

</body>
</html>
