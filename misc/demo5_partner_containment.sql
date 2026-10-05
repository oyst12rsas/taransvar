-- Apply on the DB server and receiving nodes before deploying Demo 5.
ALTER TABLE partnerRouter ADD COLUMN IF NOT EXISTS demo5Enabled tinyint NOT NULL DEFAULT 0;
ALTER TABLE partnerRouter ADD COLUMN IF NOT EXISTS taggingState varchar(24) NOT NULL DEFAULT 'unknown';
ALTER TABLE partnerRouter ADD COLUMN IF NOT EXISTS taggingStateUpdated timestamp NULL;
ALTER TABLE partnerRouter ADD COLUMN IF NOT EXISTS restrictionUntil timestamp NULL;
ALTER TABLE partnerRouter ADD COLUMN IF NOT EXISTS restrictionReason varchar(255) NULL;
ALTER TABLE colorListings ADD COLUMN IF NOT EXISTS listingSource varchar(24) NOT NULL DEFAULT 'manual';
ALTER TABLE colorListings ADD COLUMN IF NOT EXISTS expiresAt timestamp NULL;
CREATE TABLE IF NOT EXISTS partnerRestrictionReceiver (
 receiverIp int unsigned PRIMARY KEY, tokenHash char(64) NOT NULL,
 enabled tinyint NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS demo5Configuration (
 routerId int NOT NULL PRIMARY KEY, receiverIp int unsigned NOT NULL,
 receiverPort smallint unsigned NOT NULL DEFAULT 22,
 graceSeconds int NOT NULL DEFAULT 30, restrictionSeconds int NOT NULL DEFAULT 120
);
CREATE TABLE IF NOT EXISTS demo5Session (
 sessionId char(32) PRIMARY KEY, tokenHash char(64) NOT NULL,
 routerId int NOT NULL, sourceIp int unsigned NOT NULL,
 receiverIp int unsigned NOT NULL, receiverPort smallint unsigned NOT NULL,
 created timestamp NOT NULL DEFAULT current_timestamp(), expiresAt timestamp NOT NULL,
 firstReportId int unsigned NULL, latestReportId int unsigned NULL,
 firstReportAt timestamp NULL, notifiedAt timestamp NULL, lastUntaggedAt timestamp NULL,
 lastTaggedAt timestamp NULL, restrictedAt timestamp NULL, restrictionUntil timestamp NULL,
 state varchar(32) NOT NULL DEFAULT 'awaiting_report',
 KEY active_source (sourceIp,expiresAt)
);
CREATE TABLE IF NOT EXISTS partnerIncidentEvidence (
 evidenceId bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 sessionId char(32) NOT NULL, reportId int unsigned NOT NULL,
 receiverIp int unsigned NOT NULL, sourcePort smallint unsigned NOT NULL,
 observedTag int unsigned NULL, observedAt timestamp NOT NULL,
 UNIQUE KEY report_observation (sessionId,reportId,observedAt,sourcePort)
);
CREATE TABLE IF NOT EXISTS partnerRestrictionDelivery (
 sessionId char(32) NOT NULL, receiverIp int unsigned NOT NULL,
 state varchar(16) NOT NULL DEFAULT 'pending', message varchar(255) NULL,
 appliedAt timestamp NULL, releasedAt timestamp NULL,
 PRIMARY KEY (sessionId,receiverIp)
);
-- Existing manual listings retain their legacy behavior. Expiring partner entries
-- use the independent timeout set, so they cannot stick forever in tarakernel.
CREATE OR REPLACE VIEW vListings AS
 SELECT di.ip,color,handled FROM domainIp di JOIN domain d ON d.domainId=di.domainId
 UNION SELECT ip,color,handled FROM colorListings WHERE listingSource='manual';
