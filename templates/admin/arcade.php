<div class="page-header">
    <div>
        <h1>Arcade-Spiele</h1>
        <div class="subtitle">Selbst gehostete Browser-Spiele verwalten (Katalog, Score-Richtung, Sichtbarkeit)</div>
    </div>
    <button class="btn btn-primary" onclick="showArcadeCreateForm()">+ Neues Spiel</button>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Titel</th>
                <th>Slug</th>
                <th>Beschreibung</th>
                <th>Thumbnail</th>
                <th>Score-Richtung</th>
                <th>Status</th>
                <th>Sortierung</th>
                <th>Play-URL</th>
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
<div id="arcadeCreateForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Neues Arcade-Spiel anlegen</h2>
    <form id="newArcadeForm" onsubmit="submitArcadeCreate(event)">
        <div class="form-row">
            <div class="form-group">
                <label for="newArcadeSlug">Slug *</label>
                <input type="text" id="newArcadeSlug" name="slug" required maxlength="50"
                       pattern="[a-z0-9](?:[a-z0-9\-]{0,48}[a-z0-9])?"
                       placeholder="z. B. snake">
                <small style="color:#888;">Verzeichnis unter <code>public/arcade/&lt;slug&gt;/</code>. Nur Kleinbuchstaben, Ziffern und Bindestriche.</small>
            </div>
            <div class="form-group">
                <label for="newArcadeTitle">Titel *</label>
                <input type="text" id="newArcadeTitle" name="title" required maxlength="191">
            </div>
        </div>
        <div class="form-group">
            <label for="newArcadeDescription">Beschreibung</label>
            <textarea id="newArcadeDescription" name="description" placeholder="Kurzbeschreibung für den Katalog"></textarea>
        </div>
        <div class="form-group">
            <label for="newArcadeThumbnailUrl">Thumbnail-URL</label>
            <input type="text" id="newArcadeThumbnailUrl" name="thumbnailUrl" maxlength="1024"
                   placeholder="https://… oder /arcade/&lt;slug&gt;/thumbnail.png">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newArcadeScoreDirection">Score-Richtung *</label>
                <select id="newArcadeScoreDirection" name="scoreDirection" required>
                    <option value="desc">desc – größer ist besser</option>
                    <option value="asc">asc – kleiner ist besser</option>
                </select>
            </div>
            <div class="form-group">
                <label for="newArcadeSortOrder">Sortierung</label>
                <input type="number" id="newArcadeSortOrder" name="sortOrder" value="0" step="1">
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="newArcadeIsActive" name="isActive" checked style="width:auto;">
                Aktiv (im Katalog sichtbar)
            </label>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Erstellen</button>
            <button type="button" class="btn" onclick="hideArcadeCreateForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Bearbeitungsformular -->
<div id="arcadeEditForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Arcade-Spiel bearbeiten</h2>
    <form id="editArcadeForm" onsubmit="submitArcadeEdit(event)">
        <input type="hidden" id="editArcadeId" name="id">
        <div class="form-row">
            <div class="form-group">
                <label for="editArcadeSlug">Slug *</label>
                <input type="text" id="editArcadeSlug" name="slug" required maxlength="50"
                       pattern="[a-z0-9](?:[a-z0-9\-]{0,48}[a-z0-9])?">
            </div>
            <div class="form-group">
                <label for="editArcadeTitle">Titel *</label>
                <input type="text" id="editArcadeTitle" name="title" required maxlength="191">
            </div>
        </div>
        <div class="form-group">
            <label for="editArcadeDescription">Beschreibung</label>
            <textarea id="editArcadeDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label for="editArcadeThumbnailUrl">Thumbnail-URL</label>
            <input type="text" id="editArcadeThumbnailUrl" name="thumbnailUrl" maxlength="1024">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editArcadeScoreDirection">Score-Richtung *</label>
                <select id="editArcadeScoreDirection" name="scoreDirection" required>
                    <option value="desc">desc – größer ist besser</option>
                    <option value="asc">asc – kleiner ist besser</option>
                </select>
            </div>
            <div class="form-group">
                <label for="editArcadeSortOrder">Sortierung</label>
                <input type="number" id="editArcadeSortOrder" name="sortOrder" step="1">
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editArcadeIsActive" name="isActive" style="width:auto;">
                Aktiv (im Katalog sichtbar)
            </label>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="hideArcadeEditForm()">Abbrechen</button>
        </div>
    </form>
