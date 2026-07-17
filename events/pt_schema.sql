-- Public Transport Schema (Transitious Integration)
-- Erstellt: 2026-07-16

-- =============================================================================
-- PtStation: Lokaler Cache für Stationen (optional, für schnelle Suche)
-- =============================================================================
CREATE TABLE PtStation (
  id VARCHAR(191) NOT NULL,
  name VARCHAR(255) NOT NULL,
  latitude DOUBLE NULL,
  longitude DOUBLE NULL,
  lastUpdated DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_station_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PtJourney: Gespeicherte Verbindung (A→B)
-- =============================================================================
CREATE TABLE PtJourney (
  id VARCHAR(191) NOT NULL,
  tripId VARCHAR(191) NULL,           -- FK -> TravelTrip.id (optional)
  creatorId VARCHAR(191) NOT NULL,    -- FK -> User.id
  fromStationId VARCHAR(191) NOT NULL,
  fromStationName VARCHAR(255) NOT NULL,
  toStationId VARCHAR(191) NOT NULL,
  toStationName VARCHAR(255) NOT NULL,
  departureTime DATETIME NOT NULL,
  arrivalTime DATETIME NOT NULL,
  duration INT NOT NULL,              -- in Sekunden
  transfers INT DEFAULT 0,
  chosenAt DATETIME NOT NULL,
  createdAt DATETIME(3) NOT NULL,
  updatedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_journey_creator (creatorId),
  INDEX idx_pt_journey_trip (tripId),
  INDEX idx_pt_journey_departure (departureTime),
  CONSTRAINT fk_pt_journey_creator FOREIGN KEY (creatorId) REFERENCES User(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_journey_trip FOREIGN KEY (tripId) REFERENCES TravelTrip(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PtLeg: Einzelner Fahrtabschnitt einer gespeicherten Verbindung
-- =============================================================================
CREATE TABLE PtLeg (
  id VARCHAR(191) NOT NULL,
  journeyId VARCHAR(191) NOT NULL,
  legIndex TINYINT NOT NULL,
  mode VARCHAR(50) NOT NULL,          -- WALK, BUS, RAIL, TRAM, SUBWAY, etc.
  lineName VARCHAR(191) NULL,         -- z.B. "ICE 123"
  lineProduct VARCHAR(50) NULL,       -- z.B. "nationalExpress", "regional"
  fromStationId VARCHAR(191) NOT NULL,
  fromStationName VARCHAR(255) NOT NULL,
  toStationId VARCHAR(191) NOT NULL,
  toStationName VARCHAR(255) NOT NULL,
  tripId VARCHAR(191) NULL,           -- Transitious Trip-ID (für Refresh)
  plannedDeparture DATETIME NOT NULL,
  plannedArrival DATETIME NOT NULL,
  actualDeparture DATETIME NULL,
  actualArrival DATETIME NULL,
  departureDelay INT NULL,            -- in Sekunden
  arrivalDelay INT NULL,              -- in Sekunden
  departurePlatform VARCHAR(10) NULL,
  arrivalPlatform VARCHAR(10) NULL,
  cancelled TINYINT(1) DEFAULT 0,
  realTimeState VARCHAR(20) NULL,     -- CANCELED, UPDATED, SCHEDULED
  rawResponse JSON NULL,
  lastCheckedAt DATETIME(3) NULL,
  createdAt DATETIME(3) NOT NULL,
  updatedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  INDEX idx_pt_leg_journey (journeyId),
  INDEX idx_pt_leg_trip (tripId),
  INDEX idx_pt_leg_lastChecked (lastCheckedAt),
  CONSTRAINT fk_pt_leg_journey FOREIGN KEY (journeyId) REFERENCES PtJourney(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PtParticipant: Teilnehmer-Verknüpfung
-- =============================================================================
CREATE TABLE PtParticipant (
  journeyId VARCHAR(191) NOT NULL,
  userId VARCHAR(191) NOT NULL,
  addedAt DATETIME(3) NOT NULL,
  PRIMARY KEY (journeyId, userId),
  CONSTRAINT fk_pt_participant_journey FOREIGN KEY (journeyId) REFERENCES PtJourney(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_participant_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
