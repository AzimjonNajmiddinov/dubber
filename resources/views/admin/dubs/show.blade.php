@extends('admin.layout')

@section('title', $dub->title ?: 'Dub #'.$dub->id)

@section('content')

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <a href="{{ route('admin.dubs.index') }}" class="btn btn-secondary btn-sm">← Back</a>
    <div style="display:flex;gap:8px;align-items:center">
        <span id="retts-status" style="font-size:0.8rem;color:#475569;display:none"></span>
        <button id="retts-btn" onclick="triggerReTts()"
            class="btn btn-primary"
            style="background:linear-gradient(135deg,#f59e0b,#d97706)"
            {{ $dub->status === 'processing' ? 'disabled' : '' }}>
            ⚡ Re-TTS{{ $dub->status === 'needs_retts' ? ' ('.$dub->segments->where('needs_retts',true)->count().' segments)' : ' All' }}
        </button>
    </div>
</div>

<div class="page-header">
    <div>
        <h1>{{ $dub->title ?: 'Untitled' }}</h1>
        <div style="font-size:0.78rem;color:#475569;margin-top:4px;max-width:640px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            {{ $dub->video_url }}
        </div>
    </div>
    <span class="badge badge-{{ $dub->status }}" id="dub-status-badge">{{ $dub->status }}</span>
</div>

{{-- Stats --}}
<div class="stats">
    <div class="stat">
        <div class="stat-label">Language</div>
        <div class="stat-value">{{ strtoupper($dub->language) }}</div>
        <div class="stat-sub">{{ $dub->translate_from ? 'from '.strtoupper($dub->translate_from) : 'no translation' }}</div>
    </div>
    <div class="stat">
        <div class="stat-label">Segments</div>
        <div class="stat-value">{{ $dub->segments->count() }}</div>
        <div class="stat-sub">
            @php
                $approved = $dub->segments->where('approved', true)->count();
                $needsRetts = $dub->segments->where('needs_retts', true)->count();
            @endphp
            {{ $approved }} approved · {{ $needsRetts }} pending
        </div>
    </div>
    <div class="stat">
        <div class="stat-label">TTS</div>
        <div class="stat-value" style="font-size:1rem;padding-top:4px">{{ $dub->tts_driver }}</div>
        <div class="stat-sub">updated {{ $dub->updated_at->diffForHumans() }}</div>
    </div>
    @php
        $overflowCount = $dub->segments->filter(function($s) {
            if (!$s->aac_duration) return false;
            $slot = $s->slot_end ? ($s->slot_end - $s->start_time) : ($s->end_time - $s->start_time);
            return $s->aac_duration > $slot + 0.15;
        })->count();
    @endphp
    @if($overflowCount > 0)
    <div class="stat" style="border-color:rgba(239,68,68,0.3)">
        <div class="stat-label" style="color:#fca5a5">Overflow</div>
        <div class="stat-value" style="color:#fca5a5">{{ $overflowCount }}</div>
        <div class="stat-sub">TTS longer than slot</div>
    </div>
    @endif
</div>

{{-- Voice Map Editor --}}
@if($dub->voiceMap->count())
<div class="card" style="margin-bottom:24px">
    <div style="font-weight:600;color:#f1f5f9;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between">
        <span>Voice Map</span>
        <button onclick="saveVoiceMap()" class="btn btn-primary btn-sm" id="voice-save-btn">Save voices</button>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap" id="voice-map-editor">
        @foreach($dub->voiceMap as $vm)
        <div style="background:#0a0a0f;border:1px solid #1e1e2e;border-radius:8px;padding:12px 16px;min-width:200px" data-speaker="{{ $vm->speaker_tag }}">
            <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;margin-bottom:8px">
                Speaker {{ $vm->speaker_tag }}
            </div>
            <select class="voice-select" style="width:100%">
                @php $current = is_array($vm->voice_config) ? json_encode($vm->voice_config) : $vm->voice_config; @endphp
                @foreach($voiceVariants as $opt)
                    <option value="{{ json_encode($opt['config']) }}"
                        {{ json_encode($opt['config']) === $current ? 'selected' : '' }}>
                        {{ $opt['label'] }}
                    </option>
                @endforeach
                <option value="{{ $current }}"
                    {{ !collect($voiceVariants)->contains(fn($o) => json_encode($o['config']) === $current) ? 'selected' : '' }}>
                    Current ({{ is_array($vm->voice_config) ? ($vm->voice_config['voice'] ?? '?') : $vm->voice_config }})
                </option>
            </select>
        </div>
        @endforeach
    </div>
    <div id="voice-save-msg" style="display:none;margin-top:10px;font-size:0.82rem;color:#86efac">✓ Voice map saved. Click Re-TTS to regenerate audio.</div>
