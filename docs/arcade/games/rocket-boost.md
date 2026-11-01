# Rocket Boost (Arcade-Spiel)

Erstes Referenzspiel für den Arcade-Namensraum. Es dient primär als
Machbarkeits- und Nutzbarkeitstest für die Arcade-Kette (Session-Token,
Score-Abgabe, Auslieferung über `.htaccess`) und ist deshalb bewusst klein
gehalten.

| Eigenschaft | Wert |
|-------------|------|
| Slug | `rocket-boost` |
| Titel | Rocket Boost |
| Score-Richtung | `desc` (mehr Korridore = besser) |
| Technik | Go 1.24+ → WebAssembly, JS-Schale für Token/API/UI |
| Spielfeld | feste logische Welt `360 × 640` (9:16), zentriert skaliert |
| Steuerung | Tippen/Klicken oder `Leertaste`/`↑` = Schub |

## Spielprinzip

Die Rakete fliegt von links nach rechts durch Korridore einer Raumstation.
Jeder passierte Korridor zählt einen Punkt. Berührung von Wand, Decke oder
Boden beendet den Lauf. Mit steigendem Score werden die Lücken enger
(196 → 134 px) und die Scrollgeschwindigkeit höher (168 → 268 px/s); beide
Werte sind gedeckelt. Aufeinanderfolgende Lücken springen maximal 170 px
vertikal, damit keine unlösbaren Situationen entstehen.

Die Kollision nutzt einen Kreis (Radius 9 px) statt eines Rechtecks und ist
damit deutlich verzeihender als die reine Grafik vermuten lässt.

## Architektur

```
arcade-src/rocket-boost/          Quellen (nicht öffentlich)
  main.go                         Canvas, Input, Frame-Loop, Rendering
  game/world.go                   reine Simulation (ohne syscall/js)
  game/rng.go                     xorshift-PRNG statt math/rand
  game/world_test.go              Physik-, Kollisions- und Fairness-Tests
  build.sh                        WASM bauen + wasm_exec.js kopieren

public/arcade/rocket-boost/       Laufzeitdateien (statisch)
  index.html                      Canvas + Overlays
  style.css                       Layout, Safe-Areas, Overlay-Optik
  game.js                         Token, WASM-Laden, Score-API, UI
  rocket.wasm                     Build-Artefakt (nicht im Git)
  wasm_exec.js                    Build-Artefakt (nicht im Git)
  thumbnail.png                   Katalogbild
```

Go besitzt Simulation, Rendering und den Frame-Loop. `main()` blockiert am
Ende bewusst; der Go-Scheduler gibt die Kontrolle an den Browser zurück,
sobald nur noch JS-Events (rAF, Resize) anstehen. Die JS-Schale ruft
`window.rocket.start()`, `.tap()`, `.pause(bool)` und `.setDebug(bool)` auf;
Go meldet über `window.rocketShell` Score, Laufende und Debug-Werte zurück.

## Build

```bash
./arcade-src/rocket-boost/build.sh
```

Das Skript führt zuerst `go vet` und `go test` aus, baut dann
`rocket.wasm` (`GOOS=js GOARCH=wasm`, `-trimpath -s -w`), kopiert
`wasm_exec.js` aus derselben Go-Installation und gibt die Größen aus.
`wasm_exec.js` und das Binary **müssen** aus derselben Go-Version stammen.
Aktuell: Go 1.27.3, ca. 2,0 MB roh / ca. 596 KB gzip.

Die Artefakte sind in `.gitignore` eingetragen. Der Server hat kein Go; der
Deploy baut lokal und lädt die beiden Dateien per `scp` hoch.

## Deployment

`update.sh` baut das Spiel vor dem SSH-Schritt und überträgt nach
erfolgreichem `git pull` + PHPUnit-Lauf `rocket.wasm` und `wasm_exec.js` nach
`public/arcade/rocket-boost/`. Schlägt der Build fehl, bricht der Deploy ab,
bevor der Server verändert wird.

## Session und Score

- Token kommt aus dem URL-Fragment (`#arcade_token=…`) und liegt nur im
  Speicher; Renew alle 4 Minuten (`POST /arcade/session/renew`), TTL ist 900 s.
- Nach jedem Lauf wird automatisch `POST /arcade/scores` gesendet
  (`score` = Korridore, `meta.durationMs` = Laufzeit).
- Das neue Token wird per `history.replaceState` in den Fragment-Hash
  geschrieben, damit ein Reload die Sitzung nicht verliert.
- Ohne Token ist das Spiel spielbar, der Score wird aber nicht gespeichert.
- `401 invalid_session` → Hinweis „Sitzung abgelaufen".

## Responsive Verhalten

- Canvas füllt den kompletten Viewport; die logische Welt bleibt `360 × 640`
  und wird per `min(w/360, h/640)` zentriert skaliert. Damit ist die
  Schwierigkeit auf allen Geräten identisch.
- Backing-Store skaliert mit `devicePixelRatio`, gedeckelt auf 2.
- Nur die DOM-Overlays berücksichtigen `env(safe-area-inset-*)`; Notches
  schneiden keine Texte ab.
- `touch-action: none`, `overscroll-behavior: none` und ein fixierter
  Viewport verhindern Scrollen/Zoomen im WebView.

## Debug

`index.html?debug=1#arcade_token=…` zeigt FPS, Frame-Zeit, Wandanzahl und die
WASM-Ladezeit unten links. Das ist das Messinstrument für die Bewertung der
Performance im WebView.

## Admin-Eintrag

Die Migration `database/migrations/20261101180000_seed_rocket_boost_game.sql`
legt den Katalog-Eintrag an (rerun-sicher; ein bereits vorhandener Slug bleibt
unverändert). Alternativ manuell im Admin-Dashboard unter `/admin/arcade`:

| Feld | Wert |
|------|------|
| Slug | `rocket-boost` |
| Titel | `Rocket Boost` |
| Beschreibung | `Flieg die Rakete durch die Korridore der Raumstation.` |
| Thumbnail-URL | `/arcade/rocket-boost/thumbnail.png` |
| Score-Richtung | `desc` |
| Aktiv | ja |

## Bekannte Grenzen

- **Kein Cheat-Schutz:** Der Client besitzt sein Token und kann Scores direkt
  senden. Für die kleine, persönlich bekannte Nutzerbasis akzeptiert.
- **Kein In-Game-Leaderboard:** Der Leaderboard-Endpoint ist JWT-only; die
  JS-Schale hat nur das Session-Token. Nach dem Lauf werden Score und
  persönlicher Rekord aus der API-Antwort angezeigt.
- **Kein Ton:** WebView-Autoplay-Restriktionen und bewusst minimaler Umfang.
- **Ca. 2 MB WASM** (596 KB gzip, sofern `mod_deflate` greift). Falls das im
  Mobilfunknetz stört, ist TinyGo der vorgesehene Fallback; die Spiellogik
  bleibt dabei unverändert.
