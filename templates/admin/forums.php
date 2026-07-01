<div class="page-header">
    <div>
        <h1>Forenverwaltung</h1>
        <div class="subtitle">Foren anlegen, bearbeiten und löschen</div>
    </div>
    <button class="btn btn-primary" onclick="showCreateForm()">+ Neues Forum</button>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Beschreibung</th>
                <th>Mitglieder</th>
                <th>Erstellt am</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<!-- Erstellformular -->
<div id="createForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Neues Forum erstellen</h2>
    <form id="newForumForm" onsubmit="submitCreate(event)">
        <div class="form-group">
            <label for="newName">Name *</label>
            <input type="text" id="newName" name="name" placeholder="z. B. Musik-Tausch" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="newDescription">Beschreibung</label>
            <textarea id="newDescription" name="description" placeholder="Worum geht es in diesem Forum?"></textarea>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Erstellen</button>
            <button type="button" class="btn" onclick="hideCreateForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Bearbeitungsformular -->
<div id="editForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Forum bearbeiten</h2>
    <form id="editForumForm" onsubmit="submitEdit(event)">
        <input type="hidden" id="editId" name="id">
        <div class="form-group">
            <label for="editName">Name *</label>
            <input type="text" id="editName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="editDescription">Beschreibung</label>
            <textarea id="editDescription" name="description"></textarea>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="hideEditForm()">Abbrechen</button>
        </div>
    </form>
</div>

<script>
    function showCreateForm() {
        document.getElementById('createForm').style.display = 'block';
        document.getElementById('editForm').style.display = 'none';
        document.getElementById('newName').focus();
    }

    function hideCreateForm() {
        document.getElementById('createForm').style.display = 'none';
        document.getElementById('newForumForm').reset();
    }

    function showEditForm() {
        document.getElementById('editForm').style.display = 'block';
        document.getElementById('createForm').style.display = 'none';
    }

    function hideEditForm() {
        document.getElementById('editForm').style.display = 'none';
        document.getElementById('editForumForm').reset();
    }

    async function submitCreate(event) {
        event.preventDefault();
        const form = document.getElementById('newForumForm');
        const data = {
            name: form.name.value.trim(),
            description: form.description.value.trim() || null,
        };

        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/forums', {
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
                showToast('Forum erstellt');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Erstellen', 'error');
        }
    }

    function editForum(id, name, description) {
        document.getElementById('editId').value = id;
        document.getElementById('editName').value = name;
        document.getElementById('editDescription').value = description;
        showEditForm();
    }

    async function submitEdit(event) {
        event.preventDefault();
        const form = document.getElementById('editForumForm');
        const id = form.id.value;
        const data = {
            name: form.name.value.trim(),
            description: form.description.value.trim() || null,
        };

        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/forums/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Forum aktualisiert');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Speichern', 'error');
        }
    }

    async function deleteForum(id, name) {
        if (!confirm('Forum "' + name + '" wirklich löschen?\n\nAlle zugehörigen Posts, Kommentare und Mitgliedschaften werden ebenfalls gelöscht.')) {
            return;
        }

        try {
            const res = await fetch('/api/v2/admin/forums/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Forum gelöscht');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Löschen', 'error');
        }
    }
</script>
