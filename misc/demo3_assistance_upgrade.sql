-- Upgrade an existing Demo 3 installation. Safe to run more than once.
ALTER TABLE demoAssistanceSession
    ADD COLUMN IF NOT EXISTS visibility ENUM('public','group') NOT NULL DEFAULT 'public' AFTER targetIp,
    ADD COLUMN IF NOT EXISTS groupLabel VARCHAR(120) NOT NULL DEFAULT '' AFTER visibility,
    ADD COLUMN IF NOT EXISTS joinCodeHash CHAR(64) NULL AFTER groupLabel,
    ADD INDEX IF NOT EXISTS ix_demo_assistance_visibility (visibility,state,sessionId);

ALTER TABLE demoAssistanceParticipant
    MODIFY decision ENUM('pending','connected','silent','recovered','left') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS leftAt DATETIME NULL AFTER recoveredAt;
