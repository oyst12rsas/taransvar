-- Apply on the central DB server only. Session evidence expires after 30 minutes.
CREATE TABLE IF NOT EXISTS demo4Session (
    sessionId CHAR(32) NOT NULL,
    tokenHash CHAR(64) NOT NULL,
    gatewayIp INT UNSIGNED NOT NULL,
    routerId INT NOT NULL,
    destinationIp INT UNSIGNED NOT NULL,
    relayIp INT UNSIGNED NOT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt DATETIME NOT NULL,
    cleanGatewayAt DATETIME NULL,
    infectedGatewayAt DATETIME NULL,
    cleanWebsiteIp INT UNSIGNED NULL,
    cleanWebsiteAt DATETIME NULL,
    infectedWebsiteIp INT UNSIGNED NULL,
    infectedWebsiteAt DATETIME NULL,
    PRIMARY KEY (sessionId),
    KEY idx_demo4_session_expiry (expiresAt),
    KEY idx_demo4_session_gateway (gatewayIp, createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
