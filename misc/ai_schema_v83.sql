-- TaraSec DB schema version 83: normalized AI candidates.
-- Temporary standalone migration until folded into install.sql/compile.pl deployment flow.

CREATE TABLE IF NOT EXISTS aiUnitAssessment (
    aiUnitAssessmentId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aiResponseId BIGINT UNSIGNED NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ownerId INT UNSIGNED NOT NULL,
    unitId INT UNSIGNED NOT NULL,
    confidence DECIMAL(6,5) NULL,
    severity TINYINT UNSIGNED NULL,
    category VARCHAR(100) NULL,
    summary TEXT NULL,
    evidenceJson TEXT NULL,
    rawJson TEXT NOT NULL,
    PRIMARY KEY (aiUnitAssessmentId),
    UNIQUE KEY uq_ai_unit_response (aiResponseId, ownerId, unitId),
    KEY idx_ai_unit (ownerId, unitId),
    KEY idx_ai_unit_confidence (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aiBotnetCandidate (
    aiBotnetCandidateId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aiResponseId BIGINT UNSIGNED NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    candidateKey VARCHAR(128) NOT NULL,
    confidence DECIMAL(6,5) NULL,
    summary TEXT NULL,
    membersJson TEXT NULL,
    evidenceJson TEXT NULL,
    rawJson TEXT NOT NULL,
    PRIMARY KEY (aiBotnetCandidateId),
    UNIQUE KEY uq_ai_botnet_response (aiResponseId, candidateKey),
    KEY idx_ai_botnet_confidence (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE setup SET dbVersion = 83;
