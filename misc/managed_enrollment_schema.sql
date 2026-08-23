-- Run on the TaraSec back-office enrollment database.
CREATE TABLE IF NOT EXISTS managedInstallation (
    managedInstallationId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installationUuid CHAR(36) NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NULL,
    ownerName VARCHAR(255) NOT NULL,
    ownerEmail VARCHAR(255) NOT NULL,
    ownerPhone VARCHAR(64) NOT NULL,
    hostname VARCHAR(255) NULL,
    country CHAR(2) NULL,
    machineId VARCHAR(128) NULL,
    netbirdSetupKeyId VARCHAR(128) NULL,
    wazuhAgentId VARCHAR(32) NULL,
    wazuhAgentName VARCHAR(128) NULL,
    paymentAvailable BIT(1) NOT NULL DEFAULT b'1',
    paymentConfiguredTime TIMESTAMP NULL,
    globalForwardedTime TIMESTAMP NULL,
    globalForwardAttempts INT UNSIGNED NOT NULL DEFAULT 0,
    globalForwardError VARCHAR(500) NULL,
    disabledTime TIMESTAMP NULL,
    PRIMARY KEY (managedInstallationId),
    UNIQUE KEY uq_managed_installation_uuid(installationUuid),
    KEY idx_managed_owner_email(ownerEmail),
    KEY idx_managed_owner_phone(ownerPhone),
    KEY idx_managed_machine(machineId),
    KEY idx_managed_wazuh(wazuhAgentId),
    KEY idx_managed_forward(globalForwardedTime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS managedEnrollmentToken (
    managedEnrollmentTokenId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tokenHash CHAR(64) NOT NULL,
    ownerEmail VARCHAR(255) NOT NULL,
    expires TIMESTAMP NOT NULL,
    usedTime TIMESTAMP NULL,
    managedInstallationId BIGINT UNSIGNED NULL,
    PRIMARY KEY (managedEnrollmentTokenId),
    UNIQUE KEY uq_managed_token(tokenHash),
    KEY idx_managed_token_owner(ownerEmail),
    KEY idx_managed_token_expires(expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Run this table definition on the global DB server as well. The global API
-- upserts on installationUuid, making TaraSec/back-office retries idempotent.
CREATE TABLE IF NOT EXISTS managedOwnerRegistration (
    managedOwnerRegistrationId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    installationUuid CHAR(36) NOT NULL,
    firstRegistered TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastRegistered TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ownerName VARCHAR(255) NOT NULL,
    ownerEmail VARCHAR(255) NOT NULL,
    ownerPhone VARCHAR(64) NOT NULL,
    country CHAR(2) NULL,
    hostname VARCHAR(255) NULL,
    machineId VARCHAR(128) NULL,
    sourceInstallationId BIGINT UNSIGNED NULL,
    paymentAvailable BIT(1) NOT NULL DEFAULT b'0',
    followUpStatus ENUM('new','contacted','onboarding','active','not_interested','invalid') NOT NULL DEFAULT 'new',
    followUpTime TIMESTAMP NULL,
    followUpNote TEXT NULL,
    PRIMARY KEY (managedOwnerRegistrationId),
    UNIQUE KEY uq_global_installation_uuid(installationUuid),
    KEY idx_global_owner_email(ownerEmail),
    KEY idx_global_owner_phone(ownerPhone),
    KEY idx_global_country(country),
    KEY idx_global_followup(followUpStatus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
