<div class="page-header">
    <div>
        <h1>Benachrichtigungen senden</h1>
        <div class="subtitle">Vordefinierte oder benutzerdefinierte Push-Benachrichtigungen versenden</div>
    </div>
</div>

<div class="card">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Vordefinierte Benachrichtigungen</h2>
    <p style="color:#888;font-size:0.85rem;margin-bottom:1rem;">
        Wähle einen Empfänger und klicke auf eine Vorlage, um die Benachrichtigung sofort zu senden.
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

<div class="card mt-2">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Benutzerdefinierte Benachrichtigung</h2>

    <form id="customForm" onsubmit="sendCustom(event)">
        <div class="form-row">
            <div class="form-group">
                <label for="userId">Empfänger *</label>
                <select id="userId" name="userId" required>
                    <option value="">– Nutzer auswählen –</option>
                </select>
            </div>
            <div class="form-group">
                <label for="deepLink">Zielseite (Deep Link) *</label>
                <select id="deepLink" name="deepLink" required>
                    <option value="home">Startseite</option>
                    <option value="travel">Reisen</option>
                    <option value="events">Events</option>
                    <option value="profile">Profil</option>
                    <option value="settings">Einstellungen</option>
                    <option value="friends">Freunde</option>
                    <option value="discover">Entdecken</option>
                    <option value="news">News</option>
                    <option value="chat">Chat</option>
                    <option value="feedback">Feedback</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label for="title">Titel *</label>
            <input type="text" id="title" name="title" placeholder="z. B. Wichtige Ankündigung" required>
        </div>
        <div class="form-group">
            <label for="body">Nachricht *</label>
            <textarea id="body" name="body" placeholder="Deine Nachricht an den Nutzer …" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Benachrichtigung senden</button>
    </form>
</div>

<script>
    async function loadUsers() {
        try {
            const res = await fetch('/api/v2/admin/users/json', { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            const data = await res.json();
            const users = data.data || [];
            const selects = ['presetUserId', 'userId'];
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

    async function sendPreset(code) {
        const userId = document.getElementById('presetUserId').value;
        if (!userId) { showToast('Bitte einen Empfänger auswählen.', 'error'); return; }

        let deepLink = 'home';
        if (code === 'admin.new_feature') deepLink = 'discover';
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

    async function sendCustom(event) {
        event.preventDefault();
        const form = document.getElementById('customForm');
        const data = {
            userId: form.userId.value,
            code: 'admin.custom',
            deepLink: form.deepLink.value,
            title: form.title.value,
            body: form.body.value,
        };
        if (!data.userId) { showToast('Bitte einen Empfänger auswählen.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/notifications/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Benachrichtigung gesendet');
                form.title.value = '';
                form.body.value = '';
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Senden', 'error');
        }
    }

    loadUsers();
</script>
