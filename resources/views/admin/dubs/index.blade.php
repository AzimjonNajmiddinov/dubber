@extends('admin.layout')

@section('title', 'Dubs')

@section('content')
<div class="page-title">Dubbed Videos</div>

<form method="GET" action="{{ route('admin.dubs.index') }}">
    <div class="filters">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search title or URL..." style="width:260px">
        <select name="lang">
            <option value="">All languages</option>
            @foreach(['uz','ru','en','tr','kk'] as $l)
                <option value="{{ $l }}" @selected(request('lang') === $l)>{{ strtoupper($l) }}</option>
            @endforeach
        </select>
        <select name="status">
            <option value="">All statuses</option>
            @foreach(['complete','needs_retts','processing','error'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Filter</button>
        @if(request('q') || request('lang') || request('status'))
            <a href="{{ route('admin.dubs.index') }}" class="btn" style="background:#2d3748;color:#e2e8f0">Clear</a>
        @endif
    </div>
</form>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Title</th>
            <th>Lang</th>
            <th>Status</th>
            <th>Segments</th>
            <th>TTS</th>
            <th>Updated</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse($dubs as $dub)
        <tr>
            <td style="color:#718096">{{ $dub->id }}</td>
            <td>
                <a href="{{ route('admin.dubs.show', $dub) }}">{{ $dub->title ?: 'Untitled' }}</a>
                <div style="font-size:0.75rem;color:#718096;margin-top:2px;word-break:break-all">{{ Str::limit($dub->video_url, 60) }}</div>
            </td>
            <td><span class="badge" style="background:#2d3748;color:#e2e8f0">{{ strtoupper($dub->language) }}</span></td>
            <td><span class="badge badge-{{ $dub->status }}">{{ $dub->status }}</span></td>
            <td>{{ $dub->segments_count }}/{{ $dub->total_segments }}</td>
            <td style="color:#718096">{{ $dub->tts_driver }}</td>
            <td style="color:#718096;font-size:0.8rem">{{ $dub->updated_at->diffForHumans() }}</td>
            <td>
                <form method="POST" action="{{ route('admin.dubs.destroy', $dub) }}" onsubmit="return confirm('Delete this dub?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-sm">Del</button>
                </form>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="8" style="color:#718096;text-align:center;padding:40px">No dubs found.</td>
        </tr>
        @endforelse
    </tbody>
</table>

<div style="margin-top:20px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
    @if($dubs->onFirstPage())
        <span style="padding:6px 12px;border:1px solid #2d3748;border-radius:6px;color:#4a5568;font-size:0.85rem">&laquo; Prev</span>
    @else
        <a href="{{ $dubs->previousPageUrl() }}" style="padding:6px 12px;border:1px solid #2d3748;border-radius:6px;font-size:0.85rem">&laquo; Prev</a>
    @endif

    <span style="padding:6px 12px;border:1px solid #4299e1;border-radius:6px;background:#4299e1;color:#fff;font-size:0.85rem">
        Page {{ $dubs->currentPage() }} of {{ $dubs->lastPage() }}
    </span>

    @if($dubs->hasMorePages())
        <a href="{{ $dubs->nextPageUrl() }}" style="padding:6px 12px;border:1px solid #2d3748;border-radius:6px;font-size:0.85rem">Next &raquo;</a>
    @else
        <span style="padding:6px 12px;border:1px solid #2d3748;border-radius:6px;color:#4a5568;font-size:0.85rem">Next &raquo;</span>
    @endif
</div>
@endsection
