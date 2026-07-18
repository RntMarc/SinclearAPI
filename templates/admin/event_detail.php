<div class="page-header">
    <div>
        <h1>{{eventName}}</h1>
        <div class="subtitle">{{eventDescription}}</div>
    </div>
    <div class="flex" style="gap:0.5rem;">
        <a href="/api/v2/admin/travel" class="btn" style="text-decoration:none;">← Zurück zur Übersicht</a>
        <button class="btn btn-sm btn-primary" onclick="toggleEditEventForm()">Event bearbeiten</button>
    </div>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex-between" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;color:#aaa;">Details</h2>
    </div>
    <table>
        <tr><td style="border:none;padding:0.3rem 0;color:#888;">Zeitraum</td><td style="border:none;padding:0.3rem 0;">{{eventStart}} – {{eventEnd}}</td></tr>
        <tr><td style="border:none;padding:0.3rem 0;color:#888;">Reise</td><td style="border:none;padding:0.3rem 0;">{{eventTrip}}</td></tr>
        <tr><td style="border:none;padding:0.3rem 0;color:#888;">Veranstalter</td><td style="border:none;padding:0.3rem 0;">{{eventOrganizer}}</td></tr>
        <tr><td style="border:none;padding:0.3rem 0;color:#888;">Adresse</td><td style="border:none;padding:0.3rem 0;">{{eventAddress}}</td></tr>
        {{eventDetailsExtras}}
    </table>
</div>

<!-- Edit Event Form (inline) -->
<div id="editEventForm" class="card mt-2" style="display:none;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Event bearbeiten</h2>
    <form id="editEventFormEl" onsubmit="submitEditEvent(event)">
        <input type="hidden" id="editEventId">
        <div class="form-group">
            <label for="editEventName">Name *</label>
            <input type="text" id="editEventName" name="name" required maxlength="255">
        </div>
        <div class="form-group">
            <label for="editEventDescription">Beschreibung</label>
            <textarea id="editEventDescription" name="description"></textarea>
        </div>
        <div class="form-group">
            <label for="editEventTrip">Reise</label>
            <select id="editEventTrip" name="trip">
                {{tripOptions}}
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventStart">Start * (UTC)</label>
                <input type="text" id="editEventStart" name="start" required>
            </div>
            <div class="form-group">
                <label for="editEventEnd">Ende * (UTC)</label>
                <input type="text" id="editEventEnd" name="end" required>
            </div>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" id="editEventHastickets" onchange="toggleEditEventTickets()">
                Tickets vorhanden
            </label>
        </div>
        <div id="editEventTicketFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label for="editEventTicket">Ticket-Info</label>
                    <input type="text" id="editEventTicket" name="ticket">
                </div>
                <div class="form-group">
                    <label for="editEventTicketUrl">Ticket-URL</label>
                    <input type="url" id="editEventTicketUrl" name="ticketUrl">
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventUrl">Event-URL</label>
                <input type="url" id="editEventUrl" name="url">
            </div>
            <div class="form-group">
                <label for="editEventImage">Bild-URL</label>
                <input type="url" id="editEventImage" name="image">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventOrganizer">Veranstalter</label>
                <input type="text" id="editEventOrganizer" name="organizer">
            </div>
            <div class="form-group">
                <label for="editEventAddress">Adresse</label>
                <input type="text" id="editEventAddress" name="address">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="editEventLatitude">Breitengrad</label>
                <input type="number" step="any" id="editEventLatitude" name="latitude">
            </div>
            <div class="form-group">
                <label for="editEventLongitude">Längengrad</label>
                <input type="number" step="any" id="editEventLongitude" name="longitude">
            </div>
        </div>
        <div class="form-group">
            <label for="editEventOSMID">OpenStreetMap ID</label>
            <input type="number" id="editEventOSMID" name="OSMID">
        </div>
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-primary">Speichern</button>
            <button type="button" class="btn" onclick="toggleEditEventForm()">Abbrechen</button>
        </div>
    </form>
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
                <th>Hinzugefügt am</th>
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
        <div class="flex" style="gap:0.5rem;">
            <button type="submit" class="btn btn-success">Hinzufügen</button>
            <button type="button" class="btn" onclick="hideAddParticipantForm()">Abbrechen</button>
        </div>
    </form>
</div>

