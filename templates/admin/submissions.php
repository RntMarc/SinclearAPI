<div class="page-header">
    <div>
        <h1>Orte-Einreichungen</h1>
        <div class="subtitle">Manuell eingereichte Orte, die auf Admin-Prüfung warten</div>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;">
        <select id="statusFilter" onchange="filterStatus()" style="padding:0.5rem 0.8rem;border-radius:8px;background:#1a1a2e;border:1px solid #0f3460;color:#fff;font-size:0.9rem;">
            {{statusFilterOptions}}
        </select>
        <span style="color:#888;font-size:0.85rem;">
            <span style="color:#f59e0b;">● {{pendingCount}}</span>
            <span style="color:#22c55e;margin-left:0.5rem;">● {{transferredCount}}</span>
            <span style="color:#ef4444;margin-left:0.5rem;">● {{rejectedCount}}</span>
        </span>
    </div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Foto</th>
                <th>Name</th>
                <th>Adresse</th>
                <th>Status</th>
                <th>Eingereicht</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<script>
function filterStatus() {
    const status = document.getElementById('statusFilter').value;
    const params = status ? '?status=' + status : '';
    window.location.href = '/api/v2/admin/explore/submissions' + params;
}
</script>
