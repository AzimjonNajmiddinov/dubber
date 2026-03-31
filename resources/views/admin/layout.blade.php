<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin') — Dubber</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #0f1117; color: #e2e8f0; min-height: 100vh; }
        nav { background: #1a1f2e; border-bottom: 1px solid #2d3748; padding: 0 24px; display: flex; align-items: center; gap: 24px; height: 52px; }
        nav a { color: #a0aec0; text-decoration: none; font-size: 0.9rem; }
        nav a:hover { color: #fff; }
        nav .brand { color: #fff; font-weight: 700; font-size: 1rem; margin-right: 16px; }
        nav form { margin-left: auto; }
        nav button { background: none; border: 1px solid #4a5568; color: #a0aec0; padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        nav button:hover { color: #fff; border-color: #718096; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .flash { background: #22543d; border: 1px solid #276749; color: #9ae6b4; padding: 10px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th { text-align: left; padding: 10px 12px; color: #718096; font-weight: 600; border-bottom: 1px solid #2d3748; }
        td { padding: 10px 12px; border-bottom: 1px solid #1e2535; vertical-align: top; }
        tr:hover td { background: #1a1f2e; }
        a { color: #63b3ed; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
        .badge-complete { background: #1c4532; color: #9ae6b4; }
        .badge-processing { background: #1a365d; color: #90cdf4; }
        .badge-needs_retts { background: #3c1f0a; color: #fbd38d; }
        .badge-error { background: #3b1a1a; color: #fc8181; }
        .badge-preparing { background: #2d2d2d; color: #a0aec0; }
        input[type=text], input[type=search], select, textarea {
            background: #1a1f2e; border: 1px solid #2d3748; color: #e2e8f0;
            padding: 7px 12px; border-radius: 6px; font-size: 0.9rem; outline: none;
        }
        input:focus, select:focus, textarea:focus { border-color: #4299e1; }
        textarea { width: 100%; resize: vertical; font-family: inherit; }
        .btn { display: inline-block; padding: 7px 14px; border-radius: 6px; font-size: 0.85rem; cursor: pointer; border: none; font-weight: 600; }
        .btn-primary { background: #4299e1; color: #fff; }
        .btn-primary:hover { background: #3182ce; }
        .btn-danger { background: #c53030; color: #fff; }
        .btn-danger:hover { background: #9b2c2c; }
        .btn-sm { padding: 4px 10px; font-size: 0.8rem; }
        .page-title { font-size: 1.3rem; font-weight: 700; color: #fff; margin-bottom: 20px; }
        .filters { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; flex-wrap: wrap; }
        .pagination { display: flex; gap: 6px; margin-top: 20px; justify-content: center; }
        .pagination span, .pagination a { padding: 6px 12px; border: 1px solid #2d3748; border-radius: 6px; font-size: 0.85rem; }
        .pagination .active { background: #4299e1; border-color: #4299e1; color: #fff; }
    </style>
</head>
<body>
    <nav>
        <span class="brand">Dubber Admin</span>
        <a href="{{ route('admin.dubs.index') }}">Dubs</a>
        <a href="{{ route('admin.users.index') }}">Users</a>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit">Logout</button>
        </form>
    </nav>
    <div class="container">
        @if(session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif
        @yield('content')
    </div>
</body>
</html>
