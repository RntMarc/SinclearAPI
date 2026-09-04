<div class="page-header">
    <div>
        <h1>Externe Daten – Cache</h1>
        <div class="subtitle">Cached API-Antworten von InfraNode und Open-Meteo</div>
    </div>
    <div class="header-actions">
        <button class="btn btn-danger" onclick="clearAll()">Allen Cache leeren</button>
    </div>
</div>

<div class="card-grid mb-2">
    <div class="card stat-card">
        <div class="number">{{totalEntries}}</div>
        <div class="label">Einträge gesamt</div>
    </div>
    <div class="card stat-card">
        <div class="number" style="color:#ef4444;">{{expiredEntries}}</div>
        <div class="label">Abgelaufen</div>
    </div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Datentyp</th>
                <th>Schlüssel</th>
                <th>Quelle</th>
                <th>Läuft ab</th>
                <th>Größe</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="cacheTable">
            <tr><td colspan="6" style="text-align:center;color:#888;">Lade...</td></tr>
        </tbody>
    </table>
</div>

<script>
let cacheData = [];

async function loadCache() {
    try {
        const res = await fetch('/api/v2/admin/external-data-cache/json');
        const data = await res.json();
        cacheData = data.entries || [];
        renderTable();
    } catch (e) {
        document.getElementById('cacheTable').innerHTML =
            '<tr><td colspan="6" style="color:#ef4444;">Fehler beim Laden</td></tr>';
    }
}

function renderTable() {
    const tbody = document.getElementById('cacheTable');
    if (cacheData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">Keine Einträge</td></tr>';
        return;
    }

    const now = new Date();
    tbody.innerHTML = cacheData.map(function(entry) {
        const isExpired = new Date(entry.expires_at) < now;
        const rowStyle = isExpired ? 'color:#ef4444;' : '';
        const sizeKb = entry.payload_size ? Math.round(entry.payload_size / 1024) + ' KB' : '-';
        return '<tr style="' + rowStyle + '">' +
            '<td>' + escapeHtml(entry.data_type) + '</td>' +
            '<td>' + escapeHtml(entry.location_key) + '</td>' +
            '<td><span class="badge">' + escapeHtml(entry.source) + '</span></td>' +
            '<td>' + escapeHtml(entry.expires_at) + (isExpired ? ' (abgelaufen)' : '') + '</td>' +
            '<td>' + sizeKb + '</td>' +
            '<td><button class="btn btn-danger btn-sm" onclick="deleteEntry(\'' + entry.id + '\')">Löschen</button></td>' +
            '</tr>';
    }).join('');
}

async function deleteEntry(id) {
    if (!confirm('Cache-Eintrag wirklich löschen?')) return;
    try {
        await fetch('/api/v2/admin/external-data-cache/' + encodeURIComponent(id), { method: 'DELETE' });
        showToast('Eintrag gelöscht');
        loadCache();
    } catch (e) {
        showToast('Fehler beim Löschen', 'error');
    }
}

async function clearAll() {
    if (!confirm('Wirklich ALLE Cache-Einträge löschen?')) return;
    try {
        await fetch('/api/v2/admin/external-data-cache', { method: 'DELETE' });
        showToast('Cache geleert');
        loadCache();
    } catch (e) {
        showToast('Fehler beim Leeren', 'error');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

loadCache();
</script>
