<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dubber — Uzbek Dubbing Chrome Extension</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9f9f9; color: #1a1a1a; }
        .hero { max-width: 720px; margin: 80px auto; padding: 0 24px; text-align: center; }
        h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 16px; }
        p { font-size: 1.1rem; color: #555; line-height: 1.6; margin-bottom: 24px; }
        .badge { display: inline-block; background: #1a73e8; color: #fff; padding: 12px 28px; border-radius: 8px; font-size: 1rem; font-weight: 600; text-decoration: none; }
        .features { max-width: 720px; margin: 0 auto 80px; padding: 0 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 24px; }
        .feature { background: #fff; border: 1px solid #e5e5e5; border-radius: 12px; padding: 24px; }
        .feature h3 { font-size: 1rem; font-weight: 600; margin-bottom: 8px; }
        .feature p { font-size: 0.9rem; color: #666; margin: 0; }
        footer { text-align: center; padding: 24px; color: #999; font-size: 0.85rem; }
        footer a { color: #1a73e8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Dubber</h1>
        <p>YouTube videolarini o'zbek tilida real vaqt rejimida tinglang. Chrome kengaytmamiz video ustiga avtomatik dublyaj qo'shadi.</p>
        <a class="badge" href="https://chromewebstore.google.com/detail/dubber" target="_blank">Chrome'ga qo'shish</a>
    </div>

    <div class="features">
        <div class="feature">
            <h3>Real vaqt dublyaj</h3>
            <p>Video boshlanishi bilan o'zbek ovozi avtomatik ishga tushadi.</p>
        </div>
        <div class="feature">
            <h3>Subtitrlar</h3>
            <p>O'zbek subtitrlarini ham ko'rsatish imkoniyati mavjud.</p>
        </div>
        <div class="feature">
            <h3>Oson boshqaruv</h3>
            <p>Bir tugma bilan dublyajni yoqish yoki o'chirish mumkin.</p>
        </div>
    </div>

    <footer>
        &copy; {{ date('Y') }} Dubber &mdash; <a href="/privacy">Privacy Policy</a>
    </footer>
</body>
</html>
