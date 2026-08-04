<div class="page-header">
    <div>
        <h1>Rezepteverwaltung</h1>
        <div class="subtitle">Rezepte einsehen und löschen</div>
    </div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>Titel</th>
                <th>Kategorie</th>
                <th>Erstellt von</th>
                <th>Bewertung</th>
                <th>Status</th>
                <th>Erstellt am</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<script>
    async function deleteRecipe(id, title) {
        if (!confirm('Rezept "' + title + '" wirklich löschen?\n\nZutaten, Schritte, Bewertungen und Lesezeichen werden ebenfalls gelöscht.')) {
            return;
        }

        try {
            const res = await fetch('/api/v2/admin/recipes/' + id, {
                method: 'DELETE',
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            if (res.ok) {
                showToast('Rezept gelöscht');
                setTimeout(() => window.location.reload(), 500);
            } else {
                const err = await res.json();
                showToast('Fehler: ' + (err.error || 'unbekannt'), 'error');
            }
        } catch (e) {
            showToast('Fehler beim Löschen', 'error');
        }
    }

    function editRecipePlaceholder(id, title) {
        showToast('Rezeptbearbeitung kommt bald', 'error');
    }
</script>
