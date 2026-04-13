<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — Dubber</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;
            background: #0a0a0f;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 220px;
            height: 100vh;
            background: #13131a;
            border-right: 1px solid #1e1e2e;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }
        .sidebar-brand {
            padding: 20px 20px 16px;
            border-bottom: 1px solid #1e1e2e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-brand .mark {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .sidebar-brand span {
            font-weight: 700;
            font-size: 0.95rem;
            color: #fff;
            letter-spacing: -0.01em;
        }
        .sidebar-nav {
            flex: 1;
            padding: 12px 10px;
        }
        .nav-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #334155;
            padding: 0 10px;
            margin: 8px 0 4px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 8px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
        }
        .nav-item:hover { background: #1e1e2e; color: #cbd5e1; }
        .nav-item.active { background: rgba(99,102,241,0.15); color: #a5b4fc; }
        .nav-item .icon { width: 18px; text-align: center; font-size: 0.95rem; }
        .sidebar-footer {
            padding: 16px 10px;
            border-top: 1px solid #1e1e2e;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 4px;
        }
        .user-avatar {
            width: 30px; height: 30px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .user-name { font-size: 0.85rem; font-weight: 600; color: #cbd5e1; }
        .user-role { font-size: 0.75rem; color: #475569; }
        .logout-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 8px 10px;
            background: none;
            border: none;
            border-radius: 8px;
            color: #64748b;
            font-size: 0.875rem;
            cursor: pointer;
            text-align: left;
            transition: background 0.12s, color 0.12s;
        }
        .logout-btn:hover { background: rgba(239,68,68,0.1); color: #fca5a5; }

        /* Main content */
        .main {
            margin-left: 220px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            height: 56px;
            background: #13131a;
            border-bottom: 1px solid #1e1e2e;
            display: flex;
            align-items: center;
            padding: 0 28px;
        }
        .topbar h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #f1f5f9;
            letter-spacing: -0.01em;
        }
        .content { padding: 28px; flex: 1; }

        /* Flash */
        .flash {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(34,197,94,0.1);
            border: 1px solid rgba(34,197,94,0.2);
            border-radius: 10px;
            padding: 12px 16px;
            color: #86efac;
            font-size: 0.875rem;
            margin-bottom: 20px;
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        thead th {
            text-align: left;
            padding: 10px 14px;
            color: #475569;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #1e1e2e;
            background: #0f0f18;
        }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #13131a; vertical-align: middle; }
        tbody tr:hover td { background: #13131a; }

        /* Links */
        a { color: #818cf8; text-decoration: none; }
        a:hover { color: #a5b4fc; }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; opacity: 0.6; }
        .badge-complete   { background: rgba(34,197,94,0.12);  color: #86efac; }
        .badge-processing { background: rgba(59,130,246,0.12); color: #93c5fd; }
        .badge-needs_retts{ background: rgba(245,158,11,0.12); color: #fcd34d; }
        .badge-error      { background: rgba(239,68,68,0.12);  color: #fca5a5; }
        .badge-preparing  { background: rgba(100,116,139,0.12);color: #94a3b8; }

        /* Form controls */
        input[type=text], input[type=search], input[type=password], select, textarea {
            background: #0a0a0f;
            border: 1px solid #1e1e2e;
            color: #e2e8f0;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.15s;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        textarea { width: 100%; resize: vertical; }
        select { appearance: none; cursor: pointer; padding-right: 28px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: opacity 0.15s, transform 0.1s;
            text-decoration: none;
        }
        .btn:hover { opacity: 0.85; text-decoration: none; }
        .btn:active { transform: scale(0.98); }
        .btn-primary { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
        .btn-secondary { background: #1e1e2e; color: #94a3b8; border: 1px solid #2d2d3e; }
        .btn-secondary:hover { color: #e2e8f0; }
        .btn-danger { background: rgba(239,68,68,0.12); color: #fca5a5; border: 1px solid rgba(239,68,68,0.2); }
        .btn-danger:hover { background: rgba(239,68,68,0.2); opacity: 1; }
        .btn-sm { padding: 5px 10px; font-size: 0.8rem; }

        /* Card */
        .card {
            background: #13131a;
            border: 1px solid #1e1e2e;
            border-radius: 12px;
            padding: 20px;
        }

        /* Page title */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -0.02em;
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* Stat cards */
        .stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .stat {
            background: #13131a;
            border: 1px solid #1e1e2e;
            border-radius: 12px;
            padding: 16px 20px;
            min-width: 160px;
            flex: 1;
        }
        .stat-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; margin-bottom: 6px; }
        .stat-value { font-size: 1.4rem; font-weight: 700; color: #f1f5f9; }
        .stat-sub { font-size: 0.8rem; color: #475569; margin-top: 2px; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="mark">🎙</div>
            <span>Dubber</span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Content</div>
            <a href="{{ route('admin.dubs.index') }}"
               class="nav-item {{ request()->routeIs('admin.dubs*') ? 'active' : '' }}">
                <span class="icon">🎬</span> Dubs
            </a>
            <div class="nav-label">Tools</div>
            <a href="{{ route('admin.premium-dub') }}"
               class="nav-item {{ request()->routeIs('admin.premium-dub') ? 'active' : '' }}">
                <span class="icon">🎬</span> Premium Dub
            </a>
            <a href="{{ route('admin.voice-pool.index') }}"
               class="nav-item {{ request()->routeIs('admin.voice-pool*') ? 'active' : '' }}">
                <span class="icon">🎙</span> Voice Pool
            </a>
            <a href="{{ route('admin.prosody-test.index') }}"
               class="nav-item {{ request()->routeIs('admin.prosody-test*') ? 'active' : '' }}">
                <span class="icon">🎚</span> Prosody Test
            </a>
            <div class="nav-label">Settings</div>
            <a href="{{ route('admin.users.index') }}"
               class="nav-item {{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                <span class="icon">👤</span> Users
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                <div>
                    <div class="user-name">{{ auth()->user()->name }}</div>
                    <div class="user-role">Admin</div>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="logout-btn">
                    <span>⎋</span> Sign out
                </button>
            </form>
        </div>
    </aside>

    <div class="main">
        <div class="topbar">
            <h2>@yield('title', 'Admin')</h2>
        </div>
        <div class="content">
            @if(session('success'))
                <div class="flash">✓ {{ session('success') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</body>
</html>
