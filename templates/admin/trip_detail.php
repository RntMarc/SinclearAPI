<div class="page-header">
    <div>
        <h1>{{tripName}}</h1>
        <div class="subtitle">{{tripDescription}}</div>
    </div>
    <a href="/api/v2/admin/travel" class="btn" style="text-decoration:none;">← Zurück zur Übersicht</a>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Zeitraum</h2>
    </div>
    <p>{{tripStart}} – {{tripEnd}}</p>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Teilnehmer ({{participantCount}})</h2>
        <button class="btn btn-primary" onclick="showAddParticipantForm()">+ Teilnehmer hinzufügen</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Benutzer</th>
                <th>E-Mail</th>
                <th>Unterkunft</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{participantRows}}
        </tbody>
    </table>
</div>

<!-- Add Participant Form -->
<div id="addParticipantForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Teilnehmer hinzufügen</h2>
    <form id="addParticipantFormEl" onsubmit="submitAddParticipant(event)">
        <div class="form-group">
            <label for="addParticipantUser">Benutzer *</label>
            <select id="addParticipantUser" name="userId" required>
                <option value="">– Bitte wählen –</option>
                {{userOptions}}
            </select>
        </div>
        <div class="form-group">
            <label for="addParticipantAccommodation">Unterkunft (optional)</label>
            <select id="addParticipantAccommodation" name="accommodation">
                <option value="">– Keine –</option>
                {{accommodationOptions}}
            </select>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Hinzufügen</button>
            <button type="button" class="btn" onclick="hideAddParticipantForm()">Abbrechen</button>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Unterkünfte</h2>
        <button class="btn btn-primary" onclick="showCreateAccommodationForm()">+ Unterkunft erstellen</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Adresse</th>
                <th>Typ</th>
                <th>Kontakt</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{accommodationRows}}
        </tbody>
    </table>
</div>

<!-- Create Accommodation Form -->
<div id="createAccommodationForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Unterkunft erstellen</h2>
    <form id="createAccommodationFormEl" onsubmit="submitCreateAccommodation(event)">
        <div class="form-group">
            <label for="newAccName">Name *</label>
            <input type="text" id="newAccName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="newAccDescription">Beschreibung</label>
            <textarea id="newAccDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label for="newAccAddress">Adresse</label>
            <input type="text" id="newAccAddress" name="address">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newAccPhone">Telefon</label>
                <input type="text" id="newAccPhone" name="phone">
            </div>
            <div class="form-group">
                <label for="newAccMail">E-Mail</label>
                <input type="email" id="newAccMail" name="mail">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newAccLatitude">Breitengrad</label>
                <input type="number" step="any" id="newAccLatitude" name="latitude">
            </div>
            <div class="form-group">
                <label for="newAccLongitude">Längengrad</label>
                <input type="number" step="any" id="newAccLongitude" name="longitude">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="newAccOSMID">OpenStreetMap ID</label>
                <input type="number" id="newAccOSMID" name="OSMID">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="newAccIshotel" checked>
                    Ist ein Hotel
                </label>
            </div>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Erstellen</button>
            <button type="button" class="btn" onclick="hideCreateAccommodationForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Edit Accommodation Form -->
<div id="editAccommodationForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Unterkunft bearbeiten</h2>
    <form id="editAccommodationFormEl" onsubmit="submitEditAccommodation(event)">
        <input type="hidden" id="editAccId">
        <div class="form-group">
            <label for="editAccName">Name *</label>
            <input type="text" id="editAccName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="editAccDescription">Beschreibung</label>
            <textarea id="editAccDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label for="editAccAddress">Adresse</label>
            <input type="text" id="editAccAddress" name="address">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editAccPhone">Telefon</label>
                <input type="text" id="editAccPhone" name="phone">
            </div>
            <div class="form-group">
                <label for="editAccMail">E-Mail</label>
                <input type="email" id="editAccMail" name="mail">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editAccLatitude">Breitengrad</label>
                <input type="number" step="any" id="editAccLatitude" name="latitude">
            </div>
            <div class="form-group">
                <label for="editAccLongitude">Längengrad</label>
                <input type="number" step="any" id="editAccLongitude" name="longitude">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editAccOSMID">OpenStreetMap ID</label>
                <input type="number" id="editAccOSMID" name="OSMID">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="editAccIshotel">
                    Ist ein Hotel
                </label>
            </div>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="hideEditAccommodationForm()">Abbrechen</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Events dieser Reise</h2>
    </div>
    {{eventSection}}
</div>

