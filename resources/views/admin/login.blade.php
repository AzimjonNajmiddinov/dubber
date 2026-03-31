<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Dubber</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;
            background: #0a0a0f;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .wrap {
            width: 100%;
            max-width: 400px;
            padding: 24px;
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 14px;
            font-size: 1.4rem;
            margin-bottom: 12px;
        }
        .logo h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.02em;
        }
        .logo p {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 4px;
        }
        .card {
            background: #13131a;
            border: 1px solid #1e1e2e;
            border-radius: 16px;
            padding: 32px;
        }
        .field { margin-bottom: 20px; }
        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        input {
            width: 100%;
            padding: 11px 14px;
            background: #0a0a0f;
            border: 1px solid #1e1e2e;
            border-radius: 10px;
            color: #f1f5f9;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.12); }
        .error-msg {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.2);
            border-radius: 8px;
            padding: 10px 14px;
            color: #fca5a5;
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        button[type=submit] {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.15s, transform 0.1s;
            margin-top: 4px;
        }
        button[type=submit]:hover { opacity: 0.9; }
        button[type=submit]:active { transform: scale(0.99); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="logo">
            <div class="logo-mark">🎙</div>
            <h1>Dubber Admin</h1>
            <p>Sign in to your account</p>
        </div>
        <div class="card">
            @if($errors->any())
                <div class="error-msg">
                    <span>⚠</span>
                    {{ $errors->first() }}
                </div>
            @endif
            <form method="POST" action="{{ route('admin.login.post') }}">
                @csrf
                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone') }}" autofocus autocomplete="username" inputmode="numeric">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password">
                </div>
                <button type="submit">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
