<div class="page-header">
    <div>
        <h1>Benachrichtigungen senden</h1>
        <div class="subtitle">Realistische Push-Benachrichtigungen mit konkreten Objekten testen</div>
    </div>
</div>

<div class="card">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Benachrichtigung senden</h2>

    <form id="notifForm" onsubmit="sendNotification(event)">
        <div class="form-row">
            <div class="form-group">
                <label for="userId">Empfänger *</label>
                <select id="userId" name="userId" required onchange="onUserChange()">
                    <option value="">– Nutzer auswählen –</option>
                </select>
            </div>
            <div class="form-group">
                <label for="domain">Bereich *</label>
                <select id="domain" name="domain" required onchange="onDomainChange()" disabled>
                    <option value="">– Zuerst Empfänger wählen –</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="code">Benachrichtigungsart *</label>
                <select id="code" name="code" required onchange="onCodeChange()" disabled>
                    <option value="">– Zuerst Bereich wählen –</option>
                </select>
            </div>
            <div class="form-group">
                <label for="objectId">Objekt *</label>
                <select id="objectId" name="objectId" required disabled onchange="onObjectChange()">
                    <option value="">– Zuerst Art wählen –</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" id="sendBtn" disabled>Benachrichtigung senden</button>
    </form>
</div>

<div class="card mt-2">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Vordefinierte Admin-Benachrichtigungen</h2>
    <p style="color:#888;font-size:0.85rem;margin-bottom:1rem;">
        Schnelle Test-Benachrichtigungen ohne Objektauswahl.
    </p>

    <div class="form-group">
        <label for="presetUserId">Empfänger</label>
        <select id="presetUserId">
            <option value="">– Nutzer auswählen –</option>
        </select>
    </div>

    <div class="preset-btns">
        <button class="preset-btn" onclick="sendPreset('admin.system_update')">System-Update</button>
        <button class="preset-btn" onclick="sendPreset('admin.new_feature')">Neue Funktion</button>
        <button class="preset-btn" onclick="sendPreset('admin.maintenance')">Wartungshinweis</button>
        <button class="preset-btn" onclick="sendPreset('admin.welcome')">Willkommensnachricht</button>
        <button class="preset-btn" onclick="sendPreset('admin.test')">Test Ping</button>
    </div>
</div>

