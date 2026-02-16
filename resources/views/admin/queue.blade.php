<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="10">
    <title>Queue Monitor</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #e0e0e0; padding: 1.5rem; }
        a { color: #a8d8ea; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .nav { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; align-items: center; }
        .nav h1 { font-size: 1.25rem; color: #a8d8ea; margin-right: auto; }
        .status-msg { background: #1a3a2e; color: #a8e6cf; padding: 0.5rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .card { background: #16213e; padding: 1.25rem; border-radius: 8px; text-align: center; }
        .card .number { font-size: 2rem; font-weight: bold; }
        .card .label { font-size: 0.8rem; color: #8899aa; margin-top: 0.25rem; }
        .card.pending .number { color: #ffd93d; }
        .card.failed .number { color: #ff6b6b; }
        .card.batches .number { color: #a8d8ea; }
        h2 { font-size: 1rem; color: #8899aa; margin-bottom: 0.75rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; background: #16213e; border-radius: 8px; overflow: hidden; }
        th, td { padding: 0.6rem 0.75rem; text-align: left; font-size: 0.85rem; }
        th { background: #0f3460; color: #a8d8ea; font-weight: 600; }
        tr:not(:last-child) td { border-bottom: 1px solid #2a3a5c; }
        .btn { display: inline-block; padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.75rem; border: none; cursor: pointer; color: #fff; }
        .btn-retry { background: #533483; }
        .btn-retry:hover { background: #6a42a0; }
        .btn-danger { background: #8b1a1a; }
        .btn-danger:hover { background: #a52a2a; }
        .btn-sm { font-size: 0.7rem; padding: 0.2rem 0.5rem; }
        .actions-row { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .progress-bar { background: #2a3a5c; border-radius: 4px; overflow: hidden; height: 18px; }
        .progress-fill { background: #533483; height: 100%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; min-width: fit-content; padding: 0 4px; }
        .exception { max-width: 400px; max-height: 80px; overflow: auto; font-family: monospace; font-size: 0.75rem; color: #ff9a9a; white-space: pre-wrap; word-break: break-all; }
        .empty { color: #556; text-align: center; padding: 2rem; }
    </style>
</head>
<body>
    <div class="nav">
        <h1>Queue Monitor</h1>
        <a href="{{ url('/admin/logs') }}">Logs</a>
        <a href="{{ url('/admin/queue') }}">Queue</a>
        <form method="POST" action="{{ url('/admin/logout') }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-danger">Logout</button>
        </form>
    </div>

    @if(session('status'))
        <div class="status-msg">{{ session('status') }}</div>
    @endif

    <div class="cards">
        <div class="card pending">
            <div class="number">{{ $pendingJobs }}</div>
            <div class="label">Pending Jobs</div>
        </div>
        <div class="card failed">
            <div class="number">{{ $failedJobs }}</div>
            <div class="label">Failed Jobs</div>
        </div>
        <div class="card batches">
            <div class="number">{{ $batches->count() }}</div>
            <div class="label">Job Batches</div>
        </div>
    </div>

    {{-- Queue Breakdown --}}
    @if(count($pendingByQueue) > 0)
        <h2>Queue Breakdown</h2>
        <table>
            <thead><tr><th>Queue</th><th>Pending</th></tr></thead>
            <tbody>
                @foreach($pendingByQueue as $queue => $count)
                    <tr><td>{{ $queue }}</td><td>{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Failed Jobs --}}
    <h2>Failed Jobs</h2>
    @if($failedJobs > 0)
        <div class="actions-row">
            <form method="POST" action="{{ url('/admin/queue/retry-all') }}">
                @csrf
                <button class="btn btn-retry btn-sm">Retry All</button>
            </form>
            <form method="POST" action="{{ url('/admin/queue/flush') }}" onsubmit="return confirm('Flush all failed jobs?')">
                @csrf
                <button class="btn btn-danger btn-sm">Flush All</button>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Queue</th>
                    <th>Job</th>
                    <th>Failed At</th>
                    <th>Exception</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($failedJobsList as $job)
                    <tr>
                        <td>{{ $job->id }}</td>
                        <td>{{ $job->queue ?? '-' }}</td>
                        <td>{{ \Illuminate\Support\Str::afterLast(json_decode($job->payload, true)['displayName'] ?? '-', '\\') }}</td>
                        <td>{{ $job->failed_at }}</td>
                        <td><div class="exception">{{ \Illuminate\Support\Str::limit($job->exception, 200) }}</div></td>
                        <td style="white-space:nowrap">
                            <form method="POST" action="{{ url('/admin/queue/retry/'.$job->uuid) }}" style="display:inline">
                                @csrf
                                <button class="btn btn-retry btn-sm">Retry</button>
                            </form>
                            <form method="POST" action="{{ url('/admin/queue/delete/'.$job->uuid) }}" style="display:inline" onsubmit="return confirm('Delete this job?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="empty">No failed jobs.</div>
    @endif

    {{-- Job Batches --}}
    @if($batches->count() > 0)
        <h2>Job Batches</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Total</th>
                    <th>Pending</th>
                    <th>Failed</th>
                    <th>Progress</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($batches as $batch)
                    @php
                        $total = $batch->total_jobs;
                        $pending = $batch->pending_jobs;
                        $failed = $batch->failed_jobs;
                        $processed = $total - $pending;
                        $pct = $total > 0 ? round(($processed / $total) * 100) : 0;
                    @endphp
                    <tr>
                        <td style="font-family:monospace;font-size:0.75rem">{{ \Illuminate\Support\Str::limit($batch->id, 8, '...') }}</td>
                        <td>{{ $batch->name ?? '-' }}</td>
                        <td>{{ $total }}</td>
                        <td>{{ $pending }}</td>
                        <td style="color:{{ $failed > 0 ? '#ff6b6b' : 'inherit' }}">{{ $failed }}</td>
                        <td style="min-width:120px">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width:{{ $pct }}%">{{ $pct }}%</div>
                            </div>
                        </td>
                        <td>{{ \Carbon\Carbon::createFromTimestamp($batch->created_at)->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
