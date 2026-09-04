-- TaraSec Community Hotspot roaming earnings support.
-- Safe to run repeatedly on MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS hotspotRoamingBinding (
  deviceKey varchar(32) NOT NULL,
  customerId varchar(128) NULL,
  source varchar(32) NOT NULL DEFAULT 'global',
  boundTime timestamp NOT NULL DEFAULT current_timestamp(),
  lastSeen timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (deviceKey),
  KEY hotspotRoamingBinding_customer (customerId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS hotspotEarningConfig (
  configId tinyint unsigned NOT NULL DEFAULT 1,
  roamingPriceKshPerMiB decimal(12,6) NOT NULL DEFAULT 0.097656,
  networkFeePercent decimal(6,3) NOT NULL DEFAULT 10.000,
  currency char(3) NOT NULL DEFAULT 'KES',
  isDemo bit(1) NOT NULL DEFAULT b'1',
  updatedTime timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (configId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO hotspotEarningConfig(configId,roamingPriceKshPerMiB,networkFeePercent,currency,isDemo)
VALUES(1,0.097656,10.000,'KES',b'1')
ON DUPLICATE KEY UPDATE configId=configId;

CREATE TABLE IF NOT EXISTS hotspotEarning (
  earningId bigint unsigned NOT NULL AUTO_INCREMENT,
  sessionId bigint unsigned NOT NULL,
  customerId varchar(128) NULL,
  deviceKey varchar(32) NULL,
  usageMiB decimal(14,4) NOT NULL,
  grossAmount decimal(14,4) NOT NULL,
  networkFee decimal(14,4) NOT NULL,
  netAmount decimal(14,4) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'KES',
  settlementStatus enum('pending','available','paid','reversed') NOT NULL DEFAULT 'pending',
  isDemo bit(1) NOT NULL DEFAULT b'1',
  createdTime timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (earningId),
  KEY hotspotEarning_session (sessionId),
  KEY hotspotEarning_created (createdTime),
  KEY hotspotEarning_status (settlementStatus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
