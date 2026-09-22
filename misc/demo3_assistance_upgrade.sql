-- Upgrade an existing Demo 3 installation. Safe to run more than once.
ALTER TABLE demoAssistanceSession
    ADD COLUMN IF NOT EXISTS visibility ENUM('public','group') NOT NULL DEFAULT 'public' AFTER targetIp,
    ADD COLUMN IF NOT EXISTS groupLabel VARCHAR(120) NOT NULL DEFAULT '' AFTER visibility,
    ADD COLUMN IF NOT EXISTS joinCodeHash CHAR(64) NULL AFTER groupLabel,
    ADD INDEX IF NOT EXISTS ix_demo_assistance_visibility (visibility,state,sessionId);

ALTER TABLE demoAssistanceParticipant
    MODIFY severity TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY decision ENUM('pending','connected','silent','recovered','left') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS leftAt DATETIME NULL AFTER recoveredAt;


-- Demo 3 assistance requests use category demo3_<sessionId>. The historical
-- assistanceRequest ENUM rejects those values under strict SQL mode.
ALTER TABLE assistanceRequest
    MODIFY category VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS isDemo BIT(1) NOT NULL DEFAULT b'0' AFTER sentPartners;

UPDATE assistanceRequest
SET isDemo=b'1'
WHERE category LIKE 'demo3\\_%';
