-- TaraSec global hotspot registry (DB version 84 candidate)
-- Intended for the global DB server. The main install.sql migration can absorb this
-- once the branch is accepted.

CREATE TABLE IF NOT EXISTS hotspotRegistry (
    hotspotId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    publicId VARCHAR(40) NOT NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lastSeen TIMESTAMP NULL,
    publicKey TEXT NOT NULL,
    publicKeyHash CHAR(64) NOT NULL,
    apiTokenHash CHAR(64) NOT NULL,
    hostname VARCHAR(255) NULL,
    installerVersion VARCHAR(32) NULL,
    softwareVersion VARCHAR(64) NULL,
    seenIp VARCHAR(45) NULL,
    hotspotIf VARCHAR(64) NULL,
    wanIf VARCHAR(64) NULL,
    ssid VARCHAR(150) NULL,
    capabilities JSON NULL,
    ownerClaimed BIT(1) NOT NULL DEFAULT b'0',

    -- Owner-provided geographic information. All fields are optional.
    countryCode CHAR(2) NULL,
    region VARCHAR(150) NULL,
    city VARCHAR(150) NULL,
    postalCode VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    locationAccuracyMeters INT UNSIGNED NULL,
    locationSource ENUM('none','owner','device','app','import') NOT NULL DEFAULT 'none',

    -- How accurately the owner has supplied location, and how accurately TaraSec
    -- may show the hotspot on a public map. publicLocationPrecision may be less
    -- precise than locationPrecision but never needs to be more precise.
    locationPrecision ENUM('none','country','region','city','postcode','approximate','exact') NOT NULL DEFAULT 'none',
    publicLocationPrecision ENUM('none','country','region','city','postcode','approximate','exact') NOT NULL DEFAULT 'none',
    locationUpdated TIMESTAMP NULL,

    PRIMARY KEY (hotspotId),
    UNIQUE KEY uq_hotspot_public_id (publicId),
    UNIQUE KEY uq_hotspot_public_key_hash (publicKeyHash),
    KEY idx_hotspot_last_seen (lastSeen),
    KEY idx_hotspot_country_city (countryCode, city),
    KEY idx_hotspot_map (publicLocationPrecision, latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
