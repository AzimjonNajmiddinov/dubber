<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Not Found</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: #0a0a0f;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .bg {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 50% at 20% 40%, rgba(99, 46, 255, 0.08) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 80% 60%, rgba(20, 110, 255, 0.06) 0%, transparent 60%);
        }

        .container {
            position: relative;
            text-align: center;
            padding: 2rem;
        }

        .code {
            font-size: clamp(7rem, 20vw, 14rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
            background: linear-gradient(135deg, #6b21a8 0%, #3b82f6 50%, #06b6d4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            user-select: none;
        }

        .line {
            width: 3rem;
            height: 2px;
            background: linear-gradient(90deg, #6b21a8, #3b82f6);
            margin: 1.5rem auto;
            border-radius: 2px;
        }

        .title {
            font-size: 1.1rem;
            font-weight: 500;
            color: #9ca3af;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="bg"></div>
    <div class="container">
        <div class="code">404</div>
        <div class="line"></div>
        <div class="title">Page Not Found</div>
    </div>
</body>
</html>
