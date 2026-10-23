-- ModerationRequest.objectType um 'chat_message' erweitern.
--
-- Chat-Nachrichten (DirectMessage) koennen seit der Moderations-Anbindung des
-- Clients gemeldet bzw. zur Loeschung beantragt werden. Das ENUM wird hier
-- vollstaendig neu gesetzt: 'chat_message' kommt hinzu, alle bereits in der
-- Anwendung unterstuetzten Typen bleiben enthalten. MODIFY COLUMN ist
-- rerun-sicher und korrigiert zugleich eine moegliche Enum-Drift.
--
-- Hinweis: Es gibt keinen lokalen Migration-Runner; die Datei wird vom
-- Betreiber auf dem Server angewendet.

ALTER TABLE `ModerationRequest`
  MODIFY COLUMN `objectType` enum(
    'user','forum_post','recipe','explore_place',
    'recipe_review','forum_comment','explore_comment',
    'feedback_suggestion','feedback_comment',
    'travel_trip','travel_event','travel_accommodation','travel_ticket',
    'subscription','calendar_event','story','chat_message'
  ) COLLATE utf8mb4_unicode_ci NOT NULL;
