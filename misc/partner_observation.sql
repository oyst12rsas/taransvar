-- Optional migration; no partner restrictions are enabled by this feature.
CREATE TABLE IF NOT EXISTS partnerObservation (
 observationId bigint unsigned AUTO_INCREMENT PRIMARY KEY,
 routerId int NOT NULL, sourceIp int unsigned NOT NULL,
 reportId int unsigned NULL, sessionId char(32) NULL,
 created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 notifiedAt timestamp NULL, expiresAt timestamp NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'awaiting_notification',
 KEY active_source(sourceIp,expiresAt)
);
CREATE TABLE IF NOT EXISTS partnerObservationSample (
 observationId bigint unsigned NOT NULL, receiverIp int unsigned NOT NULL,
 untagged int unsigned NOT NULL, tagged int unsigned NOT NULL,
 unknownTag int unsigned NOT NULL, maliciousUntagged int unsigned NOT NULL,
 policyDeniedUntagged int unsigned NOT NULL,
 checkedAt timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(observationId,receiverIp)
);
CREATE TABLE IF NOT EXISTS partnerObservationLocalEvidence (
 cursorHash char(64) PRIMARY KEY,syslogId int unsigned NOT NULL,
 created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_observation_threat_tuple
 ON syslogThreat(src_ip,src_port,dst_ip,dst_port,syslogId);
