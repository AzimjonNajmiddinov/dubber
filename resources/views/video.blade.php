<!DOCTYPE html>
<html>
<head>
    <title>Video progress</title>
</head>
<body>

<h2>🎬 Videoni qayta ishlash</h2>

<div id="progress">Yuklanmoqda...</div>

<h3>🎙 Spikerlar</h3>
<ul id="speakers"></ul>

<h3>📝 Segmentlar</h3>
<ul id="segments"></ul>

<script>
    const videoId = {{ $video->id }};

    // 1️⃣ Progress polling
    setInterval(() => {
        fetch(`/videos/${videoId}/status`)
            .then(r => r.json())
            .then(d => {
                document.getElementById('progress').innerText =
                    `Status: ${d.status} (${d.progress}%)`;
            });
    }, 2000);

    // 2️⃣ Speakers
    fetch(`/videos/${videoId}/speakers`)
        .then(r => r.json())
        .then(data => {
            const ul = document.getElementById('speakers');
            ul.innerHTML = '';
            data.forEach(s => {
                ul.innerHTML += `<li>
                ${s.external_key} — ${s.gender} — voice: ${s.tts_voice}
            </li>`;
            });
        });

    // 3️⃣ Segments
    fetch(`/videos/${videoId}/segments`)
        .then(r => r.json())
        .then(data => {
            const ul = document.getElementById('segments');
            ul.innerHTML = '';
            data.forEach(s => {
                ul.innerHTML += `<li>
                [${s.start.toFixed(2)}–${s.end.toFixed(2)}]
                <b>${s.speaker.key}</b> (${s.speaker.gender})
                <br>
                Original: ${s.text}
                <br>
                Translated: ${s.translated_text ?? '-'}
            </li><hr>`;
            });
        });
</script>

</body>
</html>