<script>
    const NOTIFICATION_TYPES = {{notificationTypesJson}};

    let users = [];

    async function loadUsers() {
        try {
            const res = await fetch('/api/v2/admin/users/json', { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            const data = await res.json();
            users = data.data || [];
            const selects = ['userId', 'presetUserId'];
            for (const selId of selects) {
                const sel = document.getElementById(selId);
                sel.innerHTML = '<option value="">– Nutzer auswählen –</option>';
                for (const u of users) {
                    const opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.displayName + ' (' + u.email + ')' + (u.isAdmin ? ' [Admin]' : '');
                    sel.appendChild(opt);
                }
            }
        } catch (e) {
            showToast('Fehler beim Laden der Nutzerliste', 'error');
        }
    }

    function onUserChange() {
        const domainSel = document.getElementById('domain');
        const codeSel = document.getElementById('code');
        const objSel = document.getElementById('objectId');
        const sendBtn = document.getElementById('sendBtn');

        if (!document.getElementById('userId').value) {
            domainSel.innerHTML = '<option value="">– Zuerst Empfänger wählen –</option>';
            domainSel.disabled = true;
            codeSel.innerHTML = '<option value="">– Zuerst Bereich wählen –</option>';
            codeSel.disabled = true;
            objSel.innerHTML = '<option value="">– Zuerst Art wählen –</option>';
            objSel.disabled = true;
            sendBtn.disabled = true;
            return;
        }

        domainSel.innerHTML = '<option value="">– Bereich wählen –</option>';
        for (const [domain, types] of Object.entries(NOTIFICATION_TYPES)) {
            const opt = document.createElement('option');
            opt.value = domain;
            opt.textContent = domain.charAt(0).toUpperCase() + domain.slice(1);
            domainSel.appendChild(opt);
        }
        domainSel.disabled = false;

        codeSel.innerHTML = '<option value="">– Zuerst Bereich wählen –</option>';
        codeSel.disabled = true;
        objSel.innerHTML = '<option value="">– Zuerst Art wählen –</option>';
        objSel.disabled = true;
        sendBtn.disabled = true;
    }

    function onDomainChange() {
        const domain = document.getElementById('domain').value;
        const codeSel = document.getElementById('code');
        const objSel = document.getElementById('objectId');
        const sendBtn = document.getElementById('sendBtn');

        if (!domain || !NOTIFICATION_TYPES[domain]) {
            codeSel.innerHTML = '<option value="">– Zuerst Bereich wählen –</option>';
            codeSel.disabled = true;
            objSel.innerHTML = '<option value="">– Zuerst Art wählen –</option>';
            objSel.disabled = true;
            sendBtn.disabled = true;
            return;
        }

        const types = NOTIFICATION_TYPES[domain];
        codeSel.innerHTML = '<option value="">– Benachrichtigungsart wählen –</option>';
        for (const [code, label] of Object.entries(types)) {
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = label;
            codeSel.appendChild(opt);
        }
        codeSel.disabled = false;

        objSel.innerHTML = '<option value="">– Zuerst Art wählen –</option>';
        objSel.disabled = true;
        sendBtn.disabled = true;
    }

    async function onCodeChange() {
        const code = document.getElementById('code').value;
        const userId = document.getElementById('userId').value;
        const objSel = document.getElementById('objectId');
        const sendBtn = document.getElementById('sendBtn');

        if (!code || !userId) {
            objSel.innerHTML = '<option value="">– Zuerst Art wählen –</option>';
            objSel.disabled = true;
            sendBtn.disabled = true;
            return;
        }

        objSel.innerHTML = '<option value="">Lade Objekte…</option>';
        objSel.disabled = true;
        sendBtn.disabled = true;

        try {
            const res = await fetch('/api/v2/admin/notifications/objects?userId=' + encodeURIComponent(userId) + '&code=' + encodeURIComponent(code), { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            const data = await res.json();
            const objects = data.data || [];

            objSel.innerHTML = '';
            if (objects.length === 0) {
                objSel.innerHTML = '<option value="">Keine Objekte gefunden</option>';
                objSel.disabled = true;
                return;
            }

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '– Objekt wählen (' + objects.length + ' verfügbar) –';
            objSel.appendChild(placeholder);

            for (const obj of objects) {
                const opt = document.createElement('option');
                opt.value = obj.id;
                opt.textContent = obj.label;
                objSel.appendChild(opt);
            }
            objSel.disabled = false;
        } catch (e) {
            objSel.innerHTML = '<option value="">Fehler beim Laden</option>';
            showToast('Fehler beim Laden der Objekte', 'error');
        }
    }

    function onObjectChange() {
        const objSel = document.getElementById('objectId');
        const sendBtn = document.getElementById('sendBtn');
        sendBtn.disabled = !objSel.value;
    }

    async function sendNotification(event) {
        event.preventDefault();
        const userId = document.getElementById('userId').value;
        const code = document.getElementById('code').value;
        const objectId = document.getElementById('objectId').value;

        if (!userId || !code || !objectId) {
            showToast('Bitte alle Felder ausfüllen.', 'error');
            return;
        }

        try {
            const res = await fetch('/api/v2/admin/notifications/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, code, objectId }),
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Benachrichtigung gesendet (Code: ' + code + ')');
                document.getElementById('objectId').selectedIndex = 0;
                document.getElementById('sendBtn').disabled = true;
            } else {
                const data = await res.json();
                showToast('Fehler: ' + (data.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Senden', 'error');
        }
    }

    async function sendPreset(code) {
        const userId = document.getElementById('presetUserId').value;
        if (!userId) { showToast('Bitte einen Empfänger auswählen.', 'error'); return; }

        let deepLink = 'home';
        if (code === 'admin.new_feature') deepLink = 'entdecken';
        if (code === 'admin.welcome') deepLink = 'home';

        try {
            const res = await fetch('/api/v2/admin/notifications/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, code, deepLink }),
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Benachrichtigung gesendet (Code: ' + code + ')');
            } else {
                const data = await res.json();
                showToast('Fehler: ' + (data.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Senden', 'error');
        }
    }

    loadUsers();
</script>
