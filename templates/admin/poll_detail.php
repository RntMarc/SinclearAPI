<div class="page-header">
    <div>
        <h1>{{pollTitle}}</h1>
        <div class="subtitle">Typ: {{pollType}} · Ersteller: {{creatorName}} · {{statusBadge}}</div>
    </div>
    <div class="header-actions">
        {{closeButton}}
        <a href="/api/v2/admin/polls" class="btn">← Zurück</a>
    </div>
</div>

<div class="card mb-2">
    <h2>Beschreibung</h2>
    <p style="color:#ccc; white-space:pre-wrap; margin-top:0.5rem;">{{description}}</p>
    <p style="color:#888; font-size:0.85rem; margin-top:0.75rem;">Verfügbarkeitsstimmen: {{availabilityCount}}</p>
</div>

<div class="card mb-2">
    <h2>Fragen</h2>
    <table>
        <thead>
            <tr><th>Typ</th><th>Titel</th><th>Pflicht</th></tr>
        </thead>
        <tbody>
            {{questionRows}}
        </tbody>
    </table>
</div>

<div class="card mb-2">
    <h2>Optionen</h2>
    <table>
        <thead>
            <tr><th>Label</th><th>Zeitraum</th><th>Art</th><th>Stimmen</th></tr>
        </thead>
        <tbody>
            {{optionRows}}
        </tbody>
    </table>
</div>

<div class="detail-grid">
    <div class="card">
        <h2>Einladungen</h2>
        <table>
            <thead>
                <tr><th>Nutzer</th></tr>
            </thead>
            <tbody>
                {{inviteRows}}
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Formular-Antworten</h2>
        <table>
            <thead>
                <tr><th>Nutzer</th><th>Zeitpunkt</th></tr>
            </thead>
            <tbody>
                {{responseRows}}
            </tbody>
        </table>
    </div>
</div>

<script>
    async function closePoll(id) {
        if (!confirm('Umfrage jetzt schließen? Danach sind keine Antworten/Stimmen mehr möglich.')) return;

        try {
            const response = await fetch('/api/v2/admin/polls/' + id + '/close', { method: 'POST' });
            if (response.ok) {
                showToast('Umfrage geschlossen');
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
