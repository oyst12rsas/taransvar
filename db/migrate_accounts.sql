-- TaraSec account-model migration. Safe to run repeatedly.
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `isAdmin` bit(1) NOT NULL DEFAULT b'0',
  ADD COLUMN IF NOT EXISTS `lastLogin` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `lastLoginIp` int(10) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `loginFailsSinceSuccess` int(10) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `loginFailReportedTime` timestamp NULL DEFAULT NULL;

ALTER TABLE `setup`
  ADD COLUMN IF NOT EXISTS `requireRegistration` bit(1) NOT NULL DEFAULT b'1',
  ADD COLUMN IF NOT EXISTS `selfRegistration` bit(1) NOT NULL DEFAULT b'1';

CREATE TABLE IF NOT EXISTS `hotspotSubscriber` (
  `subscriberId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `createdTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmedTime` timestamp NULL DEFAULT NULL,
  `lastLogin` timestamp NULL DEFAULT NULL,
  `subscriptionType` enum('quota','expiry','limited') NOT NULL DEFAULT 'expiry',
  `expiryTime` timestamp NULL DEFAULT NULL,
  `giveHoursAfterLogin` smallint(5) unsigned DEFAULT NULL,
  `quotaMB` int(10) unsigned NOT NULL DEFAULT 0,
  `usageMB` double NOT NULL DEFAULT 0,
  `campaignId` smallint(5) unsigned DEFAULT NULL,
  `enabled` bit(1) NOT NULL DEFAULT b'1',
  `legacyRadcheckId` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`subscriberId`),
  UNIQUE KEY `hotspotSubscriber_username` (`username`),
  UNIQUE KEY `hotspotSubscriber_legacyRadcheckId` (`legacyRadcheckId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Administrator identities are never hotspot subscriber identities.
DELETE hs FROM hotspotSubscriber hs
JOIN `user` u ON u.username=hs.username
WHERE CAST(u.isAdmin AS UNSIGNED)=1;

-- Migrate legacy RADIUS credentials only when they do not collide with an administrator.
INSERT IGNORE INTO hotspotSubscriber
(username,password,name,email,phone,createdTime,confirmedTime,lastLogin,subscriptionType,expiryTime,giveHoursAfterLogin,quotaMB,usageMB,campaignId,enabled,legacyRadcheckId)
SELECT r.username,COALESCE(r.value,''),NULLIF(r.name,''),NULLIF(r.email,''),NULLIF(r.phone,''),r.createdTime,
       CASE WHEN r.op='==' AND COALESCE(r.attribute,'')='' THEN COALESCE(r.confirmedTime,r.createdTime) ELSE r.confirmedTime END,
       r.last_login,r.subscriptionType,r.expirytime,r.giveHoursAfterLogin,GREATEST(COALESCE(r.mbquota,0),0),GREATEST(COALESCE(r.mbusage,0),0),r.campaignid,b'1',r.id
FROM radcheck r
LEFT JOIN `user` u ON u.username=r.username AND CAST(u.isAdmin AS UNSIGNED)=1
WHERE u.userId IS NULL
  AND ((r.op=':=' AND r.attribute='Cleartext-Password') OR (r.op='==' AND COALESCE(r.attribute,'')=''));

-- A new hotspot needs one non-admin login for initial setup/testing. It is shown
-- by the captive portal only while it remains the sole enabled subscriber.
INSERT INTO hotspotSubscriber
(username,password,confirmedTime,subscriptionType,giveHoursAfterLogin,enabled)
SELECT 'hotspot', SUBSTRING(REPLACE(UUID(),'-',''),1,8), NOW(), 'expiry', 24, b'1'
WHERE NOT EXISTS (SELECT 1 FROM hotspotSubscriber WHERE CAST(enabled AS UNSIGNED)=1);
