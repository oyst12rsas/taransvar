-- TaraSec hotspot pricing and usage support for the Community Hotspot demo.
-- Safe to run repeatedly on MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS hotspotPricePackage (
  packageId int unsigned NOT NULL AUTO_INCREMENT,
  label varchar(100) NOT NULL,
  quotaMB int unsigned NOT NULL,
  priceKsh decimal(10,2) NOT NULL,
  currency char(3) NOT NULL DEFAULT 'KES',
  enabled bit(1) NOT NULL DEFAULT b'1',
  isDemo bit(1) NOT NULL DEFAULT b'1',
  sortOrder int unsigned NOT NULL DEFAULT 0,
  createdTime timestamp NOT NULL DEFAULT current_timestamp(),
  updatedTime timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (packageId),
  UNIQUE KEY hotspotPricePackage_quota_currency (quotaMB,currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- openNDS accounting checkpoints. The accounting worker stores the latest
-- cumulative upload/download counters here so the portal/app can show actual
-- measured traffic without maintaining a second counter.
CREATE TABLE IF NOT EXISTS hotspotUsageCheckpoint (
  sessionId bigint unsigned NOT NULL,
  uploadKiB bigint unsigned NOT NULL DEFAULT 0,
  downloadKiB bigint unsigned NOT NULL DEFAULT 0,
  updated timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (sessionId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Demo pricing. 1 GB is the reference price at KSh 100; larger packages get
-- progressively better effective rates. INSERT ... ON DUPLICATE KEY keeps the
-- demo rows current while still allowing the administrator to edit them later.
INSERT INTO hotspotPricePackage(label,quotaMB,priceKsh,currency,enabled,isDemo,sortOrder) VALUES
 ('100 MB',100,15.00,'KES',b'1',b'1',10),
 ('250 MB',250,30.00,'KES',b'1',b'1',20),
 ('500 MB',500,55.00,'KES',b'1',b'1',30),
 ('1 GB',1024,100.00,'KES',b'1',b'1',40),
 ('2 GB',2048,180.00,'KES',b'1',b'1',50),
 ('5 GB',5120,400.00,'KES',b'1',b'1',60),
 ('10 GB',10240,700.00,'KES',b'1',b'1',70)
ON DUPLICATE KEY UPDATE
 label=VALUES(label),
 priceKsh=VALUES(priceKsh),
 enabled=VALUES(enabled),
 isDemo=VALUES(isDemo),
 sortOrder=VALUES(sortOrder);
