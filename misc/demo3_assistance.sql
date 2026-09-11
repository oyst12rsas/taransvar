CREATE TABLE IF NOT EXISTS demoAssistanceSession (
    sessionId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL DEFAULT 'Community infection exercise',
    threshold TINYINT UNSIGNED NOT NULL DEFAULT 7,
    state ENUM('setup','active','contained','releasing','closed') NOT NULL DEFAULT 'setup',
    controllerToken CHAR(64) NOT NULL,
    targetIp VARCHAR(45) NOT NULL,
    containmentSeconds SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    assistanceRequestId INT UNSIGNED NULL,
    releaseRequestId INT UNSIGNED NULL,
    startsAt DATETIME NULL,
    blockAt DATETIME NULL,
    assistanceIssuedAt DATETIME NULL,
    releaseAt DATETIME NULL,
    closedAt DATETIME NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sessionId),
    UNIQUE KEY uq_demo_assistance_controller (controllerToken),
    KEY ix_demo_assistance_state (state, sessionId),
    KEY ix_demo_assistance_block (state, blockAt),
    KEY ix_demo_assistance_release (state, releaseAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demoAssistanceParticipant (
    participantId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sessionId INT UNSIGNED NOT NULL,
    participantToken CHAR(64) NOT NULL,
    nickname VARCHAR(80) NOT NULL DEFAULT '',
    observedIp VARCHAR(45) NOT NULL,
    severity TINYINT UNSIGNED NOT NULL DEFAULT 0,
    decision ENUM('pending','connected','silent','recovered') NOT NULL DEFAULT 'pending',
    joinedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeenAt DATETIME NULL,
    firstSilentAt DATETIME NULL,
    recoveredAt DATETIME NULL,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (participantId),
    UNIQUE KEY uq_demo_assistance_participant_token (participantToken),
    KEY ix_demo_assistance_session (sessionId, participantId),
    KEY ix_demo_assistance_seen (sessionId, lastSeenAt),
    CONSTRAINT fk_demo_assistance_participant_session
        FOREIGN KEY (sessionId) REFERENCES demoAssistanceSession(sessionId)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
