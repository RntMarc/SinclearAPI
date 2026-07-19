# Preference Improvement Plan

## Ziel

Alle Präferenzen und Einstellungen des Nutzers werden zentral in der
bereits existierenden `UserPreferences`-Tabelle gespeichert, statt
verteilt über `User`, `ContactInfo`, `SocialInfo`, `UserDevice` und
`ForumMember`.

---

## Aktuelle Datenbank-Situation

Die Tabelle `UserPreferences` **existiert bereits**, wird aber von keinem
PHP-Code verwendet:

```sql
CREATE TABLE `UserPreferences` (
  `id`         varchar(191) NOT NULL,
  `userId`     varchar(191) NOT NULL,
  `language`   varchar(10)  NOT NULL DEFAULT 'de',
  `theme`      enum('light','dark') NOT NULL DEFAULT 'dark',
  `primaryColor` varchar(20) NOT NULL DEFAULT 'var(--primary)',
  `timezone`   varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`)
);
```

---

## Vollständiger Ist-Zustand: 18 Preference-Felder + 4 bestehende

### Quelltabellen

| Tabelle | Preference-Felder |
|---------|-------------------|
| `User` | `emailVisibility`, `birthdayVisibility`, `onboardingCompleted` |
| `ContactInfo` | `discordVisibility`, `fluxerVisibility`, `signalVisibility`, `whatsappVisibility`, `matrixVisibility` |
| `SocialInfo` | `unsplashVisibility`, `instagramVisibility`, `mastodonVisibility`, `pixelfedVisibility`, `blueskyVisibility`, `youtubeVisibility`, `twitchVisibility` |
| `UserDevice` | `pushEnabled` |
| `ForumMember` | `notificationsEnabled` |
| `UserPreferences` | `language`, `theme`, `primaryColor`, `timezone` (ungenutzt) |

### Neu hinzukommend (aktuell in keinem DB-Schema)

| Feld | Tabelle (Ziel) | Grund |
|------|----------------|-------|
| `discordAvatarHash` | `User` | Discord-Profilbild-Cache, keine Preference |
| `syncAvatarFromDiscord` | `UserPreferences` | Preference |

### Nicht migriert

| Feld | Begründung |
|------|------------|
| `pushEnabled` | Per-Device, kein globaler User-Preference |
| `notificationsEnabled` | Per-Forum, kein globaler User-Preference |

---

## API-Design

### Neuer Endpunkt (Preferences-CRUD)

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `GET` | `/user/me/preferences` | Alle Preferences des Nutzers auslesen |
| `PUT` | `/user/me/preferences` | Teilmenge der Preferences aktualisieren |

### Alte Endpunkte (noch aktiv, aber redundant)

| Endpunkt | Ersetzt durch |
|----------|---------------|
| `PUT /user/me/discord/sync-avatar` | `PUT /user/me/preferences` mit `{ "syncAvatarFromDiscord": … }` |
| `PUT /user/me/visibility` | `PUT /user/me/preferences` mit beliebigen `*Visibility`-Keys |
| `POST /user/me/onboarding/complete` | `PUT /user/me/preferences` mit `{ "onboardingCompleted": true }` |

---

## Phasenplan (mit Checkboxen)

### Phase 0: `discordAvatarHash` in `User`

- [x] 0.1 Migration: `events/add_discord_avatar_hash.sql`
- [x] 0.2 `UserRepository`: `discordAvatarHash` in alle SELECT-Queries + `create()`-INSERT
- [x] 0.3 `UserUpdateRepository`: `updateDiscordAvatarHash()` + `$allowed`-Eintrag
- [x] 0.4 `UserService::formatUserBase()` / `formatUserBaseFiltered()`: `discordAvatarHash` im Response
- [x] 0.5 `DiscordOAuthService`: `discordAvatarHash` bei Registration/Login speichern
- [x] 0.6 `ProfileService::verifyDiscordRelink()`: `discordAvatarHash` aus Metadata
- [x] 0.7 `openapi.yaml`: `discordAvatarHash` in `UserBase` / `UserBasePublic`

### Phase 1: `UserPreferences`-Fundament

- [x] 1.1 Migration: `events/extend_user_preferences.sql`
- [x] 1.2 `src/Repository/UserPreferenceRepository.php` **NEU**
- [x] 1.3 `src/Services/UserPreferenceService.php` **NEU**
- [x] 1.4 `src/Controllers/UserPreferenceController.php` **NEU**
- [x] 1.5 `config/routes.php`: `GET /user/me/preferences`, `PUT /user/me/preferences`
- [x] 1.6 `config/dependencies.php`: DI-Registrierung
- [x] 1.7 Data-Migration: `INSERT IGNORE` für existierende User
- [x] 1.8 `openapi.yaml`: `UserPreferences`-Schema + Endpunkte

### Phase 2: `syncAvatarFromDiscord` umstellen

- [x] 2.1 `DiscordOAuthService::processCallback()`: Lese aus `UserPreferenceRepository`
- [x] 2.2 `ProfileService::updateDiscordSyncAvatar()`: Schreibe in `UserPreferenceRepository`
- [x] 2.3 `UserService::formatUserBase()`: Lese aus `UserPreferenceService`

### Phase 3: `emailVisibility`, `birthdayVisibility`, `onboardingCompleted` umstellen

- [x] 3.1 Data-Migration: `events/migrate_preferences_data.sql`
- [x] 3.2 `UserService::formatUserBase()`: Lese aus `UserPreferenceService`
- [x] 3.3 `UserService::formatUserBaseFiltered()`: Policy-Check aus `UserPreferenceService`
- [x] 3.4 `ProfileService::updateVisibility()`: Delegiere an `UserPreferenceRepository`
- [x] 3.5 `ProfileService::completeOnboarding()`: Preference in `UserPreferenceRepository`
- [x] 3.6 `UserUpdateRepository`: `emailVisibility`, `birthdayVisibility`, `onboardingCompleted` aus `$allowed` entfernt
- [x] 3.7 `UserRepository`: Spalten aus SELECT-Queries entfernt
- [x] 3.8 `openapi.yaml`: `emailVisibility`, `birthdayVisibility`, `onboardingCompleted` aus `UserBase`/`UserBasePublic` entfernt (bleiben vorerst im Response für Client-Kompatibilität)

### Phase 4: Alle `*Visibility` aus `ContactInfo` + `SocialInfo`

- [x] 4.1 Data-Migration: `events/migrate_preferences_data.sql`
- [x] 4.2 `UserService::formatSocialInfo()` / `formatSocialInfoFiltered()`: Visibility aus `UserPreferenceService`
- [x] 4.3 `UserService::formatContactInfo()` / `formatContactInfoFiltered()`: Visibility aus `UserPreferenceService`
- [x] 4.4 `ProfileService::updateVisibility()`: Vereinfacht — alles an `UserPreferenceService`
- [x] 4.5 `ProfileService::updateProfile()`: Keine Visibility mehr in `$contactUpdates`/`$socialUpdates`
- [x] 4.6 `ContactInfoUpdateRepository::upsert()`: Keine Visibility-Felder (automatisch)
- [x] 4.7 `SocialInfoUpdateRepository::upsert()`: Keine Visibility-Felder (automatisch)
- [x] 4.8 `openapi.yaml`: Visibility aus `UserContactInfo`/`UserSocialInfo` entfernt

### Phase 5: Cleanup (nach Deploy!)

- [x] 5.1 `events/cleanup_old_preference_columns.sql` ausführen
- [x] 5.2 Alte Endpunkte deprecated (seit 2026-07-19): `PUT /user/me/discord/sync-avatar`, `PUT /user/me/visibility`, `POST /user/me/onboarding/complete` — **Entfernen bis 2026-09-19**
- [x] 5.3 `ProfileController::updateDiscordSyncAvatar()` — bleibt als deprecated bis 2026-09-19
- [x] 5.4 `openapi.yaml`: Endpunkte als `deprecated: true` + `VisibilityUpdateRequest` als deprecated markiert — **Entfernen bis 2026-09-19**

---

## SQL-Migrationen

| Datei | Phase | Zweck |
|-------|-------|-------|
| `events/add_discord_avatar_hash.sql` | 0 | `discordAvatarHash` in User |
| `events/extend_user_preferences.sql` | 1 | UserPreferences erweitern |
| `events/migrate_preferences_data.sql` | 3+4 | Daten kopieren |
| `events/cleanup_old_preference_columns.sql` | 5 | Alte Spalten löschen |

---

## Vollständige Datei-Liste

### PHP (API)

| Status | Datei | Phase | Änderung |
|--------|-------|-------|----------|
| **NEU** | `src/Repository/UserPreferenceRepository.php` | 1 | CRUD für UserPreferences-Tabelle |
| **NEU** | `src/Services/UserPreferenceService.php` | 1 | getAll, update, default handling |
| **NEU** | `src/Controllers/UserPreferenceController.php` | 1 | getPreferences, updatePreferences |
| ÄNDERN | `config/dependencies.php` | 1 | DI-Registrierung |
| ÄNDERN | `config/routes.php` | 1 | Routes hinzugefügt |
| ÄNDERN | `src/Repository/UserRepository.php` | 0, 3 | `discordAvatarHash` hinzu, Preferences raus |
| ÄNDERN | `src/Repository/UserUpdateRepository.php` | 0, 2, 3 | `updateDiscordAvatarHash` hinzu, Preferences-Methoden raus |
| ÄNDERN | `src/Services/UserService.php` | 0, 2-4 | Felder aus UserPreferenceService laden |
| ÄNDERN | `src/Services/ProfileService.php` | 0, 2-4 | Schreib-Pfade umgestellt |
| ÄNDERN | `src/Services/Auth/DiscordOAuthService.php` | 0, 2 | `discordAvatarHash` speichern, `syncAvatarFromDiscord` aus Preferences |

### openapi.yaml

| Status | Bereich | Phase |
|--------|---------|-------|
| ÄNDERN | `UserBase`: `discordAvatarHash` hinzu | 0 |
| ÄNDERN | `UserContactInfo`: Visibility entfernt | 4 |
| ÄNDERN | `UserSocialInfo`: Visibility entfernt | 4 |
| **NEU** | `UserPreferences`-Schema | 1 |
| **NEU** | `GET /user/me/preferences` | 1 |
| **NEU** | `PUT /user/me/preferences` | 1 |

### Datenbank-Migrationen (events/)

| Status | Datei | Phase |
|--------|-------|-------|
| **NEU** | `events/add_discord_avatar_hash.sql` | 0 |
| **NEU** | `events/extend_user_preferences.sql` | 1 |
| **NEU** | `events/migrate_preferences_data.sql` | 3+4 |
| **NEU** | `events/cleanup_old_preference_columns.sql` | 5 |

---

## Offene Fragen

1. **`language`, `theme`, `primaryColor`, `timezone`**: Sollen diese 4 bestehenden
   Spalten in `UserPreferences` bleiben oder entfernt werden (wenn sie clientseitig
   in `SharedPreferences` verwaltet werden)?
   → Empfehlung: **Vorerst belassen**, da sie nicht stören.

2. **`pushEnabled` / `notificationsEnabled`**: Per-Device / Per-Forum –
   für später in extra Phase?

---

## Reihenfolge

```
Phase 0 ─► Phase 1 ─► Phase 2 ─► Phase 3 ─► Phase 4 ─► Phase 5
(dringend)  (Tabelle)   (sync)     (User)     (Vis.)    (Cleanup)
```

Jede Phase ist in sich abgeschlossen und deploybar. Alte Spalten bleiben bis
Phase 5 erhalten (Read/Write läuft während der Migration parallel).
