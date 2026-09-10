-- TaraSec traffic outcome migration
--
-- Tarakernel reports only the forwarding decision it actually made.
-- Demo/test classification remains a dbserver responsibility and is derived
-- by correlating the tuple/time with a centrally registered demo.

ALTER TABLE traffic
    ADD COLUMN rejected TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER tag;

CREATE INDEX idx_traffic_rejected_recent
    ON traffic (ipFrom, portFrom, ipTo, portTo, rejected, lastSeen);
