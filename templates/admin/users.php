<div class="page-header">
    <div>
        <h1>Nutzerverwaltung</h1>
        <div class="subtitle">Alle registrierten Benutzer</div>
    </div>
</div>

<div class="card placeholder-overlay">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>E-Mail</th>
                <th>Anzeigename</th>
                <th>Rolle</th>
                <th>Erstellt am</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{rows}}
        </tbody>
    </table>
</div>

<div class="card mt-2" style="padding:1rem;text-align:center;color:#888;font-size:0.85rem;">
    Hier können später Nutzer bearbeitet, zeitweise gesperrt (Timeout), gebannt und Einladungen versendet werden.
</div>
