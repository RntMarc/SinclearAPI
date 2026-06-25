<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login – Sinclear</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; background: #1a1a2e; color: #eee;
        }
        .card {
            background: #16213e; padding: 2rem; border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3); text-align: center; max-width: 380px; width: 100%;
        }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; color: #5865F2; }
        p { color: #aaa; margin-bottom: 1.5rem; font-size: 0.9rem; }
        .form-group { margin-bottom: 1rem; text-align: left; }
        .form-group label { display: block; margin-bottom: 0.4rem; color: #aaa; font-size: 0.85rem; }
        .form-group input {
            width: 100%; padding: 0.7rem 0.9rem; border-radius: 8px;
            background: #1a1a2e; border: 1px solid #0f3460; color: #fff;
            font-size: 1rem; text-align: center; letter-spacing: 0.2rem;
        }
        .form-group input:focus { outline: none; border-color: #5865F2; }
        .btn {
            width: 100%; padding: 0.7rem; border-radius: 8px; font-size: 0.95rem;
            background: #5865F2; color: #fff; border: none; cursor: pointer;
            transition: background 0.15s;
        }
        .btn:hover { background: #4751c4; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .error { color: #ef4444; font-size: 0.85rem; margin-top: 0.5rem; }
        .step-indicator { display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 1.5rem; }
        .step { width: 8px; height: 8px; border-radius: 50%; background: #0f3460; }
        .step.active { background: #5865F2; }
        .hidden { display: none; }
        .hint { font-size: 0.8rem; color: #888; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Admin-Bereich</h1>
        <p>Melde dich mit deinem Konto an.</p>

        <div class="step-indicator">
            <div class="step" id="step1-indicator"></div>
            <div class="step" id="step2-indicator"></div>
        </div>

        <div id="step1">
            <div class="form-group">
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" placeholder="admin@example.com" autocomplete="email" autofocus>
            </div>
            <button class="btn" id="sendOtpBtn" onclick="sendOtp()">Login-Code senden</button>
            <div id="step1Error" class="error hidden"></div>
        </div>

        <div id="step2" class="hidden">
            <div class="form-group">
                <label for="code">6-stelliger Code</label>
                <input type="text" id="code" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus>
            </div>
            <button class="btn" id="verifyBtn" onclick="verifyOtp()">Anmelden</button>
            <div id="step2Error" class="error hidden"></div>
            <div class="hint">
                <a href="#" onclick="resetLogin()" style="color:#5865F2;">Andere E-Mail verwenden</a>
            </div>
        </div>
    </div>

    <script>
        let loginEmail = '';

        function showError(id, msg) {
            const el = document.getElementById(id);
            el.textContent = msg;
            el.classList.remove('hidden');
        }

        function hideError(id) {
            document.getElementById(id).classList.add('hidden');
        }

        function setLoading(btnId, loading) {
            const btn = document.getElementById(btnId);
            btn.disabled = loading;
            btn.textContent = loading ? 'Bitte warten …' : btn.dataset.originalText;
        }

        document.getElementById('sendOtpBtn').dataset.originalText = 'Login-Code senden';
        document.getElementById('verifyBtn').dataset.originalText = 'Anmelden';

        document.getElementById('email').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') sendOtp();
        });
        document.getElementById('code').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') verifyOtp();
        });

        async function sendOtp() {
            hideError('step1Error');
            const email = document.getElementById('email').value.trim();
            if (!email) { showError('step1Error', 'Bitte E-Mail eingeben.'); return; }

            setLoading('sendOtpBtn', true);
            try {
                const res = await fetch('/api/v2/admin/login/otp/request', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email }),
                });
                const data = await res.json();
                if (!res.ok) {
                    showError('step1Error', data.error === 'user_not_found' ? 'Kein Konto mit dieser E-Mail.'
                        : data.error === 'forbidden' ? 'Kein Admin-Zugriff.'
                        : data.error === 'too_many_requests' ? 'Zu viele Anfragen. Bitte warten.'
                        : 'Fehler: ' + (data.error || 'unbekannt'));
                    return;
                }
                loginEmail = email;
                document.getElementById('step1').classList.add('hidden');
                document.getElementById('step2').classList.remove('hidden');
                document.getElementById('step1-indicator').classList.add('active');
                document.getElementById('step2-indicator').classList.add('active');
                document.getElementById('code').focus();
            } catch (e) {
                showError('step1Error', 'Verbindungsfehler. Bitte erneut versuchen.');
            } finally {
                setLoading('sendOtpBtn', false);
            }
        }

        async function verifyOtp() {
            hideError('step2Error');
            const code = document.getElementById('code').value.trim();
            if (!code || code.length !== 6) { showError('step2Error', 'Bitte 6-stelligen Code eingeben.'); return; }

            setLoading('verifyBtn', true);
            try {
                const res = await fetch('/api/v2/admin/login/otp/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: loginEmail, code }),
                });
                const data = await res.json();
                if (!res.ok) {
                    showError('step2Error', data.error === 'invalid_or_expired_code' ? 'Code ungültig oder abgelaufen.'
                        : data.error === 'forbidden' ? 'Kein Admin-Zugriff.'
                        : 'Fehler: ' + (data.error || 'unbekannt'));
                    return;
                }
                window.location.href = '/api/v2/admin/';
            } catch (e) {
                showError('step2Error', 'Verbindungsfehler. Bitte erneut versuchen.');
            } finally {
                setLoading('verifyBtn', false);
            }
        }

        function resetLogin() {
            loginEmail = '';
            document.getElementById('step1').classList.remove('hidden');
            document.getElementById('step2').classList.add('hidden');
            document.getElementById('step1-indicator').classList.remove('active');
            document.getElementById('step2-indicator').classList.remove('active');
            document.getElementById('email').value = '';
            document.getElementById('email').focus();
            hideError('step1Error');
            hideError('step2Error');
        }
    </script>
</body>
</html>
