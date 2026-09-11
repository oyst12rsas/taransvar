-- Optional NetBird endpoint for routing tagged TaraSec traffic to a partner router.
-- Disabled by default; ordinary Internet routing is unchanged until explicitly enabled.

ALTER TABLE partnerRouter
    ADD COLUMN netbirdIp INT UNSIGNED NULL AFTER nettmask,
    ADD COLUMN routeTaggedViaNetbird BIT(1) NOT NULL DEFAULT b'0' AFTER netbirdIp;

CREATE INDEX idx_partnerRouter_netbird_route
    ON partnerRouter (routeTaggedViaNetbird, netbirdIp);
