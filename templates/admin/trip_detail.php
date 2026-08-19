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

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Verknüpftes Forum</h2>
        <button class="btn btn-primary" onclick="showLinkForumForm()">Forum ändern</button>
    </div>
    {{forumInfo}}
    <p style="color:#666;{{noForumTextStyle}}" id="noForumText">Kein Forum verknüpft.</p>
</div>

<!-- Link Forum Form -->
<div id="linkForumForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Forum verknüpfen</h2>
    <form id="linkForumFormEl" onsubmit="submitLinkForum(event)">
        <div class="form-group">
            <label for="forumSelect">Forum</label>
            <select id="forumSelect" name="forumId">
                {{forumOptions}}
            </select>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Speichern</button>
            <button type="button" class="btn" onclick="hideLinkForumForm()">Abbrechen</button>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Chat</h2>
    </div>
    {{chatInfo}}
    <p style="color:#666;{{noChatTextStyle}}" id="noChatText">Kein Gruppenchat vorhanden.</p>
    <button class="btn btn-primary" id="createChatBtn" onclick="createTripChat()" style="{{createChatBtnStyle}}">Gruppenchat erstellen</button>
    <div id="chatIconSection" style="{{chatIconSectionStyle}}">
        <div style="border-top:1px solid #333;padding-top:1rem;margin-top:1rem;">
            <label style="color:#888;font-size:0.85rem;display:block;margin-bottom:0.5rem;">Chat-Bild</label>
            <div class="flex" style="gap:0.5rem;align-items:center;">
                <div id="chatIconPreview" style="width:64px;height:64px;border-radius:12px;overflow:hidden;background:#1a1a2e;border:2px solid #333;flex-shrink:0;">{{chatIconPreviewHtml}}</div>
                <div>
                    <input type="file" id="chatIconFile" accept="image/jpeg,image/png,image/webp" onchange="handleChatIconFile(this)" style="display:none;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('chatIconFile').click()">Bild hochladen</button>
                    <button type="button" class="btn btn-sm btn-danger" id="removeChatIconBtn" onclick="removeChatIcon()" style="{{removeChatIconBtnStyle}}">Entfernen</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Verknüpfte Abos</h2>
        <button class="btn btn-primary" onclick="showLinkSubscriptionForm()">+ Abo verknüpfen</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>ID</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{subscriptionRows}}
        </tbody>
    </table>
    <p style="color:#666;" id="noSubscriptionText">Keine Abos verknüpft.</p>
</div>

<!-- Link Subscription Form -->
<div id="linkSubscriptionForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Abo verknüpfen</h2>
    <form id="linkSubscriptionFormEl" onsubmit="submitLinkSubscription(event)">
        <div class="form-group">
            <label for="subscriptionSelect">Abo *</label>
            <select id="subscriptionSelect" name="subscriptionId" required>
                <option value="">– Bitte wählen –</option>
                {{availableSubscriptionOptions}}
            </select>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Verknüpfen</button>
            <button type="button" class="btn" onclick="hideLinkSubscriptionForm()">Abbrechen</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Events dieser Reise ({{tripEventCount}})</h2>
        <button class="btn btn-primary" onclick="showLinkEventForm()">+ Event verknüpfen</button>
    </div>
    {{tripEventRows}}
</div>

<!-- Link Event Form -->
<div id="linkEventForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Event verknüpfen</h2>
    <form id="linkEventFormEl" onsubmit="submitLinkEvent(event)">
        <div class="form-group">
            <label for="linkEventSelect">Event *</label>
            <select id="linkEventSelect" name="eventId" required>
                <option value="">– Bitte wählen –</option>
                {{availableEventOptions}}
            </select>
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Verknüpfen</button>
            <button type="button" class="btn" onclick="hideLinkEventForm()">Abbrechen</button>
        </div>
    </form>
</div>

