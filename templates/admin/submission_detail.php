<div class="page-header">
    <div>
        <h1>{{name}}</h1>
        <div class="subtitle">Eingereicht am {{createdAt}}</div>
    </div>
    <a href="/api/v2/admin/explore/submissions" class="btn">← Zurück</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <div class="card">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Details</h2>
        <table>
            <tr><td style="color:#888;">Status</td><td>{{statusBadge}}</td></tr>
            <tr><td style="color:#888;">Nutzer-ID</td><td><code>{{userId}}</code></td></tr>
            <tr><td style="color:#888;">Name</td><td>{{name}}</td></tr>
            <tr><td style="color:#888;">Adresse</td><td>{{address}}</td></tr>
            <tr><td style="color:#888;">Koordinaten</td><td>{{latitude}}, {{longitude}}</td></tr>
            <tr><td style="color:#888;">Google Maps</td><td>{{mapLink}}</td></tr>
            <tr><td style="color:#888;">Website</td><td>{{website}}</td></tr>
            <tr><td style="color:#888;">Bewertung</td><td>{{ratingHtml}}</td></tr>
            <tr><td style="color:#888;">Kommentar</td><td>{{comment}}</td></tr>
            <tr><td style="color:#888;">Notiz</td><td>{{note}}</td></tr>
            <tr><td style="color:#888;">Aktualisiert</td><td>{{updatedAt}}</td></tr>
        </table>
        {{targetLink}}
        {{adminNote}}
    </div>
    <div class="card">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Foto</h2>
        {{photoHtml}}
    </div>
</div>

<div id="actions" style="display:{{showActions}};margin-top:1.5rem;">
    <div class="card" style="margin-bottom:1rem;">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Freigeben & nach OSM übernehmen</h2>
        <p style="color:#888;font-size:0.85rem;margin-bottom:1rem;">
            Gib die OSM-ID und den OSM-Typ des korrespondierenden OSM-Objekts ein.
            Der Ort wird dann via Nominatim geladen, in DiscoverPlace gespeichert
            und die ggf. vorhandene Bewertung des Einreichers übernommen.
        </p>
        <div class="form-row">
            <div class="form-group">
                <label>OSM-ID</label>
                <input type="number" id="approveOsmId" placeholder="z. B. 123456789">
            </div>
            <div class="form-group">
                <label>OSM-Typ</label>
                <select id="approveOsmType">
                    <option value="N">Node (N)</option>
                    <option value="W">Way (W)</option>
                    <option value="R">Relation (R)</option>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Admin-Notiz (optional)</label>
            <textarea id="approveNote" placeholder="z. B. In OSM gefunden als Restaurant Beispiel"></textarea>
        </div>
        <button class="btn btn-success" onclick="approve()">Freigeben & Übernehmen</button>
    </div>

    <div class="card">
        <h2 style="font-size:1.1rem;margin-bottom:1rem;">Ablehnen</h2>
        <p style="color:#888;font-size:0.85rem;margin-bottom:1rem;">
            Lehne die Einreichung ab. Die Bewertung wird gelöscht,
            Name und Koordinaten bleiben erhalten.
        </p>
        <div class="form-group">
            <label>Grund (Pflicht)</label>
            <textarea id="rejectNote" placeholder="z. B. Doppelter Eintrag, existiert bereits als ..."></textarea>
        </div>
        <button class="btn btn-danger" onclick="reject()">Ablehnen</button>
    </div>
</div>

<script>
const submissionId = '{{id}}';

function approve() {
    const osmId = document.getElementById('approveOsmId').value;
    const osmType = document.getElementById('approveOsmType').value;
    const note = document.getElementById('approveNote').value;

    if (!osmId || osmId <= 0) {
        showToast('Bitte eine gültige OSM-ID eingeben', 'error');
        return;
    }

    fetch('/api/v2/admin/explore/submissions/' + submissionId + '/approve', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ osmId: parseInt(osmId), osmType, adminNote: note }),
    })
    .then(r => r.json().then(d => ({ status: r.status, body: d })))
    .then(({ status, body }) => {
        if (status === 200) {
            showToast('Ort wurde freigegeben und übernommen!');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Fehler: ' + (body.error || 'Unknown'), 'error');
        }
    })
    .catch(() => showToast('Netzwerkfehler', 'error'));
}

function reject() {
    const note = document.getElementById('rejectNote').value;

    if (!note) {
        showToast('Bitte einen Grund für die Ablehnung angeben', 'error');
        return;
    }

    fetch('/api/v2/admin/explore/submissions/' + submissionId + '/reject', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ adminNote: note }),
    })
    .then(r => r.json().then(d => ({ status: r.status, body: d })))
    .then(({ status, body }) => {
        if (status === 200) {
            showToast('Einreichung wurde abgelehnt');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Fehler: ' + (body.error || 'Unknown'), 'error');
        }
    })
    .catch(() => showToast('Netzwerkfehler', 'error'));
}
</script>
