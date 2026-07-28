ALTER TABLE TravelEventTicket ADD COLUMN title VARCHAR(255) DEFAULT NULL COMMENT 'User-facing ticket label (e.g. Messe, Fahrschein)' AFTER type;
