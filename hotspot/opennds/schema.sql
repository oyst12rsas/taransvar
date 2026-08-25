-- TaraSec hotspot roaming/payment test schema
-- Intentionally separate from misc/install.sql until the Cigar pilot proves the model.

CREATE TABLE IF NOT EXISTS hotspotGateway (
    gatewayId INT UNSIGNED NOT NULL AUTO_INCREMENT,
    gatewayKey VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    apiTokenHash CHAR(64) NOT NULL,
    ownerPhone VARCHAR(64) NULL,
    ownerEmail VARCHAR(255) NULL,
    ownerAddress VARCHAR(512) NULL,
    active BIT NOT NULL DEFAULT b'1',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (gatewayId),
    UNIQUE KEY uq_hotspotGateway_key (gatewayKey)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotCustomer (
    customerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customerId),
    KEY ix_hotspotCustomer_phone (phone),
    KEY ix_hotspotCustomer_email (email)
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
SELECT 'pilot-10-15-75', 1000, 1500, 7500, b'1'
WHERE NOT EXISTS (SELECT 1 FROM hotspotPolicy WHERE versionName='pilot-10-15-75');

CREATE TABLE IF NOT EXISTS hotspotPayment (
    paymentId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    sellerGatewayId INT UNSIGNED NOT NULL,
    policyId INT UNSIGNED NOT NULL,
    amountMinor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    externalRef VARCHAR(128) NULL,
    status ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'paid',
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (paymentId),
    KEY ix_hotspotPayment_customer (customerId),
    CONSTRAINT fk_hotspotPayment_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId),
    CONSTRAINT fk_hotspotPayment_seller FOREIGN KEY (sellerGatewayId) REFERENCES hotspotGateway(gatewayId),
    CONSTRAINT fk_hotspotPayment_policy FOREIGN KEY (policyId) REFERENCES hotspotPolicy(policyId)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS hotspotSession (
    sessionId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entitlementId BIGINT UNSIGNED NOT NULL,
    deviceId BIGINT UNSIGNED NOT NULL,
    providerGatewayId INT UNSIGNED NOT NULL,
    startedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    endedAt DATETIME NULL,
    bytesUp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytesDown BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sessionId),
    KEY ix_hotspotSession_provider (providerGatewayId, startedAt),
    KEY ix_hotspotSession_device (deviceId, endedAt),
    CONSTRAINT fk_hotspotSession_entitlement FOREIGN KEY (entitlementId) REFERENCES hotspotEntitlement(entitlementId),
    CONSTRAINT fk_hotspotSession_device FOREIGN KEY (deviceId) REFERENCES hotspotDevice(deviceId),
    CONSTRAINT fk_hotspotSession_provider FOREIGN KEY (providerGatewayId) REFERENCES hotspotGateway(gatewayId)
) ENGINE=InnoDB;

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
