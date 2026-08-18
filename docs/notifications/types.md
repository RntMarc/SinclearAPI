# Notification-Typen – Übersicht

Diese Datei listet ausschließlich die existierenden Benachrichtigungstypen, ihre Trigger und die übermittelten Eigenschaften. Technische Details zur API stehen in [readme.md](./readme.md). Sie muss bei jeder Änderung an Notification-Typen aktualisiert werden.

> **Präferenzen:** Die vom Preferences-Endpoint angebotenen Typen können vom Nutzer über `PUT /notifications/preferences` aktiviert (`enabled`) oder deaktiviert (`disabled`) werden (Standard: `enabled`). Interne Event-Typen werden dabei zu gemeinsamen Preference-Schlüsseln zusammengefasst. Folgende Typen unterstützen zusätzlich `custom` (Denylist: IDs im `customData` werden vom Versand ausgeschlossen): `forum_comment`, `forum_reply` (Denylist `forumIds`), `story_post` (Denylist `userIds`) und `direct_message` (Denylist `userIds`). Details und Format-Regeln siehe [readme.md](./readme.md).
>
> **Vereinheitlichte Event-Typen:** Interne Notification-Typen (z.B. `standalone_event_user_added`, `trip_event_user_added`) werden bei den Präferenzen auf vereinheitlichte Typen gemappt. Der Nutzer sieht nur: `event_user_added` / `event_user_added_others`, `event_ticket_added` und `event_info_changed`. Diese gelten sowohl für Reise-Events als auch für eigenständige Events.

## Forum & Stories

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `forum_comment` | Neuer Top-Level-Kommentar (ohne `parentId`) auf einen Forum-Post | Autor des Posts, außer bei eigenem Kommentar (kein Self-Trigger) | `comment_author` (User, Autor des Kommentars), `post_author` (User), `parent_post` (ForumPost), `parent_forum` (Forum) | `Neuer Kommentar zu deinem Beitrag` | `Jemand hat deinen Beitrag kommentiert.` |
| `forum_reply` | Neue Antwort (mit `parentId`) auf einen bestehenden Forum-Kommentar | Autor des beantworteten Kommentars, außer bei eigener Antwort (kein Self-Trigger) | `reply_author` (User, Autor der Antwort), `comment_author` (User, Autor des beantworteten Kommentars), `post_author` (User), `parent_comment` (ForumPostComment), `parent_post` (ForumPost), `parent_forum` (Forum) | `Neue Antwort auf deinen Kommentar` | `Jemand hat auf deinen Kommentar geantwortet.` |
| `story_post` | Neue Story wird veröffentlicht | Alle Nutzer außer dem Autor (kein Self-Trigger) | `story_author` (User, Autor der Story), `story` (Story) | `Neue Story` | `Jemand hat eine neue Story veröffentlicht.` |

## Direktnachrichten

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `direct_message` | Neue Nachricht in einer 1:1-Konversation | Der andere Teilnehmer (nicht der Sender), aber NUR wenn `ChatPresence.activeUntil` des Empfängers in der Vergangenheit liegt (Push-Unterdrückung bei aktivem Polling). Bündelung: Eine Notification pro Konversation (`dedupeKey = "chat:<conversationId>"`), nicht pro Nachricht. | `sender` (User, Absender), `conversation` (ChatConversation), `message` (DirectMessage) | `Neue Nachricht` | Dynamisch: `{sender.displayName} hat dir eine Nachricht geschickt.` |

## Reise: Nutzer hinzugefügt

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_user_added` | Nutzer wird zu einer Reise hinzugefügt | Der hinzugefügte Nutzer | `added_user` (User), `trip` (Trip), `added_by` (User, optional – wer hat hinzugefügt) | `Du wurdest zu einer Reise hinzugefügt` | Dynamisch: `Du wurdest zu der Reise "{trip.name}" hinzugefügt.` |
| `trip_user_added_others` | Nutzer wird zu einer Reise hinzugefügt | Alle anderen Reise-Teilnehmer | `added_user` (User), `trip` (Trip), `added_by` (User, optional) | `Neuer Teilnehmer auf der Reise` | Dynamisch: `{user.displayName} wurde zu eurer Reise "{trip.name}" hinzugefügt.` |

## Event: Nutzer hinzugefügt

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `standalone_event_user_added` | Nutzer wird zu einem Standalone-Event hinzugefügt | Der hinzugefügte Nutzer | `added_user` (User), `event` (Event), `added_by` (User, optional) | `Du wurdest zu einem Event hinzugefügt` | Dynamisch: `Du wurdest zu dem Event "{event.name}" hinzugefügt.` |
| `standalone_event_user_added_others` | Nutzer wird zu einem Standalone-Event hinzugefügt | Alle anderen Event-Teilnehmer | `added_user` (User), `event` (Event), `added_by` (User, optional) | `Neuer Teilnehmer beim Event` | Dynamisch: `{user.displayName} wurde zu eurem Event "{event.name}" hinzugefügt.` |
| `trip_event_user_added` | Nutzer wird zu einem Event in einer Reise hinzugefügt | Der hinzugefügte Nutzer | `added_user` (User), `event` (Event), `trip` (Trip), `added_by` (User, optional) | `Du wurdest zu einem Event hinzugefügt` | Dynamisch: `Du wurdest zu dem Event "{event.name}" in der Reise "{trip.name}" hinzugefügt.` |
| `trip_event_user_added_others` | Nutzer wird zu einem Event in einer Reise hinzugefügt | Alle anderen Event-Teilnehmer | `added_user` (User), `event` (Event), `trip` (Trip), `added_by` (User, optional) | `Neuer Teilnehmer beim Event` | Dynamisch: `{user.displayName} wurde zu dem Event "{event.name}" hinzugefügt.` |

## Reise: Event hinzugefügt

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_event_added` | Neues Event wird zu einer Reise hinzugefügt | Alle Reise-Teilnehmer | `event` (Event), `trip` (Trip) | `Neues Event auf der Reise` | Dynamisch: `Ein neues Event "{event.name}" wurde zur Reise "{trip.name}" hinzugefügt.` |

