<div class="page-header">
    <div>
        <h1>Moderations-Anfragen</h1>
        <div class="subtitle">Meldungen und Bearbeitungswünsche aus der App</div>
    </div>
    <div class="header-actions">
        <select id="requestTypeFilter" onchange="applyFilters()" style="padding:0.5rem 0.8rem;border-radius:8px;background:#1a1a2e;border:1px solid #0f3460;color:#fff;font-size:0.9rem;">
            {{requestFilterOptions}}
        </select>
        <select id="objectFilter" onchange="applyFilters()" style="padding:0.5rem 0.8rem;border-radius:8px;background:#1a1a2e;border:1px solid #0f3460;color:#fff;font-size:0.9rem;">
            {{objectFilterOptions}}
        </select>
        <select id="statusFilter" onchange="applyFilters()" style="padding:0.5rem 0.8rem;border-radius:8px;background:#1a1a2e;border:1px solid #0f3460;color:#fff;font-size:0.9rem;">
            {{statusFilterOptions}}
        </select>
    </div>
</div>

<div style="margin-bottom:1.5rem;">
    {{countLinks}}
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Art</th>
                <th>Objekt</th>
                <th>Objekt-ID</th>
                <th>Absender</th>
                <th>Anliegen</th>
                <th>Status</th>
                <th>Erstellt</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<script>
function applyFilters() {
    const params = new URLSearchParams();
    const requestType = document.getElementById('requestTypeFilter').value;
    const object = document.getElementById('objectFilter').value;
    const status = document.getElementById('statusFilter').value;
    if (status) params.set('status', status);
    if (object) params.set('objectType', object);
    if (requestType) params.set('requestType', requestType);
    const qs = params.toString();
    window.location.href = '/api/v2/admin/moderation-requests' + (qs ? '?' + qs : '');
}
</script>
