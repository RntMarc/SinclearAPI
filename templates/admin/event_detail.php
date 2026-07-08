<div class="page-header">
    <div>
        <h1>{{eventName}}</h1>
        <div class="subtitle">{{eventDescription}}</div>
    </div>
    <div class="flex" style="gap:0.5rem;">
        <a href="/api/v2/admin/travel" class="btn" style="text-decoration:none;">← Zurück zur Übersicht</a>
        <button class="btn btn-sm btn-primary" onclick="editEventFromDetail()">Event bearbeiten</button>
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

<div class="card">
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

    function showAddParticipantForm() {
        document.getElementById('addParticipantForm').style.display = 'block';
        document.getElementById('addParticipantUser').focus();
    }
    function hideAddParticipantForm() {
        document.getElementById('addParticipantForm').style.display = 'none';
        document.getElementById('addParticipantFormEl').reset();
    }

    function editEventFromDetail() {
        const d = eventEditData;
        // Navigate to travel page with edit event pre-filled
        window.location.href = '/api/v2/admin/travel#edit-event-' + eventId;
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
