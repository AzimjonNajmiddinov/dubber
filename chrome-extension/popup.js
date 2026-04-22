chrome.storage.sync.get({ apiBase: 'https://dubbing.uz', language: 'uz' }, (s) => {
    document.getElementById('apiBase').value  = s.apiBase;
    document.getElementById('language').value = s.language;
});

document.getElementById('save').addEventListener('click', () => {
    const data = {
        apiBase:  document.getElementById('apiBase').value.trim().replace(/\/$/, ''),
        language: document.getElementById('language').value,
    };
    chrome.storage.sync.set(data, () => {
        const saved = document.getElementById('saved');
        saved.style.display = 'block';
        setTimeout(() => { saved.style.display = 'none'; }, 2000);
    });
});
