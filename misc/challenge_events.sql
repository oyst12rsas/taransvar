CREATE TABLE IF NOT EXISTS publicChallenge (
    challengeId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    joinText VARCHAR(160) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    destination VARCHAR(32) NOT NULL DEFAULT 'demo3',
    destinationUrl VARCHAR(500) NOT NULL DEFAULT '',
    allowedCidrs TEXT NOT NULL,
    startsAt DATETIME NOT NULL,
    endsAt DATETIME NOT NULL,
    active BIT(1) NOT NULL DEFAULT b'1',
    priority INT NOT NULL DEFAULT 0,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (challengeId),
    UNIQUE KEY uq_publicChallenge_slug (slug),
    KEY idx_publicChallenge_window (active, startsAt, endsAt, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example university event. Keep disabled until the real university public IP/CIDR
-- and event times are known, then enable it instead of changing the Android app.
INSERT INTO publicChallenge
(slug,title,joinText,description,destination,destinationUrl,allowedCidrs,startsAt,endsAt,active,priority)
VALUES
('pizza-challenge-example','TaraSec Pizza Challenge','Join Pizza Challenge','Run Demo 3, see cooperative cyber defence in action, and explain what you think it means for cybercrime.','demo3','','203.0.113.0/24','2026-01-01 00:00:00','2026-01-01 01:00:00',b'0',100)
ON DUPLICATE KEY UPDATE slug=VALUES(slug);