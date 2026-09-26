<div class="page-header">
    <div>
        <h1>Umfragen</h1>
        <div class="subtitle">Formulare, Terminfindungen und Abstimmungen</div>
    </div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Titel</th>
                <th>Typ</th>
                <th>Ersteller</th>
                <th>Teilnahme</th>
                <th>Status</th>
                <th>Erstellt</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<script>
    async function deletePoll(id, title) {
        if (!confirm('Umfrage "' + title + '" wirklich löschen? Antworten und Stimmen werden mitgelöscht.')) return;

        try {
            const response = await fetch('/api/v2/admin/polls/' + id, { method: 'DELETE' });
            if (response.ok) {
                showToast('Umfrage gelöscht');
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
