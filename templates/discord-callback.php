<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord-Login – Sinclear Beyond</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; background: #1a1a2e; color: #eee;
        }
        .card {
            background: #16213e; padding: 2rem; border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3); text-align: center; max-width: 400px;
        }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; color: #5865F2; }
        p { color: #aaa; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .code {
            font-size: 3rem; font-weight: 700; letter-spacing: 0.5rem;
            padding: 1rem 1.5rem; background: #0f3460; border-radius: 12px;
            display: inline-block; color: #fff; user-select: all;
        }
        .hint { margin-top: 1rem; font-size: 0.85rem; color: #888; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Mit Discord verbunden</h1>
        <p>Gib diesen Code in der App ein, um dich anzumelden.</p>
        <div class="code">{{code}}</div>
        <p class="hint">Der Code ist 2 Minuten gültig.</p>
    </div>
</body>
</html>
