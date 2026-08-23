-- Run on the TaraSec global/back-office enrollment database, not on every gateway.
CREATE TABLE IF NOT EXISTS managedInstallation (
    managedInstallationId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NULL,
    ownerEmail VARCHAR(255) NULL,
    hostname VARCHAR(255) NULL,
    country CHAR(2) NULL,
    machineId VARCHAR(128) NULL,
    netbirdSetupKeyId VARCHAR(128) NULL,
    wazuhAgentId VARCHAR(32) NULL,
    wazuhAgentName VARCHAR(128) NULL,
    paymentAvailable BIT(1) NOT NULL DEFAULT b'1',
    paymentConfiguredTime TIMESTAMP NULL,
    disabledTime TIMESTAMP NULL,
    PRIMARY KEY (managedInstallationId),
    KEY idx_managed_owner(ownerEmail),
    KEY idx_managed_machine(machineId),
    KEY idx_managed_wazuh(wazuhAgentId)
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
