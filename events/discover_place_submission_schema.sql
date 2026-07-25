CREATE TABLE IF NOT EXISTS DiscoverPlaceSubmission (
    id            VARCHAR(191) NOT NULL PRIMARY KEY,
    userId        VARCHAR(191) NOT NULL,
    name          VARCHAR(255) NOT NULL,
    address       TEXT,
    latitude      DOUBLE DEFAULT NULL,
    longitude     DOUBLE DEFAULT NULL,
    photo         TEXT,
    mapLink       TEXT,
    website       TEXT,
    rating        TINYINT NOT NULL,
    comment       TEXT DEFAULT NULL,
    note          TEXT DEFAULT NULL,
    status        ENUM('pending','approved','rejected','transferred') NOT NULL DEFAULT 'pending',
    adminNote     TEXT,
    targetPlaceId VARCHAR(191) DEFAULT NULL,
    createdAt     DATETIME(3) NOT NULL DEFAULT NOW(3),
    updatedAt     DATETIME(3) NOT NULL DEFAULT NOW(3),

    INDEX idx_submission_status (status),
    INDEX idx_submission_userId (userId)
);
