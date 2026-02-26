
#version 42 (250226)
alter table setup add hotspot bit(1) not null default b'1' after doOther;
alter table setup add isGlobalDbServer bit(1) not null default b'0' after hotspot;
update setup set dbVersion = 42;

#version 43 (250305)
alter table setup add ssid varchar(150) not null default "" after hotspot;
create table loginAttempt (
	loginAttemptId int unsigned not null auto_increment,
	theTime timestamp not null default current_timestamp,
	ip int unsigned not null,
	username varchar(100),
	password varchar(100),
	primary key(loginAttemptId)
);
alter table user add suspendedUntil timestamp null;
alter table user add suspendedMinutes timestamp null;
update setup set dbVersion = 43;

#version 44 (250305)
alter table setup add suspendHotspotLoginUntil timestamp;
alter table setup add suspendGKLoginUntil timestamp;
alter table setup add requestReboot bit(1) not null default b'0' after doOther;
update setup set dbVersion = 44;

#version 45 (250305)
alter table setup add requestShutdown bit(1) not null default b'0' after requestReboot;
update setup set dbVersion = 45;

#version 46 (250317)
alter table access add updated timestamp not null default current_timestamp;
update setup set dbVersion = 46;

#version 47 (250318)
alter table radcheck add name varchar(100) not null default '' after username;
alter table radcheck modify value varchar(255);
alter table radcheck add createdTime timestamp not null default current_timestamp after phone;
alter table radcheck add last_login timestamp null after createdTime;
update setup set dbVersion = 47;

#version 48 (250318)
alter table session add id int(11) null after sessionid;
update setup set dbVersion = 48;

#version 49 (250318)
alter table session modify username varchar(150);
update setup set dbVersion = 49;



#NOTE! The versions (#version nn ...) are here so that misc/system_diag.pl 
#can import DB changes automatically based on the content of this file...
#So just go to programming/misc and: sudo perl system_diag.pl