</div>

<script>
    const arcadeGames = {{gamesJson}};

    function findArcadeGame(id) {
        return arcadeGames.find(function (game) { return game.id === id; }) || null;
    }

    function showArcadeCreateForm() {
        document.getElementById('arcadeCreateForm').style.display = 'block';
        document.getElementById('arcadeEditForm').style.display = 'none';
        document.getElementById('newArcadeSlug').focus();
    }

    function hideArcadeCreateForm() {
        document.getElementById('arcadeCreateForm').style.display = 'none';
        document.getElementById('newArcadeForm').reset();
    }

    function hideArcadeEditForm() {
        document.getElementById('arcadeEditForm').style.display = 'none';
        document.getElementById('editArcadeForm').reset();
    }

    function editArcadeGame(id) {
        const game = findArcadeGame(id);
        if (!game) { showToast('Spiel nicht gefunden.', 'error'); return; }

        document.getElementById('arcadeCreateForm').style.display = 'none';
        document.getElementById('arcadeEditForm').style.display = 'block';
        document.getElementById('editArcadeId').value = game.id;
        document.getElementById('editArcadeSlug').value = game.slug;
        document.getElementById('editArcadeTitle').value = game.title;
        document.getElementById('editArcadeDescription').value = game.description || '';
        document.getElementById('editArcadeThumbnailUrl').value = game.thumbnailUrl || '';
        document.getElementById('editArcadeScoreDirection').value = game.scoreDirection;
        document.getElementById('editArcadeSortOrder').value = game.sortOrder;
        document.getElementById('editArcadeIsActive').checked = !!game.isActive;
        document.getElementById('editArcadeSlug').focus();
    }

    function readArcadeForm(form) {
        const field = function (name) { return form.elements.namedItem(name); };
        return {
            slug: field('slug').value.trim(),
            title: field('title').value.trim(),
            description: field('description').value.trim() || null,
            thumbnailUrl: field('thumbnailUrl').value.trim() || null,
            scoreDirection: field('scoreDirection').value,
            isActive: field('isActive').checked,
            sortOrder: field('sortOrder').value === '' ? 0 : Number(field('sortOrder').value),
        };
    }

    async function submitArcadeCreate(event) {
        event.preventDefault();
        const data = readArcadeForm(document.getElementById('newArcadeForm'));

        if (!data.slug) { showToast('Slug ist erforderlich.', 'error'); return; }
        if (!data.title) { showToast('Titel ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/arcade', {
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
                showToast('Spiel erstellt');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Erstellen', 'error');
        }
    }

    async function submitArcadeEdit(event) {
        event.preventDefault();
        const form = document.getElementById('editArcadeForm');
        const id = form.elements.namedItem('id').value;
        const data = readArcadeForm(form);

        if (!data.slug) { showToast('Slug ist erforderlich.', 'error'); return; }
        if (!data.title) { showToast('Titel ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/arcade/' + encodeURIComponent(id), {
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
                showToast('Spiel aktualisiert');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Speichern', 'error');
        }
    }

    async function deleteArcadeGame(id) {
        const game = findArcadeGame(id);
        if (!game) { showToast('Spiel nicht gefunden.', 'error'); return; }

        if (!confirm('Spiel "' + game.title + '" wirklich löschen?\n\nSessions und Score-Historie dieses Spiels werden ebenfalls gelöscht. Die Spiel-Dateien unter public/arcade/' + game.slug + '/ müssen separat entfernt werden.')) {
            return;
        }

        try {
            const res = await fetch('/api/v2/admin/arcade/' + encodeURIComponent(id), {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Spiel gelöscht');
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
