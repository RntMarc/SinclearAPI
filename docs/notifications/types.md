# Notification-Typen – Übersicht

Diese Datei listet ausschließlich die existierenden Benachrichtigungstypen, ihre Trigger und die übermittelten Eigenschaften. Technische Details zur API stehen in [readme.md](./readme.md). Sie muss bei jeder Änderung an Notification-Typen aktualisiert werden.

| Type | Trigger | Empfänger | Relations (`data`) | Titel (API-generiert) | Text (API-generiert) |
|------|---------|-----------|--------------------|-----------------------|----------------------|
| `forum_comment` | Neuer Top-Level-Kommentar (ohne `parentId`) auf einen Forum-Post | Autor des Posts, außer bei eigenem Kommentar (kein Self-Trigger) | `comment_author` (User, Autor des Kommentars), `post_author` (User), `parent_post` (ForumPost), `parent_forum` (Forum) | `Neuer Kommentar zu deinem Beitrag` | `Jemand hat deinen Beitrag kommentiert.` |
| `forum_reply` | Neue Antwort (mit `parentId`) auf einen bestehenden Forum-Kommentar | Autor des beantworteten Kommentars, außer bei eigener Antwort (kein Self-Trigger) | `reply_author` (User, Autor der Antwort), `comment_author` (User, Autor des beantworteten Kommentars), `post_author` (User), `parent_comment` (ForumPostComment), `parent_post` (ForumPost), `parent_forum` (Forum) | `Neue Antwort auf deinen Kommentar` | `Jemand hat auf deinen Kommentar geantwortet.` |
