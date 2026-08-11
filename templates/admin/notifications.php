<div class="page-header">
    <div>
        <h1>Benachrichtigungen</h1>
        <div class="subtitle">Test-Benachrichtigungen an Nutzer senden (exakt wie echte Benachrichtigungen)</div>
    </div>
</div>

<div class="card">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Neue Benachrichtigung senden</h2>

    <div class="form-group">
        <label for="notifUser">Empfänger *</label>
        <select id="notifUser" required>
            <option value="">– Nutzer auswählen –</option>
            {{userOptions}}
        </select>
    </div>

    <div class="form-group">
        <label for="notifType">Benachrichtigungstyp *</label>
        <select id="notifType" required onchange="onTypeChange()">
            <option value="">– Typ auswählen –</option>
            <option value="travel_update">Reise-Update (travel_update)</option>
            <option value="event_reminder">Kalender-Event (event_reminder)</option>
            <option value="forum_reply">Foren-Antwort (forum_reply)</option>
            <option value="forum_comment">Foren-Kommentar (forum_comment)</option>
            <option value="poll_invitation">Umfrage-Einladung (poll_invitation)</option>
            <option value="feedback_status">Feedback-Status (feedback_status)</option>
            <option value="custom">Eigener Typ (custom)</option>
        </select>
    </div>

    <div class="form-group" id="entityGroup" style="display:none;">
        <label for="notifEntity">Zugehöriges Objekt (Deep-Link)</label>
        <select id="notifEntity" onchange="onEntityChange()">
            <option value="">– Kein Objekt (kein Deep-Link) –</option>
        </select>
        <div style="font-size:0.8rem;color:#888;margin-top:0.3rem;">Wähle ein existierendes Objekt, damit der Deep-Link auf dem Client funktioniert.</div>
    </div>

    <div class="form-group">
        <label for="notifRoute">Deep-Link Route</label>
        <input type="text" id="notifRoute" placeholder="z. B. /trips/0192abcd-..." readonly style="background:#0d1117;">
        <div style="font-size:0.8rem;color:#888;margin-top:0.3rem;">Wird automatisch aus dem Typ + Objekt gesetzt. Manuell editierbar.</div>
    </div>

    <div class="form-group">
        <label for="notifTitle">Titel *</label>
        <input type="text" id="notifTitle" placeholder="Benachrichtigungstitel" required maxlength="255">
    </div>

    <div class="form-group">
        <label for="notifBody">Nachrichtentext *</label>
        <textarea id="notifBody" placeholder="Text der Benachrichtigung..." required></textarea>
    </div>

    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        <button type="button" class="btn btn-primary" onclick="sendNotification()">Senden</button>
        <button type="button" class="btn" onclick="resetForm()">Zurücksetzen</button>
    </div>
</div>

<div class="card mt-2">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Vorschau</h2>
    <div id="preview" style="background:#1a1a2e;border-radius:8px;padding:1rem;min-height:80px;border:1px solid #0f3460;">
        <div style="color:#888;font-style:italic;">Fülle das Formular aus, um eine Vorschau zu sehen...</div>
    </div>
</div>

<div class="card mt-2">
    <h2 style="font-size:1.1rem;margin-bottom:0.5rem;color:#aaa;">Hinweise</h2>
    <ul style="font-size:0.85rem;color:#888;padding-left:1.2rem;line-height:1.7;">
        <li>Die Benachrichtigung wird exakt identisch zu einer echten Benachrichtigung erstellt.</li>
        <li>Wähle ein existierendes Objekt aus der Dropdown-Liste, damit der Deep-Link auf dem Client funktioniert.</li>
        <li>Die Route wird automatisch gesetzt: <code>/trips/{id}</code>, <code>/calendar/{id}</code>, <code>/forum/{id}</code>, <code>/poll/{id}</code>, <code>/feedback/{id}</code></li>
        <li>Der Typ <code>custom</code> erlaubt eigene Typ-Strings ohne Auto-Route.</li>
        <li>Push-Nachrichten werden automatisch an alle registrierten Geräte des Empfängers zugestellt.</li>
    </ul>
</div>

