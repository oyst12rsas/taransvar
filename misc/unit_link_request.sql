CREATE TABLE IF NOT EXISTS unitLinkRequest (
 requestId CHAR(32) PRIMARY KEY,
 unitId INT NOT NULL,
 ownerBinding VARCHAR(32) NOT NULL,
 peer VARCHAR(45) NOT NULL,
 gatewayId CHAR(32) NOT NULL,
 provider VARCHAR(512) NOT NULL,
 state ENUM('pending','applied','acked','cancelled') NOT NULL DEFAULT 'pending',
 created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expiresAt DATETIME NOT NULL,
 KEY unit_pending(unitId,state,expiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
