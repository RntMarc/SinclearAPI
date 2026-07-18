<div class="page-header">
    <div>
        <h1>{{subscriptionName}}</h1>
        <div class="subtitle">Abo-Details</div>
    </div>
    <a href="/api/v2/admin/subscriptions" class="btn">← Zurück</a>
</div>

<div class="card-grid mb-2">
    <div class="card stat-card">
        <div class="number">{{basePrice}} €</div>
        <div class="label">Grundpreis</div>
    </div>
    <div class="card stat-card">
        <div class="number">{{billingPeriodStart}}</div>
        <div class="label">Abrechnungszeitraum Beginn</div>
    </div>
    <div class="card stat-card">
        <div class="number">{{billingPeriodEnd}}</div>
        <div class="label">Abrechnungszeitraum Ende</div>
    </div>
</div>

<div class="card">
    <div class="flex-between mb-2">
        <h2>Teilnehmer</h2>
        <button class="btn btn-primary" onclick="showAddParticipantModal()">+ Teilnehmer hinzufügen</button>
    </div>
    <table>
        <thead>
            <tr>
                <th>Name / Nutzer-ID</th>
                <th>Typ</th>
                <th>Bezahltstatus</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{participantRows}}
        </tbody>
    </table>
</div>

<div id="addParticipantModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:1000; align-items:center; justify-content:center;">
    <div class="card" style="width:500px; max-width:90vw;">
        <h2 style="margin-bottom:1.5rem;">Teilnehmer hinzufügen</h2>
        <form id="addParticipantForm" onsubmit="return addParticipant(event)">
            <div class="form-group" style="position:relative;">
                <label for="userSearch">App-Nutzer suchen</label>
                <input type="text" id="userSearch" placeholder="Name oder E-Mail eingeben …" autocomplete="off">
                <div id="userSearchResults" style="display:none; position:absolute; top:100%; left:0; right:0; background:#1a1a2e; border:1px solid #0f3460; border-radius:6px; max-height:200px; overflow-y:auto; z-index:10;"></div>
                <input type="hidden" id="participantUserId">
                <div id="selectedUser" style="display:none; margin-top:0.5rem; padding:0.5rem; background:#0f3460; border-radius:6px; font-size:0.9rem;"></div>
            </div>
            <div class="form-group">
                <label for="participantName">Name (bei Nicht-Nutzer ausfüllen)</label>
                <input type="text" id="participantName">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="participantPaid"> Bereits bezahlt
                </label>
            </div>
            <div style="display:flex; gap:0.5rem; justify-content:flex-end; margin-top:1.5rem;">
                <button type="button" class="btn" onclick="hideAddParticipantModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Hinzufügen</button>
            </div>
        </form>
    </div>
</div>