<script>
    let entityData = {};

    async function loadEntityData() {
        try {
            const res = await fetch('/api/v2/admin/notifications/json', { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { entityData = await res.json(); }
        } catch (e) { console.error('Failed to load entity data', e); }
    }

    const typeConfig = {
        travel_update: { routePrefix: '/trips', entityKey: 'trips', entityLabel: 'name', defaultTitle: 'Reise aktualisiert', defaultBody: 'Details deiner Reise wurden geändert.' },
        event_reminder: { routePrefix: '/calendar', entityKey: 'calendarEvents', entityLabel: 'title', defaultTitle: 'Event-Erinnerung', defaultBody: 'Du hast bald ein Termin.' },
        forum_reply: { routePrefix: '/forum', entityKey: 'forumPosts', entityLabel: 'title', defaultTitle: 'Neue Antwort', defaultBody: 'Jemand hat auf deinen Beitrag geantwortet.' },
        forum_comment: { routePrefix: '/forum', entityKey: 'forumPosts', entityLabel: 'title', defaultTitle: 'Neuer Kommentar', defaultBody: 'Jemand hat auf deine Antwort kommentiert.' },
        poll_invitation: { routePrefix: '/poll', entityKey: 'polls', entityLabel: 'title', defaultTitle: 'Umfrage-Einladung', defaultBody: 'Du wurdest zu einer Umfrage eingeladen.' },
        feedback_status: { routePrefix: '/feedback', entityKey: 'feedback', entityLabel: 'title', defaultTitle: 'Feedback-Status', defaultBody: 'Der Status deines Vorschlags wurde aktualisiert.' },
    };

    function onTypeChange() {
        const type = document.getElementById('notifType').value;
        const entityGroup = document.getElementById('entityGroup');
        const entitySelect = document.getElementById('notifEntity');
        const routeInput = document.getElementById('notifRoute');

        if (type === 'custom' || type === '') {
            entityGroup.style.display = 'none';
            entitySelect.innerHTML = '<option value="">– Kein Objekt (kein Deep-Link) –</option>';
            routeInput.value = '';
            routeInput.readOnly = false;
            document.getElementById('notifTitle').value = '';
            document.getElementById('notifBody').value = '';
            updatePreview();
            return;
        }

        routeInput.readOnly = true;
        const config = typeConfig[type];
        if (!config) { entityGroup.style.display = 'none'; return; }

        document.getElementById('notifTitle').value = config.defaultTitle;
        document.getElementById('notifBody').value = config.defaultBody;

        const entities = entityData[config.entityKey] || [];
        entitySelect.innerHTML = '<option value="">– Kein Objekt (kein Deep-Link) –</option>';
        entities.forEach(function(e) {
            const label = e[config.entityLabel] || e.id;
            entitySelect.innerHTML += '<option value="' + e.id + '">' + label + '</option>';
        });

        entityGroup.style.display = 'block';
        routeInput.value = '';
        updatePreview();
    }

    function onEntityChange() {
        const type = document.getElementById('notifType').value;
        const entityId = document.getElementById('notifEntity').value;
        const routeInput = document.getElementById('notifRoute');

        if (type === 'custom' || !typeConfig[type]) {
            routeInput.value = '';
            updatePreview();
            return;
        }

        if (entityId) {
            routeInput.value = typeConfig[type].routePrefix + '/' + entityId;
        } else {
            routeInput.value = '';
        }
        updatePreview();
    }

    function updatePreview() {
        const userSelect = document.getElementById('notifUser');
        const type = document.getElementById('notifType').value;
        const title = document.getElementById('notifTitle').value;
        const body = document.getElementById('notifBody').value;
        const route = document.getElementById('notifRoute').value;

        const userName = userSelect.selectedIndex > 0 ? userSelect.options[userSelect.selectedIndex].text : 'Nutzer';

        if (!title && !body) {
            document.getElementById('preview').innerHTML = '<div style="color:#888;font-style:italic;">Fülle das Formular aus, um eine Vorschau zu sehen...</div>';
            return;
        }

        let html = '<div style="border-left:3px solid #5865F2;padding-left:0.8rem;">';
        html += '<div style="font-size:0.75rem;color:#888;margin-bottom:0.3rem;">An: ' + userName + ' · Typ: ' + (type || '–') + '</div>';
        html += '<div style="font-weight:600;margin-bottom:0.3rem;">' + (title || '(kein Titel)') + '</div>';
        html += '<div style="font-size:0.9rem;color:#ccc;">' + (body || '(kein Text)') + '</div>';
        if (route) {
            html += '<div style="font-size:0.8rem;color:#5865F2;margin-top:0.4rem;">🔗 ' + route + '</div>';
        }
        html += '</div>';
        document.getElementById('preview').innerHTML = html;
    }

    document.getElementById('notifTitle').addEventListener('input', updatePreview);
    document.getElementById('notifBody').addEventListener('input', updatePreview);
    document.getElementById('notifRoute').addEventListener('input', updatePreview);
    document.getElementById('notifUser').addEventListener('change', updatePreview);

    async function sendNotification() {
        const userId = document.getElementById('notifUser').value;
        const type = document.getElementById('notifType').value;
        const title = document.getElementById('notifTitle').value.trim();
        const body = document.getElementById('notifBody').value.trim();
        const route = document.getElementById('notifRoute').value.trim();

        if (!userId) { showToast('Empfänger wählen.', 'error'); return; }
        if (!type) { showToast('Benachrichtigungstyp wählen.', 'error'); return; }
        if (!title) { showToast('Titel ist erforderlich.', 'error'); return; }
        if (!body) { showToast('Nachrichtentext ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/notifications/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, type, title, body, route: route || undefined }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            const json = await res.json();
            if (res.ok) {
                showToast('Benachrichtigung gesendet an ' + json.sentTo);
                resetForm();
            } else {
                showToast('Fehler: ' + (json.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Senden', 'error');
        }
    }

    function resetForm() {
        document.getElementById('notifUser').value = '';
        document.getElementById('notifType').value = '';
        document.getElementById('notifEntity').value = '';
        document.getElementById('notifRoute').value = '';
        document.getElementById('notifTitle').value = '';
        document.getElementById('notifBody').value = '';
        document.getElementById('entityGroup').style.display = 'none';
        document.getElementById('notifRoute').readOnly = false;
        updatePreview();
    }

    loadEntityData();
</script>
