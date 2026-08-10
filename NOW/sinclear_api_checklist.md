# Sinclear Notification API — Implementierungs-Checkliste

## Phase 1 — Datenbankschema

- [x] Migration erstellen für Tabelle `notifications`
  - [x] Felder: `id` (varchar(191), PK), `userId` (varchar(191)), `type` (VARCHAR 64), `title` (VARCHAR 255), `body` (TEXT), `data` (JSON, nullable), `isRead` (TINYINT, default 0), `createdAt` (DATETIME(3), default now)
  - [x] Index auf `(userId, isRead, createdAt)`
- [x] Migration erstellen für Tabelle `push_subscriptions`
  - [x] Felder: `id` (varchar(191), PK), `userId` (varchar(191)), `type` (VARCHAR 20 — `'webpush'` oder `'unifiedpush'`), `endpoint` (TEXT), `p256dh` (TEXT, nullable), `auth` (TEXT, nullable), `userAgent` (VARCHAR 255, nullable), `createdAt` (DATETIME(3), default now)
  - [x] Index auf `(userId)`
  - [x] Unique-Constraint auf `endpoint` (Duplikate beim selben Endpoint verhindern)
- [ ] Beide Migrationen lokal ausgeführt und Tabellenstruktur geprüft *(erfordert MySQL auf dem Server)*
- [ ] Migrationen auf dem Server ausgeführt *(manuell auszuführen)*

## Phase 2 — NotificationService

- [x] Klasse `Sinclear\Api\Services\NotificationService` anlegen unter `src/Services/NotificationService.php`
- [x] Konstruktor nimmt `NotificationRepository`, `PushSubscriptionRepository`, optional `WebPush`, optional `Client`, optional `LoggerInterface` entgegen
- [x] Methode `create(string $userId, string $type, string $title, string $body, ?array $data = null): string`
  - [x] Generiert UUID via `ramsey/uuid` (im Repository)
  - [x] Schreibt Datensatz in Tabelle `Notification`
  - [x] Gibt die neue `id` zurück
  - [x] Ruft `sendWebPush()` und `sendUnifiedPush()` auf
- [x] Methode `getUnread(string $userId, ?string $since = null): array`
  - [x] Gibt alle Einträge mit `isRead = 0` für den User zurück
  - [x] Filtert optional nach `createdAt > $since`, wenn Parameter gesetzt
  - [x] Sortierung: `createdAt DESC`, Limit 50
  - [x] Gibt `data`-Feld als dekodiertes Array zurück (nicht als JSON-String)
- [x] Methode `markRead(string $userId, array $ids): void`
  - [x] Setzt `isRead = 1` für alle übergebenen IDs, die dem User gehören
  - [x] Leeres Array wird ohne DB-Query abgefangen
- [x] Service in DI-Container registrieren
- [x] Unit-Tests für alle drei Methoden schreiben (15 Tests)

## Phase 3 — NotificationController & Routen

- [x] Klasse `Sinclear\Api\Controllers\NotificationController` anlegen unter `src/Controllers/NotificationController.php`
- [x] Konstruktor nimmt `NotificationService` und `PushSubscriptionRepository` entgegen
- [x] Controller in DI-Container registrieren
- [x] Methode `index(Request, Response): Response` — `GET /notifications`
  - [x] Liest User aus Request-Attribut (gesetzt durch bestehende Auth-Middleware)
  - [x] Liest optionalen Query-Parameter `since`
  - [x] Gibt JSON zurück: `{"notifications": [...]}`
  - [x] HTTP 200
- [x] Methode `markRead(Request, Response): Response` — `POST /notifications/read`
  - [x] Liest `ids`-Array aus Request-Body
  - [x] Gibt HTTP 400 zurück wenn `ids` fehlt, kein Array ist oder leer ist
  - [x] Ruft `NotificationService::markRead()` auf
  - [x] Gibt JSON zurück: `{"ok": true}`, HTTP 200
- [x] Methode `savePushSubscription(Request, Response): Response` — `POST /notifications/push-subscription`
  - [x] Validiert: `endpoint` vorhanden, `type` ist `'webpush'` oder `'unifiedpush'`
  - [x] Für `type = 'webpush'`: `keys.p256dh` und `keys.auth` müssen vorhanden sein
  - [x] Für `type = 'unifiedpush'`: `keys` sind optional
  - [x] Schreibt in Tabelle `PushSubscription` (INSERT, bei gleichem Endpoint UPDATE)
  - [x] Gibt HTTP 201 und `{"ok": true}` zurück
