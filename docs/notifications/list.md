# Benachrichtigungen – Implementierungsliste

Diese Datei ist das zentrale Register aller Benachrichtigungs-Trigger im Projekt.
Nach jeder Änderung an der Benachrichtigungslogik MUSS diese Datei aktuell gehalten werden.

**Legende:**
- `[x]` = Implementiert
- `[ ]` = Noch nicht implementiert (geplant)

---

## Admin

- [x] `admin.system_update` – System-Update-Ankündigung (manuell via Admin-Dashboard)
- [x] `admin.new_feature` – Neue Funktion verfügbar (manuell via Admin-Dashboard)
- [x] `admin.maintenance` – Wartungshinweis (manuell via Admin-Dashboard)
- [x] `admin.welcome` – Willkommensnachricht (manuell via Admin-Dashboard)
- [x] `admin.test` – Test-Ping (manuell via Admin-Dashboard)
- [x] `admin.custom` – Freie Admin-Nachricht (manuell via Admin-Dashboard)

## Kalender

- [x] `calendar.event_created` – Neues Kalender-Event mit Teilnehmern → alle Teilnehmer (außer Ersteller)
- [x] `calendar.event_updated` – Kalender-Event geändert → alle Teilnehmer + Ersteller (außer Handelndem)
- [x] `calendar.participant_added` – Teilnehmer zu Event hinzugefügt → der neue Teilnehmer (wenn nicht Handelnder)

## Location Sharing

- [x] `location_sharing.started` – Live-Standort wird geteilt → alle Empfänger der Freigabe

## Place Submission

- [x] `submission.created` – Nutzer erstellt Place-Submission → einreichender Nutzer (Bestätigung)
- [x] `submission.new` – Neue Place-Submission eingegangen → alle Admins
- [x] `submission.status_changed` – Submission genehmigt → einreichender Nutzer
- [x] `submission.status_changed` – Submission abgelehnt → einreichender Nutzer

## Forum

- [x] `forum.new_post` – Neuer Post in einem Forum → alle Mitglieder mit `notificationsEnabled=true` (außer Autor)
- [x] `forum.post_commented` – Kommentar zu einem Post → Post-Autor (nicht Commentator)
- [x] `forum.comment_replied` – Antwort auf Kommentar (Thread) → alle Eltern-Kommentare inkl. Post-Autor (außer Antworendem)
- [x] `forum.post_upvoted` – Upvote auf einen Post → Post-Autor (nicht Voter)

## Rezepte

- [x] `recipe.review_created` – Jemand bewertet ein Rezept → Rezept-Ersteller (nicht Reviewer)

## Reisen

> **Ausnahme:** Im verknüpften Forum einer Reise wird **immer** benachrichtigt –
> die Einstellung `notificationsEnabled` wird dort nicht berücksichtigt, da diese
> Beiträge für alle Teilnehmenden relevant sind.

### Reise-Forum (verknüpftes Forum)

- [x] `forum.new_post` – Neuer Post im Reise-Forum → alle Reise-Teilnehmer (immer, kein Opt-out)
- [x] `forum.post_commented` – Kommentar im Reise-Forum → Post-Autor (nicht Commentator)
- [x] `forum.comment_replied` – Antwort auf Kommentar im Reise-Forum → alle Eltern-Kommentare inkl. Post-Autor (außer Antworendem)
- [x] `forum.post_upvoted` – Upvote im Reise-Forum → Post-Autor (nicht Voter)

### Reise-Events & Unterkünfte

- [x] `travel.participant_added` – Teilnehmer zu Reise hinzugefügt → der neue Teilnehmer
- [x] `travel.event_created` – Neues Event in einer Reise → alle Teilnehmer des Events (außer Ersteller)
- [x] `travel.event_updated` – Reise-Event geändert → alle Teilnehmer des Events (außer Änderndem)
- [x] `travel.accommodation_changed` – Unterkunft geändert → alle Nutzer dieser Unterkunft (außer Änderndem)

## Entdecken / Explore

- [x] `explore.place_reviewed` – Jemand bewertet einen Ort → Orts-Ersteller (nicht Reviewer)

## Feedback

- [x] `feedback.status_changed` – Admin ändert Status eines Vorschlags → Vorschlags-Autor
- [x] `feedback.suggestion_commented` – Kommentar zu einem Vorschlag → Vorschlags-Autor (nicht Commentator)
- [x] `feedback.suggestion_comment_replied` – Antwort auf Kommentar → alle Eltern-Kommentare inkl. Vorschlags-Autor (außer Antworendem)

## Abos / Subscriptions

- [x] `subscription.participant_added` – Teilnehmer zu Abo hinzugefügt → der neue Teilnehmer
- [x] `subscription.billing_updated` – Abrechungsdaten geändert → alle Teilnehmer (außer Änderndem)

## Moderation

- [x] `moderation.request_resolved` – Admin bearbeitet Meldung → Meldungs-Einreicher
