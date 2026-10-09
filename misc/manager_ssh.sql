CREATE TABLE IF NOT EXISTS managerSshRequest (
    sequenceId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    requestId CHAR(32) NOT NULL UNIQUE,
    managerRequestId INT NOT NULL,
    sourceIp VARCHAR(45) NOT NULL,
    action ENUM('open','close') NOT NULL,
    seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('pending','applied','rejected') NOT NULL DEFAULT 'pending',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    appliedAt TIMESTAMP NULL,
    INDEX pending_requests(state,sequenceId),
    INDEX manager_history(managerRequestId,createdAt)
);