- [x] Methode `deletePushSubscription(Request, Response): Response` — `DELETE /notifications/push-subscription`
  - [x] Liest `endpoint` aus Request-Body
  - [x] Löscht Eintrag aus `PushSubscription` wenn er dem User gehört
  - [x] Gibt HTTP 200 und `{"ok": true}` zurück
- [x] Methode `vapidPublicKey(Request, Response): Response` — `GET /notifications/vapid-public-key`
  - [x] Liest `VAPID_PUBLIC_KEY` aus Umgebungsvariable
  - [x] Gibt JSON zurück: `{"key": "..."}`
  - [x] Kein Auth erforderlich — Route liegt außerhalb der Auth-Gruppe
- [x] Alle fünf Routen in `config/routes.php` eintragen
  - [x] `GET /notifications`, `POST /notifications/read`, `POST /notifications/push-subscription`, `DELETE /notifications/push-subscription` → in Auth-Gruppe
  - [x] `GET /notifications/vapid-public-key` → ohne Auth-Middleware
- [x] Integrationstests für alle fünf Endpunkte (22 Tests)

## Phase 4 — VAPID-Schlüssel & Konfiguration

- [x] Paket `minishlink/web-push` via Composer installieren
- [x] VAPID-Schlüsselpaar-Generator erstellt (`bin/generate_vapid_keys.php`)
- [x] `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` in `.env.example` eingetragen
- [x] VAPID-Konfiguration in Settings und DI-Container registriert
- [ ] VAPID-Schlüsselpaar einmalig generieren und in `.env` eintragen *(manuell auf dem Server ausführen)*
- [ ] Prüfen: `GET /notifications/vapid-public-key` gibt den richtigen Public Key zurück *(nach Deployment)*

## Phase 5 — Web Push Versand

- [x] `NotificationService` um Abhängigkeit `WebPush $webPush` erweitert
- [x] Private Methode `sendWebPush(string $userId, string $title, string $body, ?array $data): void`
  - [x] Lädt alle Subscriptions des Users mit `type = 'webpush'`
  - [x] Gibt sofort zurück wenn keine vorhanden
  - [x] Erstellt `Subscription`-Objekte und queued Notifications
  - [x] Payload: `json_encode(['title' => ..., 'body' => ..., 'data' => ...])`
  - [x] Löscht abgelaufene Endpoints (HTTP 410)
  - [x] Fehler werden geloggt, aber werfen keine Exception nach oben
- [x] `create()` ruft am Ende `sendWebPush()` auf

## Phase 6 — UnifiedPush Versand

- [x] Private Methode `sendUnifiedPush(string $userId, string $title, string $body, ?array $data): void` in `NotificationService`
  - [x] Lädt alle Subscriptions des Users mit `type = 'unifiedpush'`
  - [x] Gibt sofort zurück wenn keine vorhanden
  - [x] Sendet per Guzzle HTTP POST an jeden Endpoint
  - [x] Payload als JSON-Body: `{"title": ..., "body": ..., "data": ...}`
  - [x] HTTP 410 vom Distributor → Subscription aus DB löschen
  - [x] Fehler werden geloggt, aber werfen keine Exception nach oben
  - [x] Requests nicht-blockierend (try/catch)
- [x] `create()` ruft `sendUnifiedPush()` parallel zu `sendWebPush()` auf

## Abschluss-Prüfung API

- [x] PHPStan: 0 Errors (Level 5)
- [ ] Alle Tests grün *(erfordert PDO/SQLite auf dem Server — `composer require --dev php-sqlite3`)*
- [ ] `NotificationService::create()` in bestehenden Controller einbinden *(z.B. ForumCommentController, CalendarEventController — nachfolgender Agent)*
- [x] Keine Push-Fehler blockieren API-Responses
- [x] VAPID-Public-Key-Endpunkt erreichbar ohne Auth
- [x] Abgelaufene Subscriptions werden automatisch bereinigt
- [x] `.env.example` ist vollständig und aktuell
- [x] OpenAPI-Spezifikation aktuell (5 neue Endpunkte + 4 neue Schemas)
- [x] Dokumentation in `docs/notifications/readme.md` erstellt
