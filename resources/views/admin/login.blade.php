<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: #16213e; padding: 2rem; border-radius: 8px; width: 100%; max-width: 360px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        h1 { font-size: 1.25rem; margin-bottom: 1.5rem; text-align: center; color: #a8d8ea; }
        .error { background: #5c1a1a; color: #ff9a9a; padding: 0.5rem 0.75rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.875rem; }
        label { display: block; font-size: 0.875rem; margin-bottom: 0.5rem; color: #8899aa; }
        input[type="password"] { width: 100%; padding: 0.6rem 0.75rem; border: 1px solid #2a3a5c; border-radius: 4px; background: #0f3460; color: #e0e0e0; font-size: 1rem; outline: none; }
        input[type="password"]:focus { border-color: #a8d8ea; }
        button { width: 100%; padding: 0.6rem; margin-top: 1rem; border: none; border-radius: 4px; background: #533483; color: #fff; font-size: 1rem; cursor: pointer; }
        button:hover { background: #6a42a0; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Admin Access</h1>
        @if($error)
            <div class="error">{{ $error }}</div>
        @endif
        <form method="POST" action="{{ $intended }}">
            @csrf
            <input type="hidden" name="intended" value="{{ $intended }}">
            <label for="admin_password">Password</label>
            <input type="password" id="admin_password" name="admin_password" autofocus required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
