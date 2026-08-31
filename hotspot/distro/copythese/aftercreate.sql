use taransvar;

-- Back-office administrators belong in `user`, never in FreeRADIUS/radcheck.
-- MySQL does not support MariaDB's ADD COLUMN IF NOT EXISTS syntax, so use
-- information_schema and dynamic SQL to keep this migration repeatable on both.
SET @add_is_admin = IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user' AND COLUMN_NAME='isAdmin'
  ),
  'SELECT 1',
  'ALTER TABLE `user` ADD COLUMN `isAdmin` bit(1) NOT NULL DEFAULT b''0'''
);
PREPARE add_is_admin_stmt FROM @add_is_admin;
EXECUTE add_is_admin_stmt;
DEALLOCATE PREPARE add_is_admin_stmt;

INSERT INTO `user` (username, password, isAdmin, verified)
SELECT 'admin', SUBSTRING(REPLACE(UUID(),'-',''),1,16), b'1', b'1'
WHERE NOT EXISTS (SELECT 1 FROM `user` WHERE username='admin');

-- Remove obsolete dual-use legacy records if an older install created them.
DELETE FROM radcheck WHERE username='admin';
DELETE FROM radusergroup WHERE username='admin';

insert into hotspotSetup (hashkey, loginmsg) values ( UUID(), "If you don't yet have a user name, then you can obtain one by contacting the owner of this WiFi router. You may be granted a number of MB or unlimited access for a given time." );
INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES ('thisgroup', 'Service-Type', ':=', 'Framed-User'), 
('thisgroup', 'Framed-Protocol', ':=', 'PPP'),
('thisgroup', 'Framed-Compression', ':=', 'Van-Jacobsen-TCP-IP');
insert into fw_acceptTemplate(ruleTemplate) values ('SSH'),('Samba'),('HTTP');
update fw_acceptTemplate set incomingInside = b'1', incomingOutside = b'1', outwardsInside = b'1', outwardsOutside = b'1' where ruleTemplate = 'HTTP';