</div>
@endif

{{-- Segments --}}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <span style="font-weight:600;color:#f1f5f9">Segments</span>
    <span style="font-size:0.78rem;color:#475569">Edits save automatically · click ▶ to preview audio</span>
</div>

<div id="save-toast" style="
    position:fixed;bottom:24px;right:24px;z-index:999;
    background:#13131a;border:1px solid rgba(34,197,94,0.3);
    border-radius:10px;padding:12px 18px;color:#86efac;font-size:0.875rem;
    display:none;align-items:center;gap:8px;
    box-shadow:0 8px 32px rgba(0,0,0,0.4)
">✓ Saved</div>

<div class="card" style="padding:0;overflow:hidden">
    <table>
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th style="width:90px">Time</th>
                <th style="width:70px">Speaker</th>
                <th>Source</th>
                <th>Translation</th>
                <th style="width:70px;text-align:center">Dur / Slot</th>
                <th style="width:50px;text-align:center">✓</th>
                <th style="width:44px;text-align:center">▶</th>
            </tr>
        </thead>
        <tbody>
            @foreach($dub->segments as $seg)
            @php
                $slotDur = $seg->slot_end ? ($seg->slot_end - $seg->start_time) : ($seg->end_time - $seg->start_time);
                $overflow = $seg->aac_duration && $seg->aac_duration > $slotDur + 0.15;
                $hasAudio = $seg->aac_path && file_exists($seg->aac_path)
                    || ($dub->aac_dir && file_exists($dub->aac_dir.'/'.$seg->segment_index.'.aac'));
            @endphp
            <tr data-seg="{{ $seg->id }}" data-dub="{{ $dub->id }}"
                style="{{ $overflow ? 'background:rgba(239,68,68,0.04)' : '' }}">
                <td style="color:#334155;font-size:0.8rem">{{ $seg->segment_index }}</td>
                <td style="font-size:0.75rem;color:#475569;white-space:nowrap;line-height:1.7">
                    {{ gmdate('H:i:s', (int)$seg->start_time) }}<br>
                    <span style="color:#334155">{{ gmdate('H:i:s', (int)$seg->end_time) }}</span>
                </td>
                <td>
                    <input type="text" class="speaker-input" value="{{ $seg->speaker }}"
                        style="width:58px;font-size:0.82rem;padding:4px 7px;text-align:center;font-weight:600">
                </td>
                <td style="font-size:0.8rem;color:#475569;max-width:200px;line-height:1.5">{{ $seg->source_text }}</td>
                <td>
                    <textarea class="translation-input" rows="2"
                        style="font-size:0.85rem;min-width:180px;line-height:1.5">{{ $seg->translated_text }}</textarea>
                </td>
                <td style="text-align:center;white-space:nowrap">
                    @if($seg->aac_duration)
                        <span style="font-size:0.78rem;color:{{ $overflow ? '#fca5a5' : '#64748b' }}">
                            {{ number_format($seg->aac_duration, 1) }}s
                            @if($overflow)
                                <br><span style="font-size:0.7rem;color:#ef4444">⚠ +{{ number_format($seg->aac_duration - $slotDur, 1) }}s</span>
                            @endif
                        </span>
                    @else
                        <span style="color:#334155;font-size:0.8rem">—</span>
                    @endif
                </td>
                <td style="text-align:center">
                    <input type="checkbox" class="approved-cb"
                        {{ $seg->approved ? 'checked' : '' }}
                        style="width:16px;height:16px;accent-color:#6366f1;cursor:pointer"
                        title="Mark as approved">
                </td>
                <td style="text-align:center">
                    @if($hasAudio)
                    <button onclick="playSegment({{ $dub->id }}, {{ $seg->id }}, this)"
                        style="background:none;border:none;cursor:pointer;font-size:1rem;color:#64748b;padding:4px"
                        title="Preview audio">▶</button>
                    @else
                    <span style="color:#1e293b;font-size:0.8rem">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Hidden audio player --}}
