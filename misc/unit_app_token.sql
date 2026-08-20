-- TaraSec unit-scoped App token table.
-- Phase 2 of end-user/unit-owner support. Apply on a gateway with:
--   sudo mysql taransvar < misc/unit_app_token.sql
-- This deliberately does not change setup.dbVersion yet; it can be folded into
-- the next normal schema migration once the pairing protocol is validated.

CREATE TABLE IF NOT EXISTS unitAppToken (
    unitAppTokenId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    unitId INT NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tokenHash CHAR(64) NOT NULL,
    label VARCHAR(100) NULL,
    active BIT(1) NOT NULL DEFAULT b'1',
    lastUsed TIMESTAMP NULL,
    expires TIMESTAMP NULL,
    PRIMARY KEY (unitAppTokenId),
    UNIQUE KEY uq_unit_app_token_hash (tokenHash),
    KEY idx_unit_app_token_unit (unitId),
    KEY idx_unit_app_token_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
