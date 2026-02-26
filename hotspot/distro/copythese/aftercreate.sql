use taransvar;
insert into radcheck (username, attribute, op, value, confirmedTime) values ('admin', 'Cleartext-Password',':=', LEFT(UUID(), 5),now());FLUSH PRIVILEGES;
insert into hotspotSetup (hashkey, loginmsg) values ( UUID(), "If you don't yet have a user name, then you can obtain one by contacting the owner of this WiFi router. You may be granted a number of MB or unlimited access for a given time." );
INSERT INTO radusergroup (username, groupname, priority) VALUES ('admin', 'thisgroup', '1');
INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES ('thisgroup', 'Service-Type', ':=', 'Framed-User'), 
('thisgroup', 'Framed-Protocol', ':=', 'PPP'),
('thisgroup', 'Framed-Compression', ':=', 'Van-Jacobsen-TCP-IP');
insert into fw_acceptTemplate(ruleTemplate) values ('SSH'),('Samba'),('HTTP');
update fw_acceptTemplate set incomingInside = b'1', incomingOutside = b'1', outwardsInside = b'1', outwardsOutside = b'1' where ruleTemplate = 'HTTP';