<script>
    // Trip ID stored for API calls
    const tripId = '{{tripId}}';

    // Participant form toggles
    function showAddParticipantForm() {
        hideCreateAccommodationForm(); hideEditAccommodationForm();
        document.getElementById('addParticipantForm').style.display = 'block';
        document.getElementById('addParticipantUser').focus();
    }
    function hideAddParticipantForm() {
        document.getElementById('addParticipantForm').style.display = 'none';
        document.getElementById('addParticipantFormEl').reset();
    }

    // Accommodation form toggles
    function showCreateAccommodationForm() {
        hideAddParticipantForm(); hideEditAccommodationForm();
        document.getElementById('createAccommodationForm').style.display = 'block';
        document.getElementById('newAccName').focus();
    }
    function hideCreateAccommodationForm() {
        document.getElementById('createAccommodationForm').style.display = 'none';
        document.getElementById('createAccommodationFormEl').reset();
    }
    function showEditAccommodationForm() {
        hideAddParticipantForm(); hideCreateAccommodationForm();
        document.getElementById('editAccommodationForm').style.display = 'block';
    }
    function hideEditAccommodationForm() {
        document.getElementById('editAccommodationForm').style.display = 'none';
        document.getElementById('editAccommodationFormEl').reset();
    }

    // Participant CRUD
    async function submitAddParticipant(event) {
        event.preventDefault();
        const userId = document.getElementById('addParticipantUser').value;
        const accommodation = document.getElementById('addParticipantAccommodation').value || null;
        if (!userId) { showToast('Bitte einen Benutzer wählen.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/participants', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId, accommodation }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Teilnehmer hinzugefügt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Hinzufügen', 'error'); }
    }

    async function removeParticipant(userId, displayName) {
        if (!confirm('Teilnehmer "' + displayName + '" wirklich von dieser Reise entfernen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/participants/' + userId, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Teilnehmer entfernt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Entfernen', 'error'); }
    }

    async function changeAccommodation(userId, selectEl) {
        const accommodation = selectEl.value || null;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/participants/' + userId + '/accommodation', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ accommodation }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Unterkunft aktualisiert'); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Ändern', 'error'); }
    }

    // Accommodation CRUD
    async function submitCreateAccommodation(event) {
        event.preventDefault();
        const data = {
            name: document.getElementById('newAccName').value.trim(),
            description: document.getElementById('newAccDescription').value.trim() || null,
            address: document.getElementById('newAccAddress').value.trim() || null,
            phone: document.getElementById('newAccPhone').value.trim() || null,
            mail: document.getElementById('newAccMail').value.trim() || null,
            latitude: document.getElementById('newAccLatitude').value.trim() || null,
            longitude: document.getElementById('newAccLongitude').value.trim() || null,
            OSMID: document.getElementById('newAccOSMID').value.trim() || null,
            ishotel: document.getElementById('newAccIshotel').checked ? 1 : 0,
        };
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/accommodations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Unterkunft erstellt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Erstellen', 'error'); }
    }

    function editAccommodation(id, name, description, address, phone, mail, latitude, longitude, OSMID, ishotel) {
        document.getElementById('editAccId').value = id;
        document.getElementById('editAccName').value = name;
        document.getElementById('editAccDescription').value = description;
        document.getElementById('editAccAddress').value = address;
        document.getElementById('editAccPhone').value = phone || '';
        document.getElementById('editAccMail').value = mail || '';
        document.getElementById('editAccLatitude').value = latitude || '';
        document.getElementById('editAccLongitude').value = longitude || '';
        document.getElementById('editAccOSMID').value = OSMID || '';
        document.getElementById('editAccIshotel').checked = ishotel == 1;
        showEditAccommodationForm();
    }

    async function submitEditAccommodation(event) {
        event.preventDefault();
        const id = document.getElementById('editAccId').value;
        const data = {
            name: document.getElementById('editAccName').value.trim(),
            description: document.getElementById('editAccDescription').value.trim() || null,
            address: document.getElementById('editAccAddress').value.trim() || null,
            phone: document.getElementById('editAccPhone').value.trim() || null,
            mail: document.getElementById('editAccMail').value.trim() || null,
            latitude: document.getElementById('editAccLatitude').value.trim() || null,
            longitude: document.getElementById('editAccLongitude').value.trim() || null,
            OSMID: document.getElementById('editAccOSMID').value.trim() || null,
            ishotel: document.getElementById('editAccIshotel').checked ? 1 : 0,
        };
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/accommodations/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Unterkunft aktualisiert'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Speichern', 'error'); }
    }

    async function deleteAccommodation(id, name) {
        if (!confirm('Unterkunft "' + name + '" wirklich löschen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/accommodations/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Unterkunft gelöscht'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Löschen', 'error'); }
    }
</script>