<script>
    const eventId = '{{eventId}}';
    const eventEditData = {{eventEditData}};

    function toggleEditEventForm() {
        const form = document.getElementById('editEventForm');
        if (form.style.display === 'none') {
            populateEditForm();
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth' });
        } else {
            form.style.display = 'none';
        }
    }

    function populateEditForm() {
        const d = eventEditData;
        document.getElementById('editEventId').value = d.id;
        document.getElementById('editEventName').value = d.name;
        document.getElementById('editEventDescription').value = d.description;
        document.getElementById('editEventTrip').value = d.trip || '';
        document.getElementById('editEventStart').value = d.start;
        document.getElementById('editEventEnd').value = d.end;
        const hasTickets = d.hastickets === '1';
        document.getElementById('editEventHastickets').checked = hasTickets;
        document.getElementById('editEventTicketFields').style.display = hasTickets ? 'block' : 'none';
        document.getElementById('editEventTicket').value = d.ticket || '';
        document.getElementById('editEventTicketUrl').value = d.ticketUrl || '';
        document.getElementById('editEventUrl').value = d.url || '';
        document.getElementById('editEventImage').value = d.image || '';
        document.getElementById('editEventOrganizer').value = d.organizer || '';
        document.getElementById('editEventAddress').value = d.address || '';
        document.getElementById('editEventLatitude').value = d.latitude || '';
        document.getElementById('editEventLongitude').value = d.longitude || '';
        document.getElementById('editEventOSMID').value = d.OSMID || '';
    }

    function toggleEditEventTickets() {
        document.getElementById('editEventTicketFields').style.display =
            document.getElementById('editEventHastickets').checked ? 'block' : 'none';
    }

    async function submitEditEvent(event) {
        event.preventDefault();
        const id = document.getElementById('editEventId').value;
        const data = {
            name: document.getElementById('editEventName').value.trim(),
            description: document.getElementById('editEventDescription').value.trim() || null,
            trip: document.getElementById('editEventTrip').value || null,
            start: document.getElementById('editEventStart').value.trim(),
            end: document.getElementById('editEventEnd').value.trim(),
            hastickets: document.getElementById('editEventHastickets').checked ? '1' : '0',
            ticket: document.getElementById('editEventTicket').value.trim() || null,
            ticketUrl: document.getElementById('editEventTicketUrl').value.trim() || null,
            url: document.getElementById('editEventUrl').value.trim() || null,
            image: document.getElementById('editEventImage').value.trim() || null,
            organizer: document.getElementById('editEventOrganizer').value.trim() || null,
            address: document.getElementById('editEventAddress').value.trim() || null,
            latitude: document.getElementById('editEventLatitude').value.trim() || null,
            longitude: document.getElementById('editEventLongitude').value.trim() || null,
            OSMID: document.getElementById('editEventOSMID').value.trim() || null,
        };
        if (!data.name) { showToast('Name ist erforderlich.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/events/' + id, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Event aktualisiert'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Speichern', 'error'); }
    }

    function showAddParticipantForm() {
        document.getElementById('addParticipantForm').style.display = 'block';
        document.getElementById('addParticipantUser').focus();
    }
    function hideAddParticipantForm() {
        document.getElementById('addParticipantForm').style.display = 'none';
        document.getElementById('addParticipantFormEl').reset();
    }

    async function submitAddParticipant(event) {
        event.preventDefault();
        const userId = document.getElementById('addParticipantUser').value;
        if (!userId) { showToast('Bitte einen Benutzer wählen.', 'error'); return; }

        try {
            const res = await fetch('/api/v2/admin/travel/events/' + eventId + '/participants', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userId }),
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Teilnehmer hinzugefügt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Hinzufügen', 'error'); }
    }

    async function removeParticipant(userId, displayName) {
        if (!confirm('Teilnehmer "' + displayName + '" wirklich von diesem Event entfernen?')) return;
        try {
            const res = await fetch('/api/v2/admin/travel/events/' + eventId + '/participants/' + userId, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) { window.location.href = '/api/v2/admin/login'; return; }
            if (res.ok) { showToast('Teilnehmer entfernt'); setTimeout(() => window.location.reload(), 500); }
            else { const err = await res.json(); showToast('Fehler: ' + (err.error || 'unbekannt'), 'error'); }
        } catch (e) { showToast('Fehler beim Entfernen', 'error'); }
    }
</script>
