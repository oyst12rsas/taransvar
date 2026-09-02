-- TaraSec subscriber app authentication and local delivery cache schema.
-- Financial/loan authority lives in the private tarasec_payment database.


-- Central TaraSec identity. Provider identities are stable subjects; email is
-- an attribute and is never the sole provider-account key.
CREATE TABLE IF NOT EXISTS tarasecIdentity (
    identityId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    primaryEmail VARCHAR(255) NULL,
    emailVerifiedAt DATETIME NULL,
    displayName VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(identityId),
    KEY ix_tarasecIdentity_email(primaryEmail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tarasecIdentityProvider (
    identityProviderId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identityId BIGINT UNSIGNED NOT NULL,
    provider ENUM('google','facebook','email') NOT NULL,
    providerSubject VARCHAR(255) NOT NULL,
    emailAtProvider VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(identityProviderId),
    UNIQUE KEY uq_identity_provider_subject(provider,providerSubject),
    CONSTRAINT fk_identityProvider_identity FOREIGN KEY(identityId) REFERENCES tarasecIdentity(identityId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tarasecOAuthState (
    oauthStateId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stateHash CHAR(64) NOT NULL,
    provider ENUM('google','facebook') NOT NULL,
    appRedirect VARCHAR(255) NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt DATETIME NOT NULL,
    usedAt DATETIME NULL,
    PRIMARY KEY(oauthStateId),
    UNIQUE KEY uq_oauth_state_hash(stateHash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tarasecIdentityCode (
    identityCodeId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identityId BIGINT UNSIGNED NOT NULL,
    codeHash CHAR(64) NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt DATETIME NOT NULL,
    usedAt DATETIME NULL,
    PRIMARY KEY(identityCodeId),
    UNIQUE KEY uq_identity_code_hash(codeHash),
    CONSTRAINT fk_identityCode_identity FOREIGN KEY(identityId) REFERENCES tarasecIdentity(identityId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS tarasec_subscriber_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE tarasec_subscriber_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
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

CALL tarasec_subscriber_add_column_if_missing('hotspotCustomer','identityId','BIGINT UNSIGNED NULL');
CALL tarasec_subscriber_add_column_if_missing('hotspotCustomer','passwordHash','VARCHAR(255) NULL');
CALL tarasec_subscriber_add_column_if_missing('hotspotCustomer','emailVerifiedAt','DATETIME NULL');
CALL tarasec_subscriber_add_column_if_missing('hotspotCustomer','phoneVerifiedAt','DATETIME NULL');
CALL tarasec_subscriber_add_column_if_missing('hotspotCustomer','lastLogin','DATETIME NULL');
DROP PROCEDURE IF EXISTS tarasec_subscriber_add_column_if_missing;

CREATE TABLE IF NOT EXISTS hotspotSubscriberToken (
    subscriberTokenId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    tokenHash CHAR(64) NOT NULL,
    deviceLabel VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastUsed DATETIME NULL,
    expiresAt DATETIME NOT NULL,
    revokedAt DATETIME NULL,
    PRIMARY KEY (subscriberTokenId),
    UNIQUE KEY uq_hotspotSubscriberToken_hash (tokenHash),
    KEY ix_hotspotSubscriberToken_customer (customerId, revokedAt, expiresAt),
    CONSTRAINT fk_hotspotSubscriberToken_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;

-- This is not a financial ledger. It only proves that a central financial grant
-- has already been delivered into the TaraSec spendable-credit cache.
CREATE TABLE IF NOT EXISTS hotspotCreditGrantReceipt (
    grantId CHAR(36) NOT NULL,
    customerId BIGINT UNSIGNED NOT NULL,
    amountCredits DECIMAL(20,6) NOT NULL,
    appliedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (grantId),
    KEY ix_hotspotCreditGrantReceipt_customer (customerId, appliedAt),
    CONSTRAINT fk_hotspotCreditGrantReceipt_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;

SET @identity_unique_exists=(SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='hotspotCustomer' AND index_name='uq_hotspotCustomer_identity');
SET @identity_unique_sql=IF(@identity_unique_exists=0,'ALTER TABLE hotspotCustomer ADD UNIQUE KEY uq_hotspotCustomer_identity(identityId)','SELECT 1');
PREPARE identity_unique_stmt FROM @identity_unique_sql;
EXECUTE identity_unique_stmt;
DEALLOCATE PREPARE identity_unique_stmt;
