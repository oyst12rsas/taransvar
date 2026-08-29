-- TaraSec partner trust metadata.
-- Safe to run once on existing installations. New installations invoke this
-- immediately after db/taransvar.sql.

ALTER TABLE partner
  ADD COLUMN externalId varchar(64) DEFAULT NULL AFTER partnerId,
  ADD COLUMN sourceType enum('manual','hotspot','api') NOT NULL DEFAULT 'manual' AFTER techPhone,
  ADD COLUMN trustScore tinyint unsigned NOT NULL DEFAULT 50 AFTER sourceType,
  ADD COLUMN trustStatus enum('low','verified','established','suspended','revoked') NOT NULL DEFAULT 'verified' AFTER trustScore,
  ADD COLUMN enrolledAt timestamp NOT NULL DEFAULT current_timestamp() AFTER trustStatus,
  ADD COLUMN trustUpdatedAt timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER enrolledAt,
  ADD UNIQUE KEY partner_external_id (externalId),
  ADD KEY partner_trust_status_score (trustStatus, trustScore);

-- Existing manually-created partners pre-date automatic enrollment and are
-- treated as verified, not automatically "established".
UPDATE partner
   SET sourceType = 'manual',
       trustScore = CASE WHEN trustScore = 50 THEN 60 ELSE trustScore END,
       trustStatus = CASE WHEN trustStatus = 'verified' THEN 'verified' ELSE trustStatus END
 WHERE sourceType = 'manual';
