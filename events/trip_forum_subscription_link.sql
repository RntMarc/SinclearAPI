SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

ALTER TABLE TravelTrip ADD COLUMN forumId VARCHAR(191) DEFAULT NULL,
  ADD KEY idx_trip_forum (forumId),
  ADD FOREIGN KEY (forumId) REFERENCES Forum(id) ON DELETE SET NULL;

CREATE TABLE TravelTripSubscription (
  tripId VARCHAR(191) NOT NULL,
  subscriptionId VARCHAR(191) NOT NULL,
  PRIMARY KEY (tripId, subscriptionId),
  KEY idx_tts_subscription (subscriptionId),
  FOREIGN KEY (tripId) REFERENCES TravelTrip(id) ON DELETE CASCADE,
  FOREIGN KEY (subscriptionId) REFERENCES Subscription(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
