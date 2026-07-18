<div class="page-header">
    <div>
        <h1>{{subscriptionName}}</h1>
        <div class="subtitle">Abo-Details</div>
    </div>
    <a href="/api/v2/admin/subscriptions" class="btn">← Zurück</a>
</div>

<div class="card-grid mb-2">
    <div class="card stat-card">
        <div class="number">{{basePrice}} €</div>
        <div class="label">Grundpreis</div>
    </div>
    <div class="card stat-card">
        <div class="number">{{billingPeriodStart}}</div>
        <div class="label">Abrechnungszeitraum Beginn</div>
    </div>
    <div class="card stat-card">
        <div class="number">{{billingPeriodEnd}}</div>
        <div class="label">Abrechnungszeitraum Ende</div>
    </div>
</div>

<div class="card">
    <div class="flex-between mb-2">
        <h2>Teilnehmer</h2>
        <button class="btn btn-primary" onclick="showAddParticipantModal()">+ Teilnehmer hinzufügen</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name / Nutzer-ID</th>
                <th>Typ</th>
                <th>Bezahltstatus</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{participantRows}}
        </tbody>
    </table>
</div>

<div id="addParticipantModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center;">
    <div class="card" style="width:500px; max-width:90vw;">
        <h2 style="margin-bottom:1.5rem;">Teilnehmer hinzufügen</h2>
        <form id="addParticipantForm" onsubmit="return addParticipant(event)">
            <div class="form-group">
                <label for="participantName">Name (für Nicht-Nutzer)</label>
                <input type="text" id="participantName">
            </div>
            <div class="form-group">
                <label for="participantUserId">Nutzer-ID (falls bekannt)</label>
                <input type="text" id="participantUserId">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="participantPaid"> Bereits bezahlt
                </label>
            </div>
            <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1.5rem;">
                <button type="button" class="btn" onclick="hideAddParticipantModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

<script>
    const subscriptionId = '{{subscriptionId}}';

    function showAddParticipantModal() {
        document.getElementById('addParticipantModal').style.display = 'flex';
    }

    function hideAddParticipantModal() {
        document.getElementById('addParticipantModal').style.display = 'none';
        document.getElementById('addParticipantForm').reset();
    }

    async function addParticipant(event) {
        event.preventDefault();
        const name = document.getElementById('participantName').value.trim();
        const userId = document.getElementById('participantUserId').value.trim();
        const hasPaid = document.getElementById('participantPaid').checked;

        if (!name && !userId) {
            showToast('Name oder Nutzer-ID angeben', 'error');
            return false;
        }

        const data = { hasPaid };
        if (userId) data.userId = userId;
        if (name) data.userName = name;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subscriptionId + '/participants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (response.ok) {
                showToast('Teilnehmer hinzugefügt');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }

        return false;
    }

    async function togglePaidStatus(participantId, currentStatus) {
        const newStatus = !currentStatus;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subscriptionId + '/participants/' + participantId, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hasPaid: newStatus }),
            });

            if (response.ok) {
                showToast(newStatus ? 'Als bezahlt markiert' : 'Als offen markiert');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }
    }

    async function removeParticipant(subId, participantId) {
        if (!confirm('Teilnehmer wirklich entfernen?')) return;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subId + '/participants/' + participantId, {
                method: 'DELETE',
            });

            if (response.ok) {
                showToast('Teilnehmer entfernt');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }
    }
</script>
