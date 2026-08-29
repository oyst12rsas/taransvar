create view vListings as
select di.ip, color, handled from domainIp di join domain d on d.domainId = di.domainId
union
select ip, color, handled from colorListings;

insert into setup (adminIP, internalIP, nettmask, globalDb1ip, dbVersion) values (inet_aton('10.10.10.10'),inet_aton('192.168.50.1'),inet_aton('255.255.255.0'), inet_aton('81.88.19.252'),50);

-- Partner trust metadata for new installations.
ALTER TABLE partner
  ADD COLUMN externalId varchar(64) DEFAULT NULL AFTER partnerId,
  ADD COLUMN sourceType enum('manual','hotspot','api') NOT NULL DEFAULT 'manual' AFTER techPhone,
  ADD COLUMN trustScore tinyint unsigned NOT NULL DEFAULT 50 AFTER sourceType,
  ADD COLUMN trustStatus enum('low','verified','established','suspended','revoked') NOT NULL DEFAULT 'verified' AFTER trustScore,
  ADD COLUMN enrolledAt timestamp NOT NULL DEFAULT current_timestamp() AFTER trustStatus,
  ADD COLUMN trustUpdatedAt timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER enrolledAt,
  ADD UNIQUE KEY partner_external_id (externalId),
  ADD KEY partner_trust_status_score (trustStatus, trustScore);