## Tickets

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_ticket_added` | Neues Ticket (type=trip) wird zu einer Reise hinzugefügt | Alle Reise-Teilnehmer (außer Hochlader) | `ticket` (Ticket), `trip` (Trip), `uploaded_by` (User) | `Neues Ticket für die Reise` | Dynamisch: `Ein Ticket "{ticket.title}" wurde für die Reise "{trip.name}" hinzugefügt.` |
| `standalone_event_ticket_added` | Neues Ticket (type=event) wird zu einem Standalone-Event hinzugefügt | Alle Event-Teilnehmer (außer Hochlader) | `ticket` (Ticket), `event` (Event), `uploaded_by` (User) | `Neues Ticket für das Event` | Dynamisch: `Ein Ticket "{ticket.title}" wurde für das Event "{event.name}" hinzugefügt.` |
| `trip_event_ticket_added` | Neues Ticket (type=event) wird zu einem Event in einer Reise hinzugefügt | Alle Event-Teilnehmer (außer Hochlader) | `ticket` (Ticket), `event` (Event), `uploaded_by` (User) | `Neues Ticket für das Event` | Dynamisch: `Ein Ticket "{ticket.title}" wurde für das Event "{event.name}" hinzugefügt.` |

**Hinweis:** Tickets mit `type=user` (persönliche Tickets) lösen keine Benachrichtigung aus.

## Hotel / Unterkunft

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_accommodation_added` | Hotel-Informationen werden für einen Nutzer zugewiesen | Nur der betreffende Nutzer | `accommodation` (Accommodation), `trip` (Trip), `user` (User) | `Hotel-Zuweisung` | Dynamisch: `Dir wurde das Hotel "{accommodation.name}" für die Reise "{trip.name}" zugewiesen.` |

## Informationen geändert

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_info_changed` | Informationen einer Reise werden geändert | Alle Reise-Teilnehmer | `trip` (Trip), `changed_by` (User), `changed_fields` (FieldList, kommagetrennte Feldnamen) | `Reise-Informationen geändert` | Dynamisch basierend auf geänderten Feldern (siehe unten) |
| `standalone_event_info_changed` | Informationen eines Standalone-Events werden geändert | Alle Event-Teilnehmer | `event` (Event), `changed_by` (User), `changed_fields` (FieldList) | `Event-Informationen geändert` | Dynamisch basierend auf geänderten Feldern |
| `trip_event_info_changed` | Informationen eines Events in einer Reise werden geändert | Alle Event-Teilnehmer | `event` (Event), `trip` (Trip), `changed_by` (User), `changed_fields` (FieldList) | `Event-Informationen geändert` | Dynamisch basierend auf geänderten Feldern |

### Text-Logik für Änderungs-Benachrichtigungen

| Geändertes Feld | Text |
|-----------------|------|
| `Name` | "Der Name wurde geändert." |
| `Beschreibung` | "Die Beschreibung wurde geändert." |
| `Startdatum`, `Enddatum` | "Der Zeitraum wurde geändert." |
| `Ticket-Informationen`, `Ticket-URL` | "Die Ticket-Informationen wurden geändert." |
| Kombination | "Die Felder {fields} wurden geändert." |

## Abonnements / Zahlungen

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `trip_subscription_added` | Abo wird mit einer Reise verknüpft | Alle Reise-Teilnehmer | `subscription` (Subscription), `trip` (Trip) | `Neues Abo verknüpft` | Dynamisch: `Das Abo "{subscription.name}" wurde mit der Reise "{trip.name}" verknüpft.` |
