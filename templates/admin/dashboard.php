<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <div class="subtitle">Übersicht über die Plattform</div>
    </div>
</div>

<div class="card-grid">
    <div class="card stat-card">
        <div class="number">{{userCount}}</div>
        <div class="label">Registrierte Nutzer</div>
        <a href="/api/v2/admin/users" class="btn btn-sm mt-2">Verwalten</a>
    </div>
    <div class="card stat-card">
        <div class="number">{{tripCount}}</div>
        <div class="label">Reisen</div>
        <a href="/api/v2/admin/travel" class="btn btn-sm mt-2">Verwalten</a>
    </div>
    <div class="card stat-card">
        <div class="number">⚡</div>
        <div class="label">Benachrichtigungen</div>
        <a href="/api/v2/admin/notifications" class="btn btn-sm mt-2">Senden</a>
    </div>
</div>
