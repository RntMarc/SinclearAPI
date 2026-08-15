# Plan: Standortfreigabe für Reisegruppen

## Agenten-Regeln

> **WICHTIG:** Dieser Plan ist in einzelne, unabhängige Arbeitsschritte unterteilt.
> 
> **Arbeitsablauf:**
> 1. Führe JEDEN Schritt einzeln und nacheinander aus
> 2. Teste jede Änderung sofort (phpstan, php -l)
> 3. Dokumentiere das Ergebnis
> 4. Hake die Checkbox ab: `- [x]`
> 
> **Schritte mit Abhängigkeiten sind explizit markiert.**
> 
> **Bei Fragen oder Fehlern: STOP und rückfragen, bevor du weitermachst.**

---

## Übersicht

**Ziel:** Nutzer sollen ihren Standort mit allen Teilnehmern einer Reise oder eines Standalone-Events teilen können. Die Implementierung erweitert die bestehende Location-Sharing-API um eine Reisegruppen-Integration.

** Kernanforderungen:**
- Standortfreigabe für Reisen und Standalone-Events (über `TravelRelation`/`EventRelation`)
- Token-basierte Authentifizierung (32-Zeichen-hex, kompatibel mit Traccar & Co.)
- Gültigkeitsdauer: 3 Monate (90 Tage)
- Anzeige in der App im Karten-Tab der Reise-Details

**Architektur-Entscheidung:**
- Bestehende `LocationSharingSession`-Tabelle wird um optionale `tripId`/`eventId`-Felder erweitert
- Kein neues Token-System - bestehende Tokens werden wiederverwendet
- Reise-Teilnehmer werden automatisch als Empfänger hinzugefügt

---

## Schritte

- [ ] **1. Datenbank-Schema erweitern**
  
  **Ziel:** `LocationSharingSession`-Tabelle um Reise/Event-Referenzen erweitern
  
  **Änderungen:**
  - Neue Spalten: `tripId` (nullable VARCHAR(36)), `eventId` (nullable VARCHAR(36))
  - Index auf `tripId` und `eventId` für Performance
  - Migration script erstellen
  
  **Test:** SQL-Syntax prüfen, Migration ausführen

---

- [ ] **2. DTOs aktualisieren**
  
  **Ziel:** API-Request/Response um Reise/Event-Felder erweitern
  
  **Änderungen:**
  - `LocationSharingSessionCreateRequest`: Neues optionales Feld `trip_id` oder `event_id`
  - `LocationSharingSessionResponse`: Neue Felder `tripId`, `eventId`, `tripName`, `eventName`
  
  **Validierungsregeln:**
  - Entweder `recipient_ids` ODER `trip_id`/`event_id` muss gesetzt sein
  - `trip_id` und `event_id` dürfen nicht gleichzeitig gesetzt werden
  - Gültige UUID-Formate für beide Felder
  
  **Test:** PHPStan, Request-Validierung

---

- [ ] **3. Service-Layer erweitern**
  
  **Ziel:** Logik für Reisegruppen-Integration implementieren
  
  **Änderungen:**
  - Neue Methode `createTripSession()`: Erstellt Session + fügt alle Trip-Teilnehmer als Empfänger hinzu
  - Neue Methode `createEventSession()`: Erstellt Session + fügt alle Event-Teilnehmer als Empfänger hinzu
  - Bestehende `createSession()` bleibt unverändert (für manuelle Empfänger)
  
  **Logik:**
  ```
  IF trip_id gesetzt:
    - Prüfe ob Nutzer Teilnehmer der Reise ist
    - Hole alle User-IDs aus TravelRelation
    - Erstelle Session mit diesen als recipients
  IF event_id gesetzt:
    - Prüfe ob Nutzer Teilnehmer des Events ist
    - Hole alle User-IDs aus EventRelation
    - Erstelle Session mit diesen als recipients
  ```
  
  **Test:** Unit-Tests für Service-Methoden

---

- [ ] **4. Controller erweitern**
  
  **Ziel:** API-Endpunkte für Reisegruppen-Freigabe bereitstellen
  
  **Änderungen:**
  - `LocationSharingController::create()`: Erkenne `trip_id`/`event_id` und rufe entsprechende Service-Methode auf
  - Neue Methode `getTripSessions()`: Gibt alle aktiven Sessions für eine Reise zurück
  
  **Test:** Integration-Tests mit Mock-Daten

---

- [ ] **5. Policy anpassen**
  
  **Ziel:** Zugriffskontrolle für Reise-Sessions implementieren
  
  **Änderungen:**
  - `LocationSharingPolicy::canView()`: Erlaube Zugriff wenn Nutzer Reise-Teilnehmer ist
  - `LocationSharingPolicy::canModify()`: Nur Eigentümer oder Admin
  - `LocationSharingPolicy::canCreate()`: Nur Reise-Teilnehmer
  
  **Test:** Policy-Unit-Tests

---

