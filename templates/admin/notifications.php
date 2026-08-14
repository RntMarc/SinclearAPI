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
            <option value="forum_reply">Foren-Antwort (forum_reply)</option>
            <option value="forum_comment">Foren-Kommentar (forum_comment)</option>
        </select>
    </div>

    <div class="form-group" id="entityGroup" style="display:none;">
        <label for="notifEntity">Zugehöriger Foren-Post</label>
        <select id="notifEntity" onchange="onEntityChange()">
            <option value="">– Kein Objekt (kein Deep-Link) –</option>
        </select>
        <div style="font-size:0.8rem;color:#888;margin-top:0.3rem;">Der Client erzeugt Deep-Links selbst aus type und data; die API sendet keine Route mehr.</div>
    </div>

    <div class="form-group">
        <label for="notifData">Strukturierte Daten *</label>
        <textarea id="notifData" required placeholder='[{"relation":"reply_author","object":"User","identifier":"..."}]'></textarea>
        <div style="font-size:0.8rem;color:#888;margin-top:0.3rem;">Für <code>forum_reply</code> müssen reply_author, comment_author, post_author, parent_comment, parent_post und parent_forum enthalten sein. Für <code>forum_comment</code> müssen comment_author, post_author, parent_post und parent_forum enthalten sein.</div>
    </div>

    <div class="form-group">
        <label for="notifTitle">Titel</label>
        <input type="text" id="notifTitle" placeholder="Leer lassen = von der API generiert" maxlength="255">
    </div>

    <div class="form-group">
        <label for="notifBody">Nachrichtentext</label>
        <textarea id="notifBody" placeholder="Leer lassen = von der API generiert"></textarea>
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
        <li>Als strukturierte Benachrichtigungstypen werden <code>forum_reply</code> und <code>forum_comment</code> unterstützt.</li>
        <li>Titel und Text werden von der API generiert, wenn die Felder leer gelassen werden.</li>
        <li>Die API sendet keine Deep-Link-Routen an Clients; Clients erzeugen das Routing aus <code>type</code> und <code>data</code>.</li>
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
        forum_reply: { entityKey: 'forumPosts', entityLabel: 'title', defaultTitle: '', defaultBody: '' },
        forum_comment: { entityKey: 'forumPosts', entityLabel: 'title', defaultTitle: '', defaultBody: '' },
    };

    function onTypeChange() {
        const type = document.getElementById('notifType').value;
        const entityGroup = document.getElementById('entityGroup');
        const entitySelect = document.getElementById('notifEntity');
        const dataInput = document.getElementById('notifData');

        if (type === 'custom' || type === '') {
            entityGroup.style.display = 'none';
            entitySelect.innerHTML = '<option value="">– Kein Objekt (kein Deep-Link) –</option>';
            dataInput.value = '';
            document.getElementById('notifTitle').value = '';
            document.getElementById('notifBody').value = '';
            updatePreview();
            return;
        }

        const config = typeConfig[type];
        if (!config) { entityGroup.style.display = 'none'; return; }

        document.getElementById('notifTitle').value = config.defaultTitle;
        document.getElementById('notifBody').value = config.defaultBody;

        const entities = entityData[config.entityKey] || [];
        entitySelect.innerHTML = '<option value="">– Kein Objekt (kein Deep-Link) –</option>';
        entities.forEach(function(e) {
            const label = e[config.entityLabel] || e.id;
            entitySelect.innerHTML += '<option value="' + e.id + '" data-forum-id="' + (e.forumId || '') + '" data-user-id="' + (e.userId || '') + '">' + label + '</option>';
        });

        entityGroup.style.display = 'block';
        dataInput.value = '';
        updatePreview();
    }

    function onEntityChange() {
        const type = document.getElementById('notifType').value;
        const entityId = document.getElementById('notifEntity').value;
        const dataInput = document.getElementById('notifData');

        if (type === 'custom' || !typeConfig[type]) {
            dataInput.value = '';
            updatePreview();
            return;
        }

        if (entityId && type === 'forum_reply') {
            const selected = document.getElementById('notifEntity').selectedOptions[0];
            dataInput.value = JSON.stringify([
                { relation: 'reply_author', object: 'User', identifier: document.getElementById('notifUser').value || 'REPLY_AUTHOR_ID' },
                { relation: 'comment_author', object: 'User', identifier: 'COMMENT_AUTHOR_ID' },
                { relation: 'post_author', object: 'User', identifier: selected.dataset.userId || 'POST_AUTHOR_ID' },
                { relation: 'parent_comment', object: 'ForumPostComment', identifier: 'PARENT_COMMENT_ID' },
                { relation: 'parent_post', object: 'ForumPost', identifier: entityId },
                { relation: 'parent_forum', object: 'Forum', identifier: selected.dataset.forumId || 'PARENT_FORUM_ID' },
            ], null, 2);
        } else if (entityId && type === 'forum_comment') {
            const selected = document.getElementById('notifEntity').selectedOptions[0];
            dataInput.value = JSON.stringify([
                { relation: 'comment_author', object: 'User', identifier: 'COMMENT_AUTHOR_ID' },
                { relation: 'post_author', object: 'User', identifier: selected.dataset.userId || 'POST_AUTHOR_ID' },
                { relation: 'parent_post', object: 'ForumPost', identifier: entityId },
                { relation: 'parent_forum', object: 'Forum', identifier: selected.dataset.forumId || 'PARENT_FORUM_ID' },
            ], null, 2);
        } else {
            dataInput.value = '';
        }
        updatePreview();
    }

    function updatePreview() {
        const userSelect = document.getElementById('notifUser');
        const type = document.getElementById('notifType').value;
        const title = document.getElementById('notifTitle').value;
        const body = document.getElementById('notifBody').value;
        const dataText = document.getElementById('notifData').value;

        const userName = userSelect.selectedIndex > 0 ? userSelect.options[userSelect.selectedIndex].text : 'Nutzer';

        if (!title && !body) {
            document.getElementById('preview').innerHTML = '<div style="color:#888;font-style:italic;">Fülle das Formular aus, um eine Vorschau zu sehen...</div>';
            return;
        }

        let html = '<div style="border-left:3px solid #5865F2;padding-left:0.8rem;">';
        html += '<div style="font-size:0.75rem;color:#888;margin-bottom:0.3rem;">An: ' + userName + ' · Typ: ' + (type || '–') + '</div>';
        html += '<div style="font-weight:600;margin-bottom:0.3rem;">' + (title || '(kein Titel)') + '</div>';
        html += '<div style="font-size:0.9rem;color:#ccc;">' + (body || '(kein Text)') + '</div>';
        if (dataText) {
            html += '<pre style="font-size:0.75rem;color:#5865F2;margin-top:0.4rem;white-space:pre-wrap;">' + dataText + '</pre>';
        }
        html += '</div>';
        document.getElementById('preview').innerHTML = html;
    }

    document.getElementById('notifTitle').addEventListener('input', updatePreview);
    document.getElementById('notifBody').addEventListener('input', updatePreview);
    document.getElementById('notifData').addEventListener('input', updatePreview);
    document.getElementById('notifUser').addEventListener('change', updatePreview);

    async function sendNotification() {
        const userId = document.getElementById('notifUser').value;
        const type = document.getElementById('notifType').value;
        const title = document.getElementById('notifTitle').value.trim();
        const body = document.getElementById('notifBody').value.trim();
        const dataText = document.getElementById('notifData').value.trim();

        if (!userId) { showToast('Empfänger wählen.', 'error'); return; }
        if (!type) { showToast('Benachrichtigungstyp wählen.', 'error'); return; }
        let data;
        try { data = JSON.parse(dataText); } catch (e) { showToast('Data muss gültiges JSON sein.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/notifications/send', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, type, title, body, data }),
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
        document.getElementById('notifData').value = '';
        document.getElementById('notifTitle').value = '';
        document.getElementById('notifBody').value = '';
        document.getElementById('entityGroup').style.display = 'none';
        updatePreview();
    }

    loadEntityData();
</script>
