@extends('admin.layout')

@section('title', $dub->title ?: 'Dub #'.$dub->id)

@section('content')

<div style="margin-bottom:20px">
    <a href="{{ route('admin.dubs.index') }}" class="btn btn-secondary btn-sm">← Back to dubs</a>
</div>

<div class="page-header">
    <div>
        <h1>{{ $dub->title ?: 'Untitled' }}</h1>
        <div style="font-size:0.8rem;color:#475569;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:600px">
            {{ $dub->video_url }}
        </div>
    </div>
    <span class="badge badge-{{ $dub->status }}" style="font-size:0.8rem;padding:5px 12px">{{ $dub->status }}</span>
</div>

<div class="stats">
    <div class="stat">
        <div class="stat-label">Language</div>
        <div class="stat-value">{{ strtoupper($dub->language) }}</div>
        <div class="stat-sub">{{ $dub->translate_from ? 'from '.strtoupper($dub->translate_from) : 'no translation' }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">Segments</div>
        <div class="stat-value">{{ $dub->segments->count() }}</div>
        <div class="stat-sub">of {{ $dub->total_segments }} total</div>
    </div>
    <div class="stat">
        <div class="stat-label">TTS engine</div>
        <div class="stat-value" style="font-size:1rem;padding-top:4px">{{ $dub->tts_driver }}</div>
        <div class="stat-sub">updated {{ $dub->updated_at->diffForHumans() }}</div>
    </div>
    @if($dub->voiceMap->count())
    <div class="stat" style="flex:2">
        <div class="stat-label">Voice map</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
            @foreach($dub->voiceMap as $vm)
            <div style="background:#0a0a0f;border:1px solid #1e1e2e;border-radius:6px;padding:4px 10px;font-size:0.8rem;white-space:nowrap">
                <span style="color:#a5b4fc;font-weight:700">{{ $vm->speaker_tag }}</span>
                <span style="color:#475569;margin-left:6px">{{ is_array($vm->voice_config) ? ($vm->voice_config['voice'] ?? json_encode($vm->voice_config)) : $vm->voice_config }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <div>
        <span style="font-weight:600;color:#f1f5f9">Segments</span>
        <span style="color:#475569;font-size:0.8rem;margin-left:8px">Edits save automatically</span>
    </div>
    @php $needsRetts = $dub->segments->where('needs_retts', true)->count(); @endphp
    @if($needsRetts > 0)
        <span class="badge badge-needs_retts">{{ $needsRetts }} pending re-TTS</span>
    @endif
</div>

<div id="save-toast" style="
    display:none;position:fixed;bottom:24px;right:24px;z-index:999;
    background:#13131a;border:1px solid rgba(34,197,94,0.3);border-radius:10px;
    padding:12px 18px;color:#86efac;font-size:0.875rem;
    display:none;align-items:center;gap:8px;
    box-shadow:0 8px 32px rgba(0,0,0,0.4)
">✓ Saved</div>

<div class="card" style="padding:0;overflow:hidden">
    <table>
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th style="width:100px">Time</th>
                <th style="width:80px">Speaker</th>
                <th>Source text</th>
                <th>Translation</th>
                <th style="width:80px;text-align:center">Re-TTS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dub->segments as $seg)
            <tr data-seg="{{ $seg->id }}" data-dub="{{ $dub->id }}">
                <td style="color:#334155;font-size:0.8rem">{{ $seg->segment_index }}</td>
                <td style="font-size:0.78rem;color:#475569;white-space:nowrap;line-height:1.6">
                    {{ gmdate('H:i:s', (int)$seg->start_time) }}<br>
                    <span style="color:#334155">{{ gmdate('H:i:s', (int)$seg->end_time) }}</span>
                </td>
                <td>
                    <input type="text" class="speaker-input" value="{{ $seg->speaker }}"
                        style="width:68px;font-size:0.82rem;padding:5px 8px;text-align:center;font-weight:600">
                </td>
                <td style="font-size:0.82rem;color:#475569;max-width:240px;line-height:1.5">
                    {{ $seg->source_text }}
                </td>
                <td>
                    <textarea class="translation-input" rows="2"
                        style="font-size:0.85rem;min-width:200px;line-height:1.5">{{ $seg->translated_text }}</textarea>
                </td>
                <td style="text-align:center">
                    @if($seg->needs_retts)
                        <span class="badge badge-needs_retts" style="font-size:0.7rem">yes</span>
                    @else
                        <span style="color:#1e293b;font-size:1rem">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<script>
const token = document.querySelector('meta[name=csrf-token]').content;
const toast = document.getElementById('save-toast');
let toastTimer;

function showToast() {
    toast.style.display = 'flex';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.style.display = 'none', 2500);
}

document.querySelectorAll('tr[data-seg]').forEach(row => {
    const url = `/admin/dubs/${row.dataset.dub}/segments/${row.dataset.seg}`;
    let debounce;

    function save() {
        fetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({
                speaker: row.querySelector('.speaker-input').value.trim(),
                translated_text: row.querySelector('.translation-input').value,
            }),
        }).then(r => r.json()).then(d => { if (d.ok && d.changed) showToast(); });
    }

    row.querySelectorAll('input, textarea').forEach(el => {
        el.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(save, 700); });
        el.addEventListener('blur',  () => { clearTimeout(debounce); save(); });
    });
});
</script>
@endsection
