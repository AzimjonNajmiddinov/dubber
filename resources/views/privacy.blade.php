<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy — Dubber</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9f9f9; color: #1a1a1a; }
        .container { max-width: 720px; margin: 60px auto; padding: 0 24px 80px; }
        h1 { font-size: 2rem; font-weight: 700; margin-bottom: 8px; }
        .date { color: #888; font-size: 0.9rem; margin-bottom: 40px; }
        h2 { font-size: 1.1rem; font-weight: 600; margin: 32px 0 10px; }
        p, li { font-size: 1rem; color: #444; line-height: 1.7; margin-bottom: 10px; }
        ul { padding-left: 20px; }
        a { color: #1a73e8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Privacy Policy</h1>
        <p class="date">Last updated: {{ date('F d, Y') }}</p>

        <p>Dubber ("we", "our", or "the extension") is a Chrome browser extension that provides real-time Uzbek dubbing for YouTube videos. This Privacy Policy explains what data we collect and how we use it.</p>

        <h2>1. Data We Collect</h2>
        <ul>
            <li><strong>YouTube video URL</strong> — sent to our server (dubbing.uz) to retrieve translated audio segments.</li>
            <li><strong>User settings</strong> — API server address and selected dubbing language, stored locally in Chrome storage on your device.</li>
        </ul>

        <h2>2. Data We Do Not Collect</h2>
        <ul>
            <li>We do not collect personal information (name, email, location).</li>
            <li>We do not track your browsing history.</li>
            <li>We do not sell or share your data with third parties.</li>
        </ul>

        <h2>3. How We Use Your Data</h2>
        <p>The video URL is used solely to fetch the corresponding dubbed audio from our server. No user-identifiable information is associated with this request.</p>

        <h2>4. Data Storage</h2>
        <p>Settings saved via <code>chrome.storage</code> are stored locally on your device and are not transmitted to our servers.</p>

        <h2>5. Third-Party Services</h2>
        <p>The extension communicates only with <strong>dubbing.uz</strong> to retrieve audio data. No other third-party services receive your data.</p>

        <h2>6. Remote Code</h2>
        <p>The extension does not execute remote code. All scripts are bundled within the extension package. Only audio data (base64) and subtitle text are downloaded from the server.</p>

        <h2>7. Contact</h2>
        <p>If you have any questions about this Privacy Policy, please contact us at <a href="mailto:azim.najmiddinov97@gmail.com">azim.najmiddinov97@gmail.com</a>.</p>
    </div>
</body>
</html>
