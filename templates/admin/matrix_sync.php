<div class="page-header">
    <div>
        <h1>Matrix-Sync</h1>
        <div class="subtitle">Application-Service-Provisionierung von Matrix-Accounts (Continuwuity)</div>
    </div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="reconcileAll()">Alle reconciliieren</button>
    </div>
</div>

<div class="card-grid mb-2">
    <div class="card stat-card">
        <div class="number">{{accountCount}}</div>
        <div class="label">Matrix-Accounts</div>
    </div>
    <div class="card stat-card">
        <div class="number" style="color:#f59e0b;">{{pendingCount}}</div>
        <div class="label">Ausstehend</div>
    </div>
    <div class="card stat-card">
        <div class="number" style="color:#ef4444;">{{failedCount}}</div>
        <div class="label">Fehlgeschlagen</div>
    </div>
    <div class="card stat-card">
        <div class="number" style="color:#22c55e;">{{doneCount}}</div>
        <div class="label">Erledigt</div>
    </div>
</div>

<div class="card mb-2">
    <h2 style="margin-bottom:1rem;font-size:1.1rem;">Accounts</h2>
    <table>
        <thead>
            <tr>
                <th>Nutzer</th>
                <th>Matrix-User-ID</th>
                <th>Anzeigename (synced)</th>
                <th>Anzeigename (aktuell)</th>
            </tr>
        </thead>
        <tbody id="accountTable">
            <tr><td colspan="4" style="text-align:center;color:#888;">Lade...</td></tr>
        </tbody>
    </table>
</div>

<div class="card">
    <h2 style="margin-bottom:1rem;font-size:1.1rem;">Operationen (pending / failed)</h2>
    <table>
        <thead>
            <tr>
                <th>Nutzer</th>
                <th>Typ</th>
                <th>Status</th>
                <th>Versuche</th>
                <th>Nächster Versuch</th>
                <th>Fehler</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="operationTable">
            <tr><td colspan="7" style="text-align:center;color:#888;">Lade...</td></tr>
        </tbody>
    </table>
</div>

<script>
let matrixData = {};

async function loadMatrixSync() {
    try {
        const res = await fetch('/api/v2/admin/matrix/json');
        matrixData = await res.json();
        renderAccounts();
        renderOperations();
    } catch (e) {
        document.getElementById('accountTable').innerHTML =
            '<tr><td colspan="4" style="color:#ef4444;">Fehler beim Laden</td></tr>';
        document.getElementById('operationTable').innerHTML =
            '<tr><td colspan="7" style="color:#ef4444;">Fehler beim Laden</td></tr>';
    }
}

function renderAccounts() {
    const tbody = document.getElementById('accountTable');
    const accounts = matrixData.accounts || [];
    if (accounts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Keine Accounts</td></tr>';
        return;
    }
    tbody.innerHTML = accounts.map(function(a) {
        const synced = a.displayNameSynced || '–';
        const current = a.displayName || '';
        const drift = (a.displayNameSynced !== null && a.displayNameSynced !== current) ? ' (Drift)' : '';
        return '<tr>' +
            '<td>' + escapeHtml(a.email || a.userId) + '<br><small style="color:#888;">' + escapeHtml(a.userId) + '</small></td>' +
            '<td><code>' + escapeHtml(a.matrixUserId || '– (noch nicht angelegt)') + '</code></td>' +
            '<td>' + escapeHtml(synced) + '</td>' +
            '<td>' + escapeHtml(current) + escapeHtml(drift) + '</td>' +
            '</tr>';
    }).join('');
}

function renderOperations() {
    const tbody = document.getElementById('operationTable');
    const ops = matrixData.operations || [];
    if (ops.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">Keine offenen Operationen</td></tr>';
        return;
    }
    tbody.innerHTML = ops.map(function(o) {
        const statusBadge = o.status === 'failed'
            ? '<span class="badge" style="background:#ef4444;color:#fff;">failed</span>'
            : '<span class="badge" style="background:#f59e0b;color:#000;">pending</span>';
        const nextAttempt = o.nextAttemptAt || '–';
        const error = o.lastError ? escapeHtml(o.lastError) : '–';
        return '<tr>' +
            '<td>' + escapeHtml(o.displayName || o.userId) + '</td>' +
            '<td>' + escapeHtml(o.type) + '</td>' +
            '<td>' + statusBadge + '</td>' +
            '<td>' + o.attempts + '</td>' +
            '<td>' + escapeHtml(nextAttempt) + '</td>' +
            '<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + error + '">' + error + '</td>' +
            '<td><button class="btn btn-sm btn-primary" onclick="retryOperation(\'' + o.id + '\')">Neu versuchen</button></td>' +
            '</tr>';
    }).join('');
}

async function retryOperation(id) {
    try {
        const res = await fetch('/api/v2/admin/matrix/operations/' + encodeURIComponent(id) + '/retry', { method: 'POST' });
        if (res.ok) {
            showToast('Operation zum erneuten Versuch vorgemerkt');
            loadMatrixSync();
        } else {
            showToast('Fehler beim Zurücksetzen', 'error');
        }
    } catch (e) {
        showToast('Fehler beim Zurücksetzen', 'error');
    }
}

async function reconcileAll() {
    if (!confirm('Reconciliation manuell anstoßen?')) return;
    try {
        const res = await fetch('/api/v2/admin/matrix/reconcile', { method: 'POST' });
        if (res.ok) {
            showToast('Reconciliation angestoßen');
            loadMatrixSync();
        } else {
            showToast('Fehler bei der Reconciliation', 'error');
        }
    } catch (e) {
        showToast('Fehler bei der Reconciliation', 'error');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

loadMatrixSync();
</script>