<audio id="seg-player" style="display:none" onended="playerEnded()"></audio>

<script>
const token  = document.querySelector('meta[name=csrf-token]').content;
const dubId  = {{ $dub->id }};
const toast  = document.getElementById('save-toast');
let toastTimer, activePlayBtn;

// ── Audio preview ──────────────────────────────────────────────────────────
const player = document.getElementById('seg-player');

function playSegment(dubId, segId, btn) {
    const url = `/admin/dubs/${dubId}/segments/${segId}/audio`;
    if (activePlayBtn) { activePlayBtn.textContent = '▶'; activePlayBtn.style.color = '#64748b'; }
    if (player.dataset.seg === String(segId) && !player.paused) {
        player.pause();
        activePlayBtn = null;
        return;
    }
    player.src = url;
    player.dataset.seg = segId;
    player.play();
    activePlayBtn = btn;
    btn.textContent = '■';
    btn.style.color = '#a5b4fc';
}

function playerEnded() {
    if (activePlayBtn) { activePlayBtn.textContent = '▶'; activePlayBtn.style.color = '#64748b'; }
    activePlayBtn = null;
}

// ── Auto-save segment edits ────────────────────────────────────────────────
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

    row.querySelectorAll('input.speaker-input, textarea').forEach(el => {
        el.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(save, 700); });
        el.addEventListener('blur',  () => { clearTimeout(debounce); save(); });
    });

    const cb = row.querySelector('.approved-cb');
    cb.addEventListener('change', () => {
        fetch(url, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ approved: cb.checked }),
        });
    });
});

// ── Voice map save ─────────────────────────────────────────────────────────
function saveVoiceMap() {
    const voices = [];
    document.querySelectorAll('#voice-map-editor [data-speaker]').forEach(el => {
        voices.push({
            speaker: el.dataset.speaker,
            config: JSON.parse(el.querySelector('.voice-select').value),
        });
    });

    document.getElementById('voice-save-btn').disabled = true;

    fetch(`/admin/dubs/${dubId}/voice-map`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ voices }),
    }).then(r => r.json()).then(() => {
        document.getElementById('voice-save-msg').style.display = 'block';
        document.getElementById('voice-save-btn').disabled = false;
    });
}

// ── Re-TTS trigger ────────────────────────────────────────────────────────
function triggerReTts() {
    if (!confirm('This will re-generate TTS audio for all edited segments. Continue?')) return;

    const btn = document.getElementById('retts-btn');
    btn.disabled = true;
    btn.textContent = '⏳ Starting…';

    fetch(`/admin/dubs/${dubId}/retts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            btn.textContent = '⏳ Processing…';
            document.getElementById('retts-status').style.display = 'inline';
            pollRettsStatus();
        }
    });
}

function pollRettsStatus() {
    fetch(`/admin/dubs/${dubId}/retts-status`)
        .then(r => r.json())
        .then(d => {
            const statusEl = document.getElementById('retts-status');
            const btn = document.getElementById('retts-btn');
            if (d.total > 0) {
                statusEl.textContent = `${d.ready}/${d.total} segments`;
            }
            if (d.status === 'complete') {
                btn.textContent = '✓ Done — reload to see updates';
                btn.disabled = false;
                statusEl.textContent = '';
            } else if (d.status === 'error') {
                btn.textContent = '⚠ Error';
                btn.disabled = false;
            } else {
                setTimeout(pollRettsStatus, 2000);
            }
        });
}
</script>
@endsection
