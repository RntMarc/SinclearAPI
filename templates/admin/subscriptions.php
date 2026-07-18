<div class="page-header">
    <div>
        <h1>Abonnements</h1>
        <div class="subtitle">Alle Abos im System verwalten</div>
    </div>
    <button class="btn btn-primary" onclick="showCreateModal()">+ Neues Abo</button>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Beginn</th>
                <th>Ende</th>
                <th>Grundpreis</th>
                <th>Teilnehmer</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody id="subscriptionsTable">
            {{rows}}
        </tbody>
    </table>
</div>

<div id="createModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; display:none; align-items:center; justify-content:center;">
    <div class="card" style="width:500px; max-width:90vw;">
        <h2 style="margin-bottom:1.5rem;">Neues Abo erstellen</h2>
        <form id="createForm" onsubmit="return createSubscription(event)">
            <div class="form-group">
                <label for="createName">Name</label>
                <input type="text" id="createName" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="createStart">Abrechnungszeitraum Beginn</label>
                    <input type="date" id="createStart" required>
                </div>
                <div class="form-group">
                    <label for="createEnd">Abrechnungszeitraum Ende</label>
                    <input type="date" id="createEnd" required>
                </div>
            </div>
            <div class="form-group">
                <label for="createPrice">Grundpreis (€)</label>
                <input type="number" id="createPrice" step="0.01" min="0" required>
            </div>
            <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1.5rem;">
                <button type="button" class="btn" onclick="hideCreateModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Erstellen</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center;">
    <div class="card" style="width:500px; max-width:90vw;">
        <h2 style="margin-bottom:1.5rem;">Abo bearbeiten</h2>
        <form id="editForm" onsubmit="return updateSubscription(event)">
            <input type="hidden" id="editId">
            <div class="form-group">
                <label for="editName">Name</label>
                <input type="text" id="editName" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="editStart">Abrechnungszeitraum Beginn</label>
                    <input type="date" id="editStart" required>
                </div>
                <div class="form-group">
                    <label for="editEnd">Abrechnungszeitraum Ende</label>
                    <input type="date" id="editEnd" required>
                </div>
            </div>
            <div class="form-group">
                <label for="editPrice">Grundpreis (€)</label>
                <input type="number" id="editPrice" step="0.01" min="0" required>
            </div>
            <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1.5rem;">
                <button type="button" class="btn" onclick="hideEditModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showCreateModal() {
        document.getElementById('createModal').style.display = 'flex';
    }

    function hideCreateModal() {
        document.getElementById('createModal').style.display = 'none';
        document.getElementById('createForm').reset();
    }

    function showEditModal() {
        document.getElementById('editModal').style.display = 'flex';
    }

    function hideEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function editSubscription(id, name, start, end, price) {
        document.getElementById('editId').value = id;
        document.getElementById('editName').value = name;
        document.getElementById('editStart').value = start;
        document.getElementById('editEnd').value = end;
        document.getElementById('editPrice').value = price;
        showEditModal();
    }

    async function createSubscription(event) {
        event.preventDefault();
        const data = {
            name: document.getElementById('createName').value,
            billingPeriodStart: document.getElementById('createStart').value,
            billingPeriodEnd: document.getElementById('createEnd').value,
            basePrice: parseFloat(document.getElementById('createPrice').value),
        };

        try {
            const response = await fetch('/api/v2/admin/subscriptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (response.ok) {
                showToast('Abo erstellt');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler beim Erstellen', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }

        return false;
    }

    async function updateSubscription(event) {
        event.preventDefault();
        const id = document.getElementById('editId').value;
        const data = {
            name: document.getElementById('editName').value,
            billingPeriodStart: document.getElementById('editStart').value,
            billingPeriodEnd: document.getElementById('editEnd').value,
            basePrice: parseFloat(document.getElementById('editPrice').value),
        };

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (response.ok) {
                showToast('Abo aktualisiert');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler beim Aktualisieren', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }

        return false;
    }

    async function deleteSubscription(id) {
        if (!confirm('Abo wirklich löschen?')) return;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + id, {
                method: 'DELETE',
            });

            if (response.ok) {
                showToast('Abo gelöscht');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler beim Löschen', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }
    }
</script>
