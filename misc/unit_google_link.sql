-- Optional unit-owner linking schema; apply with unit_app_token.sql.
CREATE TABLE IF NOT EXISTS unitGoogleLink (
    linkId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    unitId INT NOT NULL,
    subjectHash CHAR(64) NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active BIT(1) NOT NULL DEFAULT b'1',
    UNIQUE KEY uq_unit_google_link(subjectHash,unitId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS unitGoogleGrant (
    linkId BIGINT UNSIGNED NOT NULL,
    clientHash CHAR(64) NOT NULL,
    unitAppTokenId BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(linkId,clientHash),
    UNIQUE KEY uq_unit_google_grant_token(unitAppTokenId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
