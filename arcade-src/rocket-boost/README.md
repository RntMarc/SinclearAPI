# Rocket Boost — Quellen

Go/WASM-Spiel für den Arcade-Namensraum. Laufzeitdateien liegen unter
`public/arcade/rocket-boost/`, die Dokumentation unter
`docs/arcade/games/rocket-boost.md`.

## Befehle

```bash
go vet ./...
go test ./...        # Physik, Kollision, Fairness und Autopilot-Test
./build.sh           # go vet + go test, dann WASM-Build nach public/
```

`build.sh` benötigt Go >= 1.24 und schreibt die Artefakte direkt in das
öffentliche Spielverzeichnis. Die Artefakte sind in `.gitignore` eingetragen;
der Deploy (`update.sh`) baut lokal und lädt sie per `scp` hoch.

## Aufbau

| Datei | Inhalt |
|-------|--------|
| `main.go` | Canvas, Input, Frame-Loop, Rendering (`//go:build js && wasm`) |
| `game/world.go` | reine Simulation ohne `syscall/js` |
| `game/rng.go` | xorshift-PRNG statt `math/rand` (kleineres Binary) |
| `game/world_test.go` | Unit-Tests inkl. einfachem Autopiloten |

Das Spielfeld ist eine feste logische Welt (`360 × 640`), die der Renderer auf
den Viewport skaliert. Die JS-Schale kümmert sich nur um Token, Score-API und
Overlays.
