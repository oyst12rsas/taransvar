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
