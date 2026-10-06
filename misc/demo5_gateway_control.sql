ALTER TABLE demo5Session ADD COLUMN IF NOT EXISTS pauseState varchar(24) NOT NULL DEFAULT 'pending';
ALTER TABLE demo5Session ADD COLUMN IF NOT EXISTS pauseMessage varchar(255) NULL;
ALTER TABLE demo5Session ADD COLUMN IF NOT EXISTS pauseCheckedAt timestamp NULL;
CREATE TABLE IF NOT EXISTS demo5ObservationTarget (
 routerId int NOT NULL,receiverIp int unsigned NOT NULL,
 receiverPort smallint unsigned NOT NULL DEFAULT 4205,
 PRIMARY KEY(routerId,receiverIp)
);
