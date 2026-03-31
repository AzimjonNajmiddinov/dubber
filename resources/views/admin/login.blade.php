<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Dubber</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f1117; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #1a1f2e; border: 1px solid #2d3748; border-radius: 12px; padding: 40px; width: 100%; max-width: 360px; }
        h1 { font-size: 1.4rem; margin-bottom: 24px; color: #fff; }
        label { display: block; font-size: 0.85rem; color: #a0aec0; margin-bottom: 6px; }
        input[type=password] { width: 100%; padding: 10px 14px; background: #0f1117; border: 1px solid #2d3748; border-radius: 8px; color: #e2e8f0; font-size: 1rem; outline: none; }
        input[type=password]:focus { border-color: #4299e1; }
        button { width: 100%; margin-top: 16px; padding: 11px; background: #4299e1; border: none; border-radius: 8px; color: #fff; font-size: 1rem; cursor: pointer; font-weight: 600; }
        button:hover { background: #3182ce; }
        .error { color: #fc8181; font-size: 0.85rem; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Dubber Admin</h1>
        <form method="POST" action="{{ route('admin.login.post') }}">
            @csrf
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autofocus autocomplete="current-password">
            @error('password')
                <div class="error">{{ $message }}</div>
            @enderror
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
