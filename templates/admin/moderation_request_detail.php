<div class="page-header">
    <div>
        <h1>{{requestType}} – {{objectType}}</h1>
        <div class="subtitle">Erstellt am {{createdAt}} · Aktualisiert am {{updatedAt}}</div>
    </div>
    <a href="/api/v2/admin/moderation-requests" class="btn">← Zurück</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="card">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Details</h2>
        <table>
            <tr><td style="color:#888;">Absender</td><td>{{requester}}</td></tr>
            <tr><td style="color:#888;">Absender-ID</td><td><code>{{requesterId}}</code></td></tr>
            <tr><td style="color:#888;">Anfrage-Art</td><td>{{requestType}}</td></tr>
            <tr><td style="color:#888;">Objekt-Typ</td><td>{{objectType}}</td></tr>
            <tr><td style="color:#888;">Objekt</td><td>{{objectLink}}</td></tr>
        </table>
    </div>
    <div class="card">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Anliegen des Nutzers</h2>
        <p style="white-space:pre-wrap;color:#ccc;">{{message}}</p>
    </div>
</div>

<div class="card" style="margin-top:1.5rem;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;">Bearbeiten</h2>
    <div class="form-row">
        <div class="form-group">
            <label>Bearbeitungsstatus</label>
            <select id="statusSelect">
                {{statusOptions}}
            </select>
        </div>
    </div>
    <div class="form-group">
        <label>Kommentar für den Nutzer</label>
        <textarea id="commentInput" placeholder="z. B. Vielen Dank für die Meldung, wir haben den Beitrag entfernt.">{{adminComment}}</textarea>
    </div>
    <button class="btn btn-success" onclick="save()">Speichern</button>
</div>

<script>
const requestId = '{{id}}';

function save() {
    const status = document.getElementById('statusSelect').value;
    const adminComment = document.getElementById('commentInput').value;

    fetch('/api/v2/admin/moderation-requests/' + requestId + '/update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ status, adminComment }),
    })
    .then(r => r.json().then(d => ({ status: r.status, body: d })))
    .then(({ status, body }) => {
        if (status === 200) {
            showToast('Anfrage aktualisiert!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Fehler: ' + (body.error || 'Unknown'), 'error');
        }
    })
    .catch(() => showToast('Netzwerkfehler', 'error'));
}
</script>