<script>
    const subscriptionId = '{{subscriptionId}}';
    let searchTimeout = null;
    let selectedUserId = null;

    function showAddParticipantModal() {
        document.getElementById('addParticipantModal').style.display = 'flex';
        selectedUserId = null;
        document.getElementById('participantUserId').value = '';
        document.getElementById('selectedUser').style.display = 'none';
        document.getElementById('userSearch').value = '';
        document.getElementById('participantName').value = '';
    }

    function hideAddParticipantModal() {
        document.getElementById('addParticipantModal').style.display = 'none';
        document.getElementById('addParticipantForm').reset();
        document.getElementById('userSearchResults').style.display = 'none';
        document.getElementById('selectedUser').style.display = 'none';
        selectedUserId = null;
        document.getElementById('participantUserId').value = '';
    }

    function selectUser(id, name, email, image) {
        selectedUserId = id;
        document.getElementById('participantUserId').value = id;
        document.getElementById('userSearch').value = '';
        document.getElementById('userSearchResults').style.display = 'none';
        document.getElementById('participantName').value = '';

        const avatar = image
            ? '<img src="' + image + '" style="width:24px;height:24px;border-radius:50%;margin-right:0.5rem;">'
            : '<span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:#5865F2;text-align:center;line-height:24px;font-size:0.75rem;margin-right:0.5rem;">' + name.charAt(0).toUpperCase() + '</span>';

        document.getElementById('selectedUser').innerHTML =
            avatar + '<strong>' + name + '</strong> <span style="color:#888;">(' + email + ')</span>' +
            ' <button type="button" onclick="clearSelectedUser()" style="background:none;border:none;color:#ff6b6b;cursor:pointer;margin-left:0.5rem;">×</button>';
        document.getElementById('selectedUser').style.display = 'flex';
        document.getElementById('selectedUser').style.alignItems = 'center';
    }

    function clearSelectedUser() {
        selectedUserId = null;
        document.getElementById('participantUserId').value = '';
        document.getElementById('selectedUser').style.display = 'none';
    }

    document.getElementById('userSearch').addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('userSearchResults').style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(() => searchUsers(q), 300);
    });

    document.getElementById('userSearch').addEventListener('blur', function () {
        setTimeout(() => {
            document.getElementById('userSearchResults').style.display = 'none';
        }, 200);
    });

    async function searchUsers(q) {
        try {
            const res = await fetch('/api/v2/admin/users/json?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) {
                window.location.href = '/api/v2/admin/login';
                return;
            }
            const data = await res.json();
            const users = data.data || [];
            const container = document.getElementById('userSearchResults');

            if (users.length === 0) {
                container.innerHTML = '<div style="padding:0.5rem;color:#888;">Keine Ergebnisse</div>';
                container.style.display = 'block';
                return;
            }

            container.innerHTML = users.map(u => {
                const avatar = u.image
                    ? '<img src="' + u.image + '" style="width:28px;height:28px;border-radius:50%;margin-right:0.5rem;">'
                    : '<span style="display:inline-block;width:28px;height:28px;border-radius:50%;background:#5865F2;text-align:center;line-height:28px;font-size:0.8rem;margin-right:0.5rem;">' + u.displayName.charAt(0).toUpperCase() + '</span>';
                return '<div onmousedown="selectUser(\'' + u.id + '\', \'' + u.displayName.replace(/'/g, "\\'") + '\', \'' + u.email.replace(/'/g, "\\'") + '\', \'' + (u.image || '') + '\')" style="padding:0.5rem;cursor:pointer;display:flex;align-items:center;border-bottom:1px solid #0f3460;">' +
                    avatar + '<div><strong>' + u.displayName + '</strong><br><span style="color:#888;font-size:0.85rem;">' + u.email + '</span></div></div>';
            }).join('');
            container.style.display = 'block';
        } catch (e) {
            showToast('Fehler bei der Suche', 'error');
        }
    }

    async function addParticipant(event) {
        event.preventDefault();
        const userId = selectedUserId || document.getElementById('participantUserId').value.trim();
        const name = document.getElementById('participantName').value.trim();
        const hasPaid = document.getElementById('participantPaid').checked;

        if (!userId && !name) {
            showToast('Nutzer auswählen oder Name eingeben', 'error');
            return false;
        }

        const data = { hasPaid };
        if (userId) data.userId = userId;
        if (name) data.userName = name;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subscriptionId + '/participants', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });

            if (response.ok) {
                showToast('Teilnehmer hinzugefügt');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }

        return false;
    }

    async function togglePaidStatus(participantId, currentStatus) {
        const newStatus = !currentStatus;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subscriptionId + '/participants/' + participantId, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hasPaid: newStatus }),
            });

            if (response.ok) {
                showToast(newStatus ? 'Als bezahlt markiert' : 'Als offen markiert');
                setTimeout(() => location.reload(), 500);
            } else {
                const err = await response.json();
                showToast(err.error || 'Fehler', 'error');
            }
        } catch (e) {
            showToast('Netzwerkfehler', 'error');
        }
    }

    async function removeParticipant(subId, participantId) {
        if (!confirm('Teilnehmer wirklich entfernen?')) return;

        try {
            const response = await fetch('/api/v2/admin/subscriptions/' + subId + '/participants/' + participantId, {
                method: 'DELETE',
            });

            if (response.ok) {
                showToast('Teilnehmer entfernt');
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
