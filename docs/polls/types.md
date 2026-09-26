# Fragetypen & Optionen (Polls)

`PollQuestion.type` steuert die Validierung und wird von
`src/Services/Poll/PollAnswerValidator.php` umgesetzt (DB-frei, unit-testbar).
`PollQuestion.config` (JSON) hält typspezifische Grenzen, damit Erweiterungen
ohne Schemaänderung möglich bleiben.

## Fragetypen

| `type` | `config` | Validierung | Speicherung in `PollAnswer.value` |
|--------|----------|-------------|-----------------------------------|
| `text` | `minLength`, `maxLength` (Default = DB-Cap `mediumtext` = 65535) | Länge nach `mb_strlen`, getrimmt | String |
| `textarea` | `minLength`, `maxLength` | wie `text` (Zeilenumbrüche erlaubt) | String (Plain Text) |
| `number` | `min`, `max`, `integerOnly` | Zahl, optional integer, Bereich | Zahl als String |
| `email` | – | `filter_var(..., FILTER_VALIDATE_EMAIL)` | String |
| `coordinates` | `requireBothFields` (Default true) | `lat ∈ [-90,90]`, `lon ∈ [-180,180]`, beide Werte | `"lat,lon"` |
| `date` | `minDate`, `maxDate` | `DateTimeValue::parseDate` (`YYYY-MM-DD`) | String `YYYY-MM-DD` |
| `datetime` | `timezone` (IANA) | RFC 3339 **mit Offset** (`DateTimeValue::parseInstant`), IANA geprüft | UTC-DATETIME als String |
| `url` | `allowedSchemes` (Default `[http, https]`) | `filter_var(..., FILTER_VALIDATE_URL)` + Scheme-Check | String |
| `phone` | – | E.164-nahe Regex (`/^\+?\d{7,15}$/` nach Entfernen von Trenner) | normalisierter String |
| `single_choice` | `allowOther` | Option-ID muss zur Frage gehören; bei `allowOther` ist Freitext erlaubt | eine Option-ID (oder Freitext) |
| `multiple_choice` | `minSelected`, `maxSelected` | Teilmenge der Option-IDs, Anzahl-Grenzen | JSON-Array von Option-IDs |
| `boolean` | – | `true/false`, `1/0`, `"1"/"0"`, `yes/no`, `ja/nein` | `"1"`/`"0"` |
| `rating` | `min`, `max`, `step`, `labels` | Zahl im Bereich, optional Schrittweite | Zahl als String |

### Pflicht und Leerwerte

Ein leerer Wert (`null`, leerer String, leeres Array) ist bei `isRequired=true`
ungültig (`answer_required`); bei optionalen Fragen wird `null` gespeichert.
Ungültige Werte lösen `invalid_answer` aus.

### Datum/Zeit

`datetime` folgt der AGENTS Date/Time Convention: Der Client sendet einen
RFC-3339-Zeitpunkt mit Offset; intern wird der UTC-Instant gespeichert. Die
optionale `config.timezone` wird als IANA-Zone validiert (Anzeige ist
Client-Aufgabe).

## Auswahloptionen

Optionen für `single_choice`/`multiple_choice` werden beim Anlegen der Umfrage
unter `questions[].options[]` (`{ "label": "..." }`) übergeben und als
`PollOption` mit `questionId` gespeichert.

## Terminvorschläge (`appointment`)

Terminvorschläge sind `PollOption`-Einträge mit Zeitfeldern (AGENTS
Date/Time Convention):

- **Getaktet** (`allDay=false`): `startAt`/`endAt` als RFC 3339 mit Offset,
  zwingend plus `timezone` (IANA). Datumsfelder sind verboten
  (`date_forbidden`).
- **Ganztägig** (`allDay=true`): `startDate`/`endDate` als zivile Tage
  (`YYYY-MM-DD`, inklusives Ende) plus `timezone`. Uhrzeitfelder sind verboten
  (`time_forbidden`).

Gegenvorschläge werden über `POST /polls/{id}/options` erzeugt und als
`PollOption` mit `isCounterProposal=1` und `proposedBy` markiert.

## Abstimmungsoptionen (`vote`)

`vote`-Umfragen verwenden `options[]` (`{ "label": "..." }`) ohne
`questionId`. Teilnehmer wählen eine oder mehrere Option-IDs (`optionIds[]`).
