CREATE TABLE IF NOT EXISTS gatewayAppRequest (
 requestId CHAR(32) PRIMARY KEY,
 gatewayId CHAR(32) NOT NULL,
 provider VARCHAR(512) NOT NULL,
 subjectHash CHAR(64) NOT NULL,
 clientHash CHAR(64) NOT NULL,
 confirmationCode CHAR(8) NOT NULL,
 created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expiresAt DATETIME NOT NULL,
 decidedAt DATETIME NULL,
 approvedByUserId INT NULL,
 decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 KEY request_rate(subjectHash,created)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gatewayAppLink (
 linkId BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 gatewayId CHAR(32) NOT NULL,
 provider VARCHAR(512) NOT NULL,
 subjectHash CHAR(64) NOT NULL,
 clientHash CHAR(64) NOT NULL,
 tokenHash CHAR(64) NULL,
 expiresAt DATETIME NULL,
 active BIT(1) NOT NULL DEFAULT b'1',
 approvedByUserId INT NOT NULL,
 UNIQUE KEY account_app(subjectHash,clientHash),
 UNIQUE KEY gateway_token(tokenHash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
