-- TaraSec hotspot roaming/reward pilot schema
--
-- Core rules:
--   * a subscriber is a TaraSec subscriber, not a subscriber of one hotspot;
--   * subscriber value is held as global TaraSec credits, not fixed GB;
--   * each serving hotspot sets an approved credits-per-MiB price;
--   * provider earnings are separate from subscriber credits and can later be
--     settled locally/cross-border according to owner jurisdiction and eligibility;
--   * session pricing is snapshotted so historical usage is never repriced.
--
-- This file is repeatable on MySQL/MariaDB and also migrates the earlier pilot
-- tables when they already exist.

CREATE TABLE IF NOT EXISTS hotspotOwner (
    ownerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    displayName VARCHAR(255) NULL,
    primaryCountry CHAR(2) NOT NULL,
    cashEligible BIT NOT NULL DEFAULT b'0',
    businessPermitRef VARCHAR(255) NULL,
    verifiedAt DATETIME NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ownerId),
    KEY ix_hotspotOwner_country (primaryCountry, cashEligible)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotGateway (
    gatewayId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    gatewayKey VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    apiTokenHash CHAR(64) NOT NULL,
    ownerId BIGINT UNSIGNED NULL,
    countryCode CHAR(2) NULL,
    priceCreditsPerMiB DECIMAL(20,6) NOT NULL DEFAULT 1.000000,
    providerRewardBps SMALLINT UNSIGNED NOT NULL DEFAULT 7000,
    priceLabel VARCHAR(128) NULL,
    ownerPhone VARCHAR(64) NULL,
    ownerEmail VARCHAR(255) NULL,
    ownerAddress VARCHAR(512) NULL,
    active BIT NOT NULL DEFAULT b'1',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (gatewayId),
    UNIQUE KEY uq_hotspotGateway_key (gatewayKey),
    KEY ix_hotspotGateway_owner (ownerId),
    CONSTRAINT fk_hotspotGateway_owner FOREIGN KEY (ownerId) REFERENCES hotspotOwner(ownerId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotCustomer (
    customerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ownerId BIGINT UNSIGNED NULL,
    phone VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    active BIT NOT NULL DEFAULT b'1',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customerId),
    KEY ix_hotspotCustomer_owner (ownerId),
    KEY ix_hotspotCustomer_phone (phone),
    KEY ix_hotspotCustomer_email (email),
    CONSTRAINT fk_hotspotCustomer_owner FOREIGN KEY (ownerId) REFERENCES hotspotOwner(ownerId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotDevice (
    deviceId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    deviceKey VARCHAR(128) NOT NULL,
    firstSeen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (deviceId),
    UNIQUE KEY uq_hotspotDevice_key (deviceKey),
    CONSTRAINT fk_hotspotDevice_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;

-- Legacy payment records are retained for audit/import purposes. New purchases
-- should credit hotspotCreditAccount/hotspotCreditLedger.
CREATE TABLE IF NOT EXISTS hotspotPolicy (
    policyId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    versionName VARCHAR(64) NOT NULL,
    serviceBps INT UNSIGNED NOT NULL DEFAULT 1000,
    sellerBps INT UNSIGNED NOT NULL DEFAULT 1500,
    providerPoolBps INT UNSIGNED NOT NULL DEFAULT 7500,
    active BIT NOT NULL DEFAULT b'0',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (policyId),
    UNIQUE KEY uq_hotspotPolicy_version (versionName)
) ENGINE=InnoDB;

INSERT INTO hotspotPolicy(versionName, serviceBps, sellerBps, providerPoolBps, active)
SELECT 'pilot-10-15-75', 1000, 1500, 7500, b'0'
WHERE NOT EXISTS (SELECT 1 FROM hotspotPolicy WHERE versionName='pilot-10-15-75');

CREATE TABLE IF NOT EXISTS hotspotPayment (
    paymentId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    sellerGatewayId INT UNSIGNED NOT NULL,
    policyId INT UNSIGNED NULL,
    amountMinor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    creditsPurchased DECIMAL(20,6) NULL,
    externalRef VARCHAR(128) NULL,
    status ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'paid',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (paymentId),
    KEY ix_hotspotPayment_customer (customerId),
    CONSTRAINT fk_hotspotPayment_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId),
    CONSTRAINT fk_hotspotPayment_seller FOREIGN KEY (sellerGatewayId) REFERENCES hotspotGateway(gatewayId),
    CONSTRAINT fk_hotspotPayment_policy FOREIGN KEY (policyId) REFERENCES hotspotPolicy(policyId)
) ENGINE=InnoDB;

-- Kept for compatibility with the first pilot. Credit balance is authoritative
-- for roaming access; an entitlement may still be used by older integrations.
CREATE TABLE IF NOT EXISTS hotspotEntitlement (
    entitlementId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    paymentId BIGINT UNSIGNED NULL,
    validFrom DATETIME NOT NULL,
    validUntil DATETIME NOT NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entitlementId),
    KEY ix_hotspotEntitlement_customer (customerId, status, validUntil),
    CONSTRAINT fk_hotspotEntitlement_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId),
    CONSTRAINT fk_hotspotEntitlement_payment FOREIGN KEY (paymentId) REFERENCES hotspotPayment(paymentId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotCreditAccount (
    customerId BIGINT UNSIGNED NOT NULL,
    balanceCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customerId),
    CONSTRAINT fk_hotspotCreditAccount_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotOwnerAccount (
    ownerId BIGINT UNSIGNED NOT NULL,
    balanceCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    pendingCashMinor BIGINT NOT NULL DEFAULT 0,
    pendingCashCurrency CHAR(3) NULL,
    updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ownerId),
    CONSTRAINT fk_hotspotOwnerAccount_owner FOREIGN KEY (ownerId) REFERENCES hotspotOwner(ownerId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotSession (
    sessionId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NULL,
    entitlementId BIGINT UNSIGNED NULL,
    deviceId BIGINT UNSIGNED NOT NULL,
    providerGatewayId INT UNSIGNED NOT NULL,
    priceCreditsPerMiB DECIMAL(20,6) NOT NULL DEFAULT 1.000000,
    providerRewardBps SMALLINT UNSIGNED NOT NULL DEFAULT 7000,
    chargedCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    providerCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    startedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    endedAt DATETIME NULL,
    bytesUp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytesDown BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sessionId),
    KEY ix_hotspotSession_provider (providerGatewayId, startedAt),
    KEY ix_hotspotSession_customer (customerId, endedAt),
    KEY ix_hotspotSession_device (deviceId, endedAt),
    CONSTRAINT fk_hotspotSession_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId),
    CONSTRAINT fk_hotspotSession_entitlement FOREIGN KEY (entitlementId) REFERENCES hotspotEntitlement(entitlementId),
    CONSTRAINT fk_hotspotSession_device FOREIGN KEY (deviceId) REFERENCES hotspotDevice(deviceId),
    CONSTRAINT fk_hotspotSession_provider FOREIGN KEY (providerGatewayId) REFERENCES hotspotGateway(gatewayId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotCreditLedger (
    creditLedgerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    paymentId BIGINT UNSIGNED NULL,
    sessionId BIGINT UNSIGNED NULL,
    gatewayId INT UNSIGNED NULL,
    entryType ENUM('purchase','usage','refund','adjustment') NOT NULL,
    amountCredits DECIMAL(20,6) NOT NULL,
    balanceAfter DECIMAL(20,6) NOT NULL,
    note VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (creditLedgerId),
    KEY ix_hotspotCreditLedger_customer (customerId, created),
    KEY ix_hotspotCreditLedger_session (sessionId),
    CONSTRAINT fk_hotspotCreditLedger_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId),
    CONSTRAINT fk_hotspotCreditLedger_payment FOREIGN KEY (paymentId) REFERENCES hotspotPayment(paymentId),
    CONSTRAINT fk_hotspotCreditLedger_session FOREIGN KEY (sessionId) REFERENCES hotspotSession(sessionId),
    CONSTRAINT fk_hotspotCreditLedger_gateway FOREIGN KEY (gatewayId) REFERENCES hotspotGateway(gatewayId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotProviderLedger (
    providerLedgerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ownerId BIGINT UNSIGNED NOT NULL,
    gatewayId INT UNSIGNED NOT NULL,
    sessionId BIGINT UNSIGNED NOT NULL,
    usageMiB DECIMAL(20,6) NOT NULL,
    subscriberCreditsCharged DECIMAL(20,6) NOT NULL,
    providerCreditsEarned DECIMAL(20,6) NOT NULL,
    settlementCountry CHAR(2) NOT NULL,
    cashEligibleSnapshot BIT NOT NULL DEFAULT b'0',
    settlementState ENUM('credit','cash_pending','settled','void') NOT NULL DEFAULT 'credit',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (providerLedgerId),
    KEY ix_hotspotProviderLedger_owner (ownerId, settlementState, created),
    KEY ix_hotspotProviderLedger_session (sessionId),
    CONSTRAINT fk_hotspotProviderLedger_owner FOREIGN KEY (ownerId) REFERENCES hotspotOwner(ownerId),
    CONSTRAINT fk_hotspotProviderLedger_gateway FOREIGN KEY (gatewayId) REFERENCES hotspotGateway(gatewayId),
    CONSTRAINT fk_hotspotProviderLedger_session FOREIGN KEY (sessionId) REFERENCES hotspotSession(sessionId)
) ENGINE=InnoDB;

-- Earlier pilot ledger retained for compatibility/audit.
CREATE TABLE IF NOT EXISTS hotspotLedger (
    ledgerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    paymentId BIGINT UNSIGNED NOT NULL,
    sessionId BIGINT UNSIGNED NULL,
    beneficiaryType ENUM('service','seller','provider_pool','provider') NOT NULL,
    gatewayId INT UNSIGNED NULL,
    amountMinor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    settlementState ENUM('open','settled','void') NOT NULL DEFAULT 'open',
    note VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ledgerId),
    KEY ix_hotspotLedger_payment (paymentId),
    KEY ix_hotspotLedger_gateway (gatewayId, settlementState),
    CONSTRAINT fk_hotspotLedger_payment FOREIGN KEY (paymentId) REFERENCES hotspotPayment(paymentId),
    CONSTRAINT fk_hotspotLedger_session FOREIGN KEY (sessionId) REFERENCES hotspotSession(sessionId),
    CONSTRAINT fk_hotspotLedger_gateway FOREIGN KEY (gatewayId) REFERENCES hotspotGateway(gatewayId)
) ENGINE=InnoDB;

-- Repeatable migration helpers for databases created by the original pilot.
DROP PROCEDURE IF EXISTS tarasec_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE tarasec_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE s FROM @ddl;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END//
DELIMITER ;

CALL tarasec_add_column_if_missing('hotspotGateway','ownerId','BIGINT UNSIGNED NULL');
CALL tarasec_add_column_if_missing('hotspotGateway','countryCode','CHAR(2) NULL');
CALL tarasec_add_column_if_missing('hotspotGateway','priceCreditsPerMiB','DECIMAL(20,6) NOT NULL DEFAULT 1.000000');
CALL tarasec_add_column_if_missing('hotspotGateway','providerRewardBps','SMALLINT UNSIGNED NOT NULL DEFAULT 7000');
CALL tarasec_add_column_if_missing('hotspotGateway','priceLabel','VARCHAR(128) NULL');
CALL tarasec_add_column_if_missing('hotspotCustomer','ownerId','BIGINT UNSIGNED NULL');
CALL tarasec_add_column_if_missing('hotspotCustomer','active','BIT NOT NULL DEFAULT b''1''');
CALL tarasec_add_column_if_missing('hotspotPayment','creditsPurchased','DECIMAL(20,6) NULL');
CALL tarasec_add_column_if_missing('hotspotSession','customerId','BIGINT UNSIGNED NULL');
CALL tarasec_add_column_if_missing('hotspotSession','priceCreditsPerMiB','DECIMAL(20,6) NOT NULL DEFAULT 1.000000');
CALL tarasec_add_column_if_missing('hotspotSession','providerRewardBps','SMALLINT UNSIGNED NOT NULL DEFAULT 7000');
CALL tarasec_add_column_if_missing('hotspotSession','chargedCredits','DECIMAL(20,6) NOT NULL DEFAULT 0.000000');
CALL tarasec_add_column_if_missing('hotspotSession','providerCredits','DECIMAL(20,6) NOT NULL DEFAULT 0.000000');

-- Backfill customerId for sessions made by the first pilot.
UPDATE hotspotSession s
JOIN hotspotDevice d ON d.deviceId=s.deviceId
SET s.customerId=d.customerId
WHERE s.customerId IS NULL;

DROP PROCEDURE IF EXISTS tarasec_add_column_if_missing;
