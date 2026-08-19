-- ChatConversation: Add image column for group chat icons.
-- The image is stored as base64-encoded data (like other images in the system).
-- Only applies to group conversations; direct conversations ignore this field.

ALTER TABLE ChatConversation
ADD COLUMN image MEDIUMTEXT NULL AFTER name;
