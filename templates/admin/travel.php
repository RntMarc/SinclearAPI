<div class="page-header">
    <div>
        <h1>Reisen & Events</h1>
        <div class="subtitle">Alle Reisen und Events auf der Plattform</div>
    </div>
</div>

<div class="card placeholder-overlay" style="margin-bottom:1rem;">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Reisen</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Zeitraum</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{tripRows}}
        </tbody>
    </table>
</div>

<div class="card placeholder-overlay">
    <h2 style="font-size:1.1rem;margin-bottom:1rem;color:#aaa;">Events</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Reise</th>
                <th>Start</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {{eventRows}}
        </tbody>
    </table>
</div>
