<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{title}} – Sinclear Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex; min-height: 100vh; background: #1a1a2e; color: #eee;
        }
        .sidebar {
            width: 260px; background: #16213e; padding: 1.5rem;
            display: flex; flex-direction: column; flex-shrink: 0;
            border-right: 1px solid #0f3460;
        }
        .logo {
            font-size: 1.25rem; font-weight: 700; color: #5865F2;
            margin-bottom: 2rem; padding-bottom: 1rem;
            border-bottom: 1px solid #0f3460;
        }
        .sidebar nav { display: flex; flex-direction: column; gap: 0.25rem; }
        .sidebar nav a {
            color: #aaa; text-decoration: none; padding: 0.6rem 0.8rem;
            border-radius: 8px; transition: all 0.15s;
            font-size: 0.9rem;
        }
        .sidebar nav a:hover { background: #1a1a2e; color: #fff; }
        .sidebar nav a.active { background: #0f3460; color: #fff; font-weight: 600; }
        .sidebar .user-info {
            margin-top: auto; padding-top: 1rem; border-top: 1px solid #0f3460;
            font-size: 0.8rem; color: #888;
        }
        .main { flex: 1; padding: 2rem; overflow-y: auto; }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 2rem;
        }
        .page-header h1 { font-size: 1.5rem; font-weight: 600; }
        .page-header .subtitle { color: #888; font-size: 0.85rem; margin-top: 0.25rem; }
        .card {
            background: #16213e; border-radius: 12px; padding: 1.5rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
        }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
        .stat-card { text-align: center; padding: 2rem 1.5rem; }
        .stat-card .number { font-size: 2.5rem; font-weight: 700; color: #5865F2; }
        .stat-card .label { color: #888; margin-top: 0.5rem; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; }
        table th, table td { text-align: left; padding: 0.6rem 0.8rem; font-size: 0.85rem; }
        table th { color: #888; font-weight: 600; border-bottom: 1px solid #0f3460; }
        table td { border-bottom: 1px solid #1a1a2e; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-admin { background: #5865F2; color: #fff; }
        .btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.85rem;
            border: none; cursor: pointer; transition: all 0.15s;
            background: #0f3460; color: #fff; text-decoration: none;
        }
        .btn:hover { background: #1a4a8a; }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.8rem; }
        .btn-primary { background: #5865F2; }
        .btn-primary:hover { background: #4751c4; }
        .btn-success { background: #22c55e; }
        .btn-success:hover { background: #16a34a; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.4rem; color: #aaa; font-size: 0.85rem; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 0.6rem 0.8rem; border-radius: 8px;
            background: #1a1a2e; border: 1px solid #0f3460; color: #fff;
            font-size: 0.9rem; font-family: inherit;
        }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #5865F2;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem;
            padding: 0.8rem 1.5rem; border-radius: 8px;
            font-size: 0.9rem; z-index: 1000;
            animation: slideIn 0.3s ease;
        }
        .toast-success { background: #22c55e; color: #fff; }
        .toast-error { background: #ef4444; color: #fff; }
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .preset-btns { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.5rem; }
        .preset-btn {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #0f3460;
            background: transparent; color: #ccc; cursor: pointer; font-size: 0.8rem;
            transition: all 0.15s;
        }
        .preset-btn:hover { background: #0f3460; color: #fff; border-color: #5865F2; }
        .placeholder-overlay {
            position: relative;
        }
        .placeholder-overlay::after {
            content: 'Coming soon';
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(26,26,46,0.8); color: #888;
            font-size: 1.1rem; font-weight: 600; border-radius: 12px;
            backdrop-filter: blur(2px);
        }
        .mt-2 { margin-top: 1rem; }
        .mb-2 { margin-bottom: 1rem; }
        .flex { display: flex; }
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo">⚙ Sinclear Admin</div>
        <nav>
            <a href="/api/v2/admin/" class="nav-link" data-path="/api/v2/admin/">Dashboard</a>
            <a href="/api/v2/admin/users" class="nav-link" data-path="/api/v2/admin/users">Nutzer</a>
            <a href="/api/v2/admin/travel" class="nav-link" data-path="/api/v2/admin/travel">Reisen & Events</a>
            <a href="/api/v2/admin/notifications" class="nav-link" data-path="/api/v2/admin/notifications">Benachrichtigungen</a>
        </nav>
        <div class="user-info">
            <div>{{userEmail}}</div>
            <a href="#" onclick="logout()" style="color:#ef4444;text-decoration:none;font-size:0.8rem;">Abmelden</a>
        </div>
    </aside>
    <main class="main">
        {{content}}
    </main>
    <script>
        function logout() {
            window.location.href = '/api/v2/admin/logout';
        }

        function showToast(message, type = 'success') {
            const el = document.createElement('div');
            el.className = 'toast toast-' + type;
            el.textContent = message;
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        // Active nav highlighting
        document.querySelectorAll('.nav-link').forEach(function(link) {
            if (window.location.pathname === link.getAttribute('data-path')) {
                link.classList.add('active');
            }
        });
    </script>
</body>
</html>
