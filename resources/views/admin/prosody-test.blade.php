@extends('admin.layout')
@section('title', 'Prosody Transfer Test')

@section('content')
<style>
    .pt-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    @media (max-width: 800px) { .pt-grid { grid-template-columns: 1fr; } }

    .upload-zone {
        border: 2px dashed #2d2d3e;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
        position: relative;
    }
    .upload-zone:hover, .upload-zone.dragover { border-color: #6366f1; background: rgba(99,102,241,0.05); }
    .upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .upload-zone .uz-icon { font-size: 2rem; margin-bottom: 8px; }
    .upload-zone .uz-label { font-size: 0.875rem; color: #64748b; }
    .upload-zone .uz-name { margin-top: 8px; font-size: 0.8rem; color: #a5b4fc; font-weight: 600; word-break: break-all; }
    .upload-zone audio { margin-top: 12px; width: 100%; }

    .options-row {
        display: flex;
        align-items: center;
        gap: 24px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .opt-group { display: flex; align-items: center; gap: 8px; }
    .opt-group label { font-size: 0.875rem; color: #94a3b8; }

    .result-box {
        background: #0d0d14;
        border: 1px solid #1e1e2e;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        min-height: 100px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }
    .result-box audio { width: 100%; max-width: 480px; }
    .result-label { font-size: 0.8rem; color: #475569; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; }

    .spinner {
        width: 28px; height: 28px;
        border: 3px solid #2d2d3e;
        border-top-color: #6366f1;
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
        display: none;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .err-box {
        background: rgba(239,68,68,0.08);
        border: 1px solid rgba(239,68,68,0.2);
        border-radius: 10px;
        padding: 12px 16px;
        color: #fca5a5;
        font-size: 0.85rem;
        display: none;
        margin-top: 12px;
        text-align: left;
    }
</style>

<div class="page-header">
    <h1>Prosody Transfer Test</h1>
    <span style="font-size:0.8rem;color:#475569">WORLD vocoder — F0 + energy ko'chirish</span>
</div>

<form id="ptForm">
    @csrf
    <div class="pt-grid">
        {{-- TTS audio --}}
        <div>
            <div style="font-size:0.8rem;color:#64748b;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
                TTS Audio (manba)
            </div>
            <div class="upload-zone" id="zone-tts">
                <input type="file" name="tts_audio" id="tts_audio" accept="audio/*">
                <div class="uz-icon">🎤</div>
                <div class="uz-label">MMS TTS chiqargan ovoz</div>
                <div class="uz-name" id="tts-name"></div>
                <audio id="tts-preview" controls style="display:none"></audio>
            </div>
        </div>

        {{-- Reference audio --}}
        <div>
            <div style="font-size:0.8rem;color:#64748b;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
                Reference (original aktyor ovozi)
            </div>
            <div class="upload-zone" id="zone-ref">
                <input type="file" name="reference" id="reference" accept="audio/*">
                <div class="uz-icon">🎬</div>
                <div class="uz-label">Ruscha original aktyor ovozi</div>
                <div class="uz-name" id="ref-name"></div>
                <audio id="ref-preview" controls style="display:none"></audio>
            </div>
        </div>
    </div>

    <div class="options-row">
        <div class="opt-group">
            <label for="f0_mode">F0 mode:</label>
            <select name="f0_mode" id="f0_mode" style="min-width:120px">
                <option value="contour">contour (emotsiya)</option>
                <option value="stats">stats (yumshoq)</option>
            </select>
        </div>
        <div class="opt-group">
            <label>
                <input type="checkbox" name="energy_transfer" id="energy_transfer" checked style="margin-right:6px">
                Energy ko'chirish
            </label>
        </div>
        <button type="submit" class="btn btn-primary" id="submitBtn">
            Transfer
        </button>
        <div class="spinner" id="spinner"></div>
    </div>
</form>

<div class="result-box" id="resultBox">
    <div class="result-label">Natija shu yerda paydo bo'ladi</div>
</div>
<div class="err-box" id="errBox"></div>

<script>
    // File preview
    function setupZone(inputId, nameId, previewId) {
        document.getElementById(inputId).addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            document.getElementById(nameId).textContent = file.name;
            const url = URL.createObjectURL(file);
            const audio = document.getElementById(previewId);
            audio.src = url;
            audio.style.display = 'block';
        });
    }
    setupZone('tts_audio', 'tts-name', 'tts-preview');
    setupZone('reference', 'ref-name', 'ref-preview');

    // Drag-over styling
    ['zone-tts', 'zone-ref'].forEach(id => {
        const z = document.getElementById(id);
        z.addEventListener('dragover', e => { e.preventDefault(); z.classList.add('dragover'); });
        z.addEventListener('dragleave', () => z.classList.remove('dragover'));
        z.addEventListener('drop', () => z.classList.remove('dragover'));
    });

    // Submit
    document.getElementById('ptForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const tts = document.getElementById('tts_audio').files[0];
        const ref = document.getElementById('reference').files[0];
        if (!tts || !ref) {
            showErr('Ikkala audio faylni ham tanlang.');
            return;
        }

        setLoading(true);
        clearResult();

        const fd = new FormData();
        fd.append('_token', document.querySelector('[name=_token]').value);
        fd.append('tts_audio', tts);
        fd.append('reference', ref);
        fd.append('f0_mode', document.getElementById('f0_mode').value);
        fd.append('energy_transfer', document.getElementById('energy_transfer').checked ? '1' : '0');

        try {
            const resp = await fetch('{{ route("admin.prosody-test.transfer") }}', {
                method: 'POST',
                body: fd,
            });

            if (!resp.ok) {
                const j = await resp.json().catch(() => ({ error: resp.statusText }));
                showErr((j.detail || j.error || 'Xatolik: ' + resp.status));
                return;
            }

            const blob = await resp.blob();
            const url = URL.createObjectURL(blob);
            const box = document.getElementById('resultBox');
            box.innerHTML = `
                <div class="result-label">Natija</div>
                <audio controls autoplay src="${url}" style="width:100%;max-width:480px"></audio>
                <a href="${url}" download="prosody_result.wav" class="btn btn-secondary btn-sm">
                    Yuklab olish
                </a>`;
        } catch (err) {
            showErr('Network xatoligi: ' + err.message);
        } finally {
            setLoading(false);
        }
    });

    function setLoading(on) {
        document.getElementById('submitBtn').disabled = on;
        document.getElementById('spinner').style.display = on ? 'block' : 'none';
    }
    function clearResult() {
        document.getElementById('resultBox').innerHTML = '<div class="result-label">Yuklanmoqda...</div>';
        document.getElementById('errBox').style.display = 'none';
    }
    function showErr(msg) {
        const b = document.getElementById('errBox');
        b.textContent = msg;
        b.style.display = 'block';
        document.getElementById('resultBox').innerHTML = '<div class="result-label">Natija shu yerda paydo bo\'ladi</div>';
    }
</script>
@endsection
