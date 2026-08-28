use taransvar;

-- Back-office administrators belong in `user`, never in FreeRADIUS/radcheck.
-- Keep this safe for older schemas by ensuring the discriminator exists first.
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `isAdmin` bit(1) NOT NULL DEFAULT b'0';

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
