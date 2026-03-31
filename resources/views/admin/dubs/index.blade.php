@extends('admin.layout')

@section('title', 'Dubs')

@section('content')
<div class="page-header">
    <h1>Dubbed Videos</h1>
    <span style="color:#475569;font-size:0.875rem">{{ $dubs->total() }} total</span>
</div>

<form method="GET" action="{{ route('admin.dubs.index') }}">
    <div class="filters">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search title or URL…" style="width:240px">
        <select name="lang" style="width:130px">
            <option value="">All languages</option>
            @foreach(['uz','ru','en','tr','kk'] as $l)
                <option value="{{ $l }}" @selected(request('lang') === $l)>{{ strtoupper($l) }}</option>
            @endforeach
        </select>
        <select name="status" style="width:150px">
            <option value="">All statuses</option>
            @foreach(['complete','needs_retts','processing','error'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">Search</button>
        @if(request('q') || request('lang') || request('status'))
            <a href="{{ route('admin.dubs.index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </div>
</form>

<div class="card" style="padding:0;overflow:hidden">
    <table>
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th>Title</th>
                <th style="width:70px">Lang</th>
                <th style="width:120px">Status</th>
                <th style="width:90px">Segments</th>
                <th style="width:80px">TTS</th>
                <th style="width:110px">Updated</th>
                <th style="width:60px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($dubs as $dub)
            <tr>
                <td style="color:#334155;font-size:0.8rem">{{ $dub->id }}</td>
                <td>
                    <a href="{{ route('admin.dubs.show', $dub) }}" style="font-weight:500;color:#c7d2fe">
                        {{ $dub->title ?: 'Untitled' }}
                    </a>
                    <div style="font-size:0.75rem;color:#334155;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:320px">
                        {{ $dub->video_url }}
                    </div>
                </td>
                <td>
                    <span style="font-size:0.78rem;font-weight:700;color:#64748b;letter-spacing:0.05em">{{ strtoupper($dub->language) }}</span>
                </td>
                <td><span class="badge badge-{{ $dub->status }}">{{ $dub->status }}</span></td>
                <td style="color:#64748b;font-size:0.85rem">
                    {{ $dub->segments_count }}<span style="color:#334155">/{{ $dub->total_segments }}</span>
                </td>
                <td style="color:#475569;font-size:0.8rem">{{ $dub->tts_driver }}</td>
                <td style="color:#334155;font-size:0.8rem">{{ $dub->updated_at->diffForHumans() }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.dubs.destroy', $dub) }}" onsubmit="return confirm('Delete this dub and all its audio?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">Del</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="text-align:center;padding:60px;color:#334155">
                    <div style="font-size:2rem;margin-bottom:8px">🎬</div>
                    No dubs found.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px">
    <span style="font-size:0.8rem;color:#475569">
        Showing {{ $dubs->firstItem() }}–{{ $dubs->lastItem() }} of {{ $dubs->total() }}
    </span>
    <div style="display:flex;gap:6px">
        @if($dubs->onFirstPage())
            <span class="btn btn-secondary btn-sm" style="opacity:0.4;cursor:default">← Prev</span>
        @else
            <a href="{{ $dubs->previousPageUrl() }}" class="btn btn-secondary btn-sm">← Prev</a>
        @endif
        <span style="padding:5px 12px;background:#1e1e2e;border-radius:8px;font-size:0.8rem;color:#94a3b8">
            {{ $dubs->currentPage() }} / {{ $dubs->lastPage() }}
        </span>
        @if($dubs->hasMorePages())
            <a href="{{ $dubs->nextPageUrl() }}" class="btn btn-secondary btn-sm">Next →</a>
        @else
            <span class="btn btn-secondary btn-sm" style="opacity:0.4;cursor:default">Next →</span>
        @endif
    </div>
</div>
@endsection