<!-- Crop Modal -->
<div id="cropModal" class="modal" onclick="if(event.target===this)cancelCrop()">
    <div class="card" style="max-width:700px;">
        <h2 id="cropModalTitle" style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Bild zuschneiden</h2>
        <div style="max-height:400px;overflow:hidden;margin-bottom:1rem;">
            <img id="cropImage" src="" style="max-width:100%;display:block;">
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="button" class="btn btn-success" onclick="confirmCrop()">Zuschneiden & Übernehmen</button>
            <button type="button" class="btn" onclick="cancelCrop()">Abbrechen</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
    // Trip ID stored for API calls
    const tripId = '{{tripId}}';
    let cropper = null;
    let cropResolve = null;

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

    // Event link/unlink
    function showLinkEventForm() {
        hideAddParticipantForm(); hideCreateAccommodationForm(); hideEditAccommodationForm();
        document.getElementById('linkEventForm').style.display = 'block';
        document.getElementById('linkEventSelect').focus();
    }
    function hideLinkEventForm() {
        document.getElementById('linkEventForm').style.display = 'none';
        document.getElementById('linkEventFormEl').reset();
    }

    async function submitLinkEvent(event) {
        event.preventDefault();
        const eventId = document.getElementById('linkEventSelect').value;
        if (!eventId) { showToast('Bitte ein Event wählen.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/events/' + eventId, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ trip: tripId }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event verknüpft'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Verknüpfen', 'error'); }
    }

    // Forum link/unlink
    function showLinkForumForm() {
        document.getElementById('linkForumForm').style.display = 'block';
    }
    function hideLinkForumForm() {
        document.getElementById('linkForumForm').style.display = 'none';
        document.getElementById('linkForumFormEl').reset();
    }

    async function submitLinkForum(event) {
        event.preventDefault();
        const forumId = document.getElementById('forumSelect').value || null;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/forum', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ forumId }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Forum verknüpft'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Verknüpfen', 'error'); }
    }

    async function unlinkForum() {
        if (!confirm('Forum wirklich von dieser Reise trennen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/forum', {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ forumId: null }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Forum getrennt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Trennen', 'error'); }
    }

    // Subscription link/unlink
    function showLinkSubscriptionForm() {
        document.getElementById('linkSubscriptionForm').style.display = 'block';
    }
    function hideLinkSubscriptionForm() {
        document.getElementById('linkSubscriptionForm').style.display = 'none';
        document.getElementById('linkSubscriptionFormEl').reset();
    }

    async function submitLinkSubscription(event) {
        event.preventDefault();
        const subscriptionId = document.getElementById('subscriptionSelect').value;
        if (!subscriptionId) { showToast('Bitte ein Abo wählen.', 'error'); return; }
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/subscriptions', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ subscriptionId }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Abo verknüpft'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Verknüpfen', 'error'); }
    }

    async function unlinkSubscription(subscriptionId) {
        if (!confirm('Abo wirklich von dieser Reise trennen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/subscriptions/' + subscriptionId, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Abo getrennt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Trennen', 'error'); }
    }

    async function unlinkEvent(eventId, eventName) {
        if (!confirm('Event "' + eventName + '" wirklich von dieser Reise trennen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/events/' + eventId, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ trip: null }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event getrennt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Trennen', 'error'); }
    }

    // Travel Chat
    async function createTripChat() {
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/chat', {
                method: 'POST',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok || res.status === 201) { showToast('Gruppenchat erstellt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Erstellen', 'error'); }
    }

    async function deleteTripChat() {
        if (!confirm('Gruppenchat wirklich löschen? Alle Nachrichten gehen verloren.')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/chat', {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Gruppenchat gelöscht'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Löschen', 'error'); }
    }

    // Chat icon
    const ICON_MAX_BYTES = 200 * 1024;
    let cropAspectRatio = 1 / 1;
    let cropOutputWidth = 512;
    let cropOutputHeight = 512;
    let cropMaxBytes = ICON_MAX_BYTES;
    let cropRatioLabel = '1:1';

    function handleChatIconFile(input) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            openCropModal(e.target.result).then(function(croppedBase64) {
                saveChatIcon(croppedBase64);
            }).catch(function() {
                input.value = '';
            });
        };
        reader.readAsDataURL(file);
    }

    async function saveChatIcon(base64) {
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/chat', {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: base64 }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) {
                document.getElementById('chatIconPreview').innerHTML = '<img src="data:image/jpeg;base64,' + base64 + '" style="width:100%;height:100%;object-fit:cover;">';
                document.getElementById('removeChatIconBtn').style.display = 'inline-flex';
                showToast('Chat-Bild gespeichert');
            } else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Speichern', 'error'); }
    }

    async function removeChatIcon() {
        try {
            const res = await fetch('/api/v2/admin/travel/trips/' + tripId + '/chat', {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: null }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) {
                document.getElementById('chatIconPreview').innerHTML = '';
                document.getElementById('removeChatIconBtn').style.display = 'none';
                document.getElementById('chatIconFile').value = '';
                showToast('Chat-Bild entfernt');
            } else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Entfernen', 'error'); }
    }

    // Crop modal
    function openCropModal(imageSrc, options) {
        if (options) {
            cropAspectRatio = options.aspectRatio ?? cropAspectRatio;
            cropOutputWidth = options.outputWidth ?? cropOutputWidth;
            cropOutputHeight = options.outputHeight ?? cropOutputHeight;
            cropMaxBytes = options.maxBytes ?? cropMaxBytes;
            cropRatioLabel = options.ratioLabel ?? cropRatioLabel;
        }
        return new Promise(function(resolve, reject) {
            cropResolve = { resolve: resolve, reject: reject };
            const modal = document.getElementById('cropModal');
            const img = document.getElementById('cropImage');
            const title = document.getElementById('cropModalTitle');
            img.src = imageSrc;
            if (title) title.textContent = 'Bild zuschneiden (' + cropRatioLabel + ')';
            modal.style.display = 'flex';
            if (cropper) { cropper.destroy(); cropper = null; }
            setTimeout(function() {
                cropper = new Cropper(img, {
                    aspectRatio: cropAspectRatio,
                    viewMode: 1,
                    autoCropArea: 1,
                    responsive: true,
                    background: false,
                });
            }, 100);
        });
    }

    function confirmCrop() {
        if (!cropper) return;
        const canvas = cropper.getCroppedCanvas({
            width: cropOutputWidth,
            height: cropOutputHeight,
            imageSmoothingQuality: 'high',
        });
        const base64 = compressToMaxBytes(canvas, cropMaxBytes);
        destroyCropModal();
        if (cropResolve) { cropResolve.resolve(base64); cropResolve = null; }
    }

    function cancelCrop() {
        const resolver = cropResolve;
        cropResolve = null;
        destroyCropModal();
        if (resolver) resolver.reject();
    }

    function destroyCropModal() {
        document.getElementById('cropModal').style.display = 'none';
        if (cropper) { cropper.destroy(); cropper = null; }
        document.getElementById('cropImage').src = '';
    }

    function closeCropModal() { cancelCrop(); }

    function compressToMaxBytes(canvas, maxBytes) {
        let quality = 0.9;
        let dataUrl = canvas.toDataURL('image/jpeg', quality);
        let base64 = dataUrl.split(',')[1];
        while (base64.length * 3 / 4 > maxBytes && quality > 0.3) {
            quality -= 0.1;
            dataUrl = canvas.toDataURL('image/jpeg', quality);
            base64 = dataUrl.split(',')[1];
        }
        return base64;
    }
</script>
