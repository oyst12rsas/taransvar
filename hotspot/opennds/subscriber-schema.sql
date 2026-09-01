-- TaraSec subscriber app authentication schema.
-- Repeatable on MySQL/MariaDB. Subscriber balances and usage remain in the
-- roaming schema; this file only adds credentials/session tokens for clients.

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
