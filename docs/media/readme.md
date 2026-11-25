# Medien & Low-Data-Modus

Die App muss auch bei schlechtem Mobilfunk (Edge, schwaches LTE, hoher
Packet-Loss) zumindest die textbasierten Inhalte zuverlässig anzeigen können.
Die Hauptursache für lange Ladezeiten sind die als Base64 in nahezu jedem
JSON-Response eingebetteten Bilder (Avatare, Rezept-/Forum-/Story-Bilder,
Event-Banner, Tickets, …). Diese Lösung liefert stattdessen winzige
Vorschaubilder aus; die volle Qualität lädt der Nutzer gezielt nach.

## Funktionsweise

Der Client signalisiert über den Header `X-Media-Quality: low` (alternativ
`Save-Data: on` oder `?media=low`), dass er wenig Bandbreite hat. Eine globale
Middleware (`MediaQualityMiddleware`) ersetzt daraufhin in der fertigen
JSON-Response jedes Base64-Bildfeld durch ein kleines Vorschaubild samt
Inhalts-Hash:

```
data:image/webp;preview=1;hash=<xxh128>;base64,<thumb>
```

- **Einmal berechnen, tausendfach ohne CPU ausliefern:** Das Vorschaubild wird
  über den `xxh128`-Hash des Original-Base64 im Disk-Cache
  `var/cache/media/{ab}/{hash}.thumb` abgelegt. Wiederholte Requests (derselbe
  Avatar in einer Liste) sind reine Datei-Lesezugriffe ohne CPU-Last — wichtig
  auf Shared Hosting.
- **Vorab-Erzeugung beim Upload:** `ImageService` erzeugt das Vorschaubild
  bereits beim Hochladen. Für Bestandsbilder wird es beim ersten Low-Data-Request
  lazily nachgeholt (max. 10 neue Thumbnails pro Request, danach wird das Feld
  geleert).
- **Original für Nachladen:** Beim Ausliefern im Low-Data-Modus wird das
  Original zusätzlich als `var/cache/media/{ab}/{hash}.full` vorgehalten.
- **Erkennbare Medienfelder:** `image`, `photo`, `avatar`, `avatarUrl`,
  `banner`, `thumbnailUrl`, `userImage`, `creatorImage`, `senderImage`,
  `otherUserImage`. Externe URLs (`http(s)://`) bleiben unangetastet und werden
  vom Client über dessen eigene Bildqualitäts-Logik behandelt.

## Nachladen der vollen Qualität

`GET /api/v2/media/{hash}` (authentifiziert) liefert das Original-Base64 eines
zuvor als Vorschaubild ausgelieferten Bildes. Der Hash ist content-addressed
und damit unveränderlich, daher ist der Response dauerhaft privat cachebar
(`Cache-Control: private, max-age=31536000, immutable`).

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `GET` | `/media/{hash}` | Original-Base64 für einen Vorschaubild-Hash |

Antwort (200):

```json
{ "data": { "image": "<base64 Originalbild>" } }
```

Fehler: `404` (`not_found`), wenn der Hash unbekannt ist.

## Cache-Bereinigung

Der Cron-Task `cleanup_media_thumbnails` (täglich) entfernt Dateien aus
`var/cache/media/`, die länger als 30 Tage nicht mehr benutzt wurden (anhand
`mtime`, die bei jedem Zugriff via `touch` aktualisiert wird). Das verhindert
unbegrenztes Wachstum auf Shared Hosting, wenn Nutzer Bilder ändern oder
löschen. Siehe [CRON.md](../CRON.md).

## Konfiguration

| Variable | Standard | Beschreibung |
|---|---|---|
| — | — | Keine zusätzliche Konfiguration nötig. Cache-Verzeichnis: `var/cache/media/` |

Die Größen-/Qualitätsparameter (Vorschaubild max. 256 px, WebP-Qualität 45)
sind Konstanten in `MediaService`.

## Client-Verhalten

Der Client (`SinclearApp`) wählt die Bildqualität über eine Einstellung
(„Automatisch / Datensparmodus / Immer volle Qualität“). Im Automatik-Modus
wird bei Timeouts automatisch auf Low-Data umgeschaltet. Vorschaubilder werden
sofort angezeigt; große Inhaltsbilder und große Avatare bieten einen
„Volle Qualität laden“-Button, der `GET /media/{hash}` aufruft und das
Ergebnis lokal zwischenspeichert.
