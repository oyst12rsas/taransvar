create view vListings as
select di.ip, color, handled from domainIp di join domain d on d.domainId = di.domainId
union
select ip, color, handled from colorListings;

insert into setup (adminIP, internalIP, nettmask, globalDb1ip, dbVersion) values (inet_aton('10.10.10.10'),inet_aton('192.168.50.1'),inet_aton('255.255.255.0'), inet_aton('81.88.19.252'),49);

