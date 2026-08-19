-- TaraSec gateway AI policy and centrally received gateway assessments.
-- Central DB only. These tables deliberately store no AI provider credential.

CREATE TABLE IF NOT EXISTS aiGatewayPolicy (
    gatewayIp INT UNSIGNED NOT NULL,
    taraSecFundedTest BIT(1) NOT NULL DEFAULT b'0',
    dailyCallLimit SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fundedUntil TIMESTAMP NULL,
    updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    comment VARCHAR(255) NULL,
    PRIMARY KEY (gatewayIp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aiGatewayAssessment (
    aiGatewayAssessmentId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gatewayIp INT UNSIGNED NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fundingMode ENUM('tarasec_test','owner_funded') NOT NULL,
    gatewayAssessmentId VARCHAR(128) NULL,
    assessmentJson MEDIUMTEXT NOT NULL,
    evidenceSummaryJson MEDIUMTEXT NULL,
    reportedBack BIT(1) NOT NULL DEFAULT b'0',
    PRIMARY KEY (aiGatewayAssessmentId),
    KEY idx_ai_gateway_created (gatewayIp,created),
    KEY idx_ai_gateway_mode_created (fundingMode,created),
    UNIQUE KEY uq_ai_gateway_external (gatewayIp,gatewayAssessmentId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example: sponsor gateway 100.68.10.7 for up to 12 calls/day:
-- INSERT INTO aiGatewayPolicy (gatewayIp,taraSecFundedTest,dailyCallLimit,comment)
-- VALUES (INET_ATON('100.68.10.7'),b'1',12,'TaraSec AI test')
-- ON DUPLICATE KEY UPDATE taraSecFundedTest=b'1',dailyCallLimit=12,comment='TaraSec AI test';