- [ ] **6. OpenAPI-Spezifikation aktualisieren**
  
  **Ziel:** Dokumentation der neuen Endpunkte und Parameter
  
  **Änderungen:**
  - `/location-sharing/sessions` POST: Neue optionale Parameter `trip_id`, `event_id`
  - Response-Schema um `tripId`, `eventId`, `tripName`, `eventName` erweitern
  - Beispiel für Reise-Session hinzufügen
  
  **Test:** OpenAPI-Validierung

---

- [ ] **7. Doku aktualisieren**
  
  **Ziel:** `docs/location-sharing/readme.md` um Reisegruppen-Integration erweitern
  
  **Inhalte:**
  - Neue Sektion "Reisegruppen-Integration"
  - API-Beispiele für Reise/Event-Session
  - Erklärung der automatischen Empfänger-Zuordnung
  - Gültigkeitsdauer (3 Monate) dokumentieren
  
  **Test:** Markdown-Validierung

---

- [ ] **8. Cron-Job für Ablauf bereinigen**
  
  **Ziel:** Automatisches Aufräumen abgelaufener Sessions (90 Tage)
  
  **Änderungen:**
  - Prüfe ob bestehender Cron-Job `cleanup_location_sharing_sessions` existiert
  - Falls ja: Interval von 7 Tagen auf 90 Tage anpassen
  - Falls nein: Neuen Cron-Job erstellen
  
  **Test:** Cron-Job-Ausführung testen

---

- [ ] **9. App-Integration vorbereiten**
  
  **Ziel:** Client-seitige Anbindung vorbereiten
  
  **API-Endpunkte für App:**
  - `POST /location-sharing/sessions` mit `trip_id`/`event_id`
  - `GET /trips/{id}/location-sessions`: Alle aktiven Sessions einer Reise
  - `GET /trips/standaloneevents/{eventId}/location-sessions`: Sessions eines Events
  
  **Hinweis:** Dies ist nur die API-Seite. Die Flutter-App-Implementierung erfolgt separat.
  
  **Test:** Endpunkte mit Postman testen

---

- [ ] **10. Tests schreiben**
  
  **Ziel:** Vollständige Testabdeckung für neue Funktionen
  
  **Testfälle:**
  - Session mit `trip_id` erstellen → alle Teilnehmer als Empfänger
  - Session mit `event_id` erstellen → alle Teilnehmer als Empfänger
  - Fehler: Nutzer ist kein Reise-Teilnehmer
  - Fehler: Sowohl `trip_id` als auch `event_id` gesetzt
  - Session-Ablauf nach 90 Tagen
  - Zugriffskontrolle für Reise-Teilnehmer
  
  **Test:** PHPUnit, PHPStan Level 8

---

- [ ] **11. Deployment vorbereiten**
  
  **Ziel:** Production-ready machen
  
  **Checkliste:**
  - [ ] Migration getestet
  - [ ] Alle Tests bestanden
  - [ ] OpenAPI validiert
  - [ ] Doku aktuell
  - [ ] .htaccess geprüft
  - [ ] Admin Dashboard aktualisiert (falls nötig)
  
  **Hinweis:** Deployment erfolgt manuell über `update.sh`

---

## Offene Fragen

1. **Soll die Gültigkeitsdauer konfigurierbar sein?**
   - Aktuell: Feste 90 Tage
   - Alternative: `duration_seconds` Parameter, Maximum 90 Tage

2. **Soll die Session automatisch verlängert werden?**
   - Aktuell: Nein, läuft nach 90 Tagen ab
   - Alternative: Auto-Verlängerung wenn Reise noch aktiv

3. **Soll die Anzeige im Karten-Tab automatisch erfolgen?**
   - API-seitig: Endpunkte bereitstellen
   - App-seitig: Karten-Tab erweitern (separater Plan)

---

## Zeitplan

| Schritt | Geschätzter Aufwand | Abhängigkeit |
|---------|---------------------|--------------|
| 1. DB-Schema | 30 Min | - |
| 2. DTOs | 20 Min | 1 |
| 3. Service | 45 Min | 1, 2 |
| 4. Controller | 30 Min | 3 |
| 5. Policy | 20 Min | 3 |
| 6. OpenAPI | 15 Min | 2, 4 |
| 7. Doku | 15 Min | 6 |
| 8. Cron-Job | 15 Min | 1 |
| 9. App-Vorbereitung | 30 Min | 4, 6 |
| 10. Tests | 45 Min | 3, 4, 5 |
| 11. Deployment | 15 Min | Alle |

**Gesamt:** ~4.5 Stunden

---

## Erfolgskriterien

- [ ] Nutzer kann Session mit `trip_id` erstellen
- [ ] Alle Reise-Teilnehmer sehen die Session
- [ ] Token-basierte Ingress-URLs funktionieren
- [ ] Session läuft nach 90 Tagen automatisch ab
- [ ] PHPStan Level 8 besteht
- [ ] Alle Tests bestehen
- [ ] OpenAPI ist aktuell
- [ ] Doku ist aktuell
