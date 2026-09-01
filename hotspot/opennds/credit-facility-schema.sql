-- TaraSec subscriber credit/loan facility.
-- Repeatable. A facility is explicitly approved per subscriber; no account may
-- borrow merely because its prepaid balance reached zero.

CREATE TABLE IF NOT EXISTS hotspotCreditFacility (
    customerId BIGINT UNSIGNED NOT NULL,
    creditLimitCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    debtCredits DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    status ENUM('disabled','active','suspended','closed') NOT NULL DEFAULT 'disabled',
    approvedBy VARCHAR(128) NULL,
    approvedAt DATETIME NULL,
    updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customerId),
    CONSTRAINT fk_hotspotCreditFacility_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hotspotCreditFacilityLedger (
    facilityLedgerId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customerId BIGINT UNSIGNED NOT NULL,
    entryType ENUM('draw','repayment','limit_change','adjustment') NOT NULL,
    amountCredits DECIMAL(20,6) NOT NULL,
    debtAfter DECIMAL(20,6) NOT NULL,
    creditLimitAfter DECIMAL(20,6) NOT NULL,
    note VARCHAR(255) NULL,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (facilityLedgerId),
    KEY ix_hotspotCreditFacilityLedger_customer (customerId, created),
    CONSTRAINT fk_hotspotCreditFacilityLedger_customer FOREIGN KEY (customerId) REFERENCES hotspotCustomer(customerId)
) ENGINE=InnoDB;
