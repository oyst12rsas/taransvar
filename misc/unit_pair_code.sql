-- Short-lived QR handoffs. No plaintext code or app credential is stored here.
CREATE TABLE IF NOT EXISTS unitPairCode (
    codeHash CHAR(64) NOT NULL PRIMARY KEY,
    unitId INT NOT NULL,
    ownerId INT NULL,
    peerIp INT UNSIGNED NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires TIMESTAMP NOT NULL,
    consumed TIMESTAMP NULL,
    KEY idx_pair_unit_created (unitId, created),
    KEY idx_pair_expires (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
