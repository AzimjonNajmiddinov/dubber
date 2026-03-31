@extends('admin.layout')

@section('title', $dub->title ?: 'Dub #'.$dub->id)

@section('content')
<div style="margin-bottom:16px">
    <a href="{{ route('admin.dubs.index') }}" style="color:#718096;font-size:0.9rem">&larr; Back</a>
</div>

<div class="page-title">{{ $dub->title ?: 'Untitled' }}</div>

<div style="display:flex;gap:24px;margin-bottom:24px;flex-wrap:wrap">
    <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:8px;padding:16px;min-width:220px">
        <div style="color:#718096;font-size:0.8rem;margin-bottom:4px">Status</div>
        <span class="badge badge-{{ $dub->status }}">{{ $dub->status }}</span>
    </div>
    <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:8px;padding:16px;min-width:220px">
        <div style="color:#718096;font-size:0.8rem;margin-bottom:4px">Language / TTS</div>
        <div>{{ strtoupper($dub->language) }} &bull; {{ $dub->tts_driver }}</div>
    </div>
    <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:8px;padding:16px;min-width:220px">
        <div style="color:#718096;font-size:0.8rem;margin-bottom:4px">Segments</div>
        <div>{{ $dub->segments->count() }} / {{ $dub->total_segments }}</div>
    </div>
    <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:8px;padding:16px;flex:1;min-width:300px">
        <div style="color:#718096;font-size:0.8rem;margin-bottom:4px">Video URL</div>
        <div style="font-size:0.8rem;word-break:break-all;color:#a0aec0">{{ $dub->video_url }}</div>
    </div>
</div>

@if($dub->voiceMap->count())
<div style="margin-bottom:24px">
    <div style="font-weight:600;margin-bottom:10px">Voice Map</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        @foreach($dub->voiceMap as $vm)
        <div style="background:#1a1f2e;border:1px solid #2d3748;border-radius:6px;padding:8px 14px;font-size:0.85rem">
            <span style="color:#63b3ed;font-weight:600">{{ $vm->speaker_tag }}</span>
            <span style="color:#718096;margin-left:8px">{{ is_array($vm->voice_config) ? ($vm->voice_config['voice'] ?? json_encode($vm->voice_config)) : $vm->voice_config }}</span>
        </div>
        @endforeach
    </div>
</div>
@endif

<div style="font-weight:600;margin-bottom:12px">Segments <span style="color:#718096;font-weight:400;font-size:0.85rem">(click text to edit)</span></div>

<div id="save-msg" style="display:none;position:fixed;bottom:20px;right:20px;background:#22543d;color:#9ae6b4;padding:10px 18px;border-radius:8px;font-size:0.9rem;z-index:999">Saved</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Time</th>
            <th>Speaker</th>
            <th>Source</th>
            <th>Translation</th>
            <th>Needs re-TTS</th>
        </tr>
    </thead>
    <tbody>
        @foreach($dub->segments as $seg)
        <tr data-seg="{{ $seg->id }}" data-dub="{{ $dub->id }}">
            <td style="color:#718096">{{ $seg->segment_index }}</td>
            <td style="font-size:0.8rem;color:#718096;white-space:nowrap">
                {{ gmdate('H:i:s', (int)$seg->start_time) }}<br>
                <span style="color:#4a5568">→ {{ gmdate('H:i:s', (int)$seg->end_time) }}</span>
            </td>
            <td>
                <input type="text" class="speaker-input" value="{{ $seg->speaker }}" style="width:60px;font-size:0.85rem;padding:4px 8px">
            </td>
            <td style="font-size:0.85rem;color:#a0aec0;max-width:220px">{{ $seg->source_text }}</td>
            <td style="max-width:280px">
                <textarea class="translation-input" rows="2" style="font-size:0.85rem">{{ $seg->translated_text }}</textarea>
            </td>
            <td style="text-align:center">
                @if($seg->needs_retts)
                    <span class="badge badge-needs_retts">yes</span>
                @else
                    <span style="color:#4a5568">—</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

<script>
const meta = document.querySelector('meta[name=csrf-token]');
const token = meta ? meta.getAttribute('content') : '{{ csrf_token() }}';

let saveTimer = null;
let saveMsg = document.getElementById('save-msg');

function flashSaved() {
    saveMsg.style.display = 'block';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => saveMsg.style.display = 'none', 2000);
}

document.querySelectorAll('tr[data-seg]').forEach(row => {
    const segId = row.dataset.seg;
    const dubId = row.dataset.dub;
    const url = `/admin/dubs/${dubId}/segments/${segId}`;

    function save() {
        const speaker = row.querySelector('.speaker-input').value.trim();
        const text = row.querySelector('.translation-input').value;
        fetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ speaker, translated_text: text }),
        }).then(r => r.json()).then(d => { if (d.ok && d.changed) flashSaved(); });
    }

    let debounce = null;
    row.querySelectorAll('input,textarea').forEach(el => {
        el.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(save, 800);
        });
        el.addEventListener('blur', () => {
            clearTimeout(debounce);
            save();
        });
    });
});
</script>
@endsection
