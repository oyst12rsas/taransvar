
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

#version 50 (260311)
alter table setup add dontDmesgIPs varchar(150);
update setup set dbVersion = 50;

#version 51 (260311)
alter table setup add nickname varchar(100);
update setup set dbVersion = 51;

#version 52 (260324)
alter table pendingWget add reply varchar(255);
alter table partner add infoAdmin varchar(255);
alter table partner add infoTech varchar(255);
alter table partner add infoSharePartner varchar(255);
alter table partner add infoSharePartners varchar(255);
alter table unit add infoSharePartner varchar(255);
alter table unit add infoSharePartners varchar(255);
alter table unit add foreignUnitId int(11) null after ownerId;
update setup set dbVersion = 52;

#version 53 (260324)
alter table unit drop column infoSharePartner;
alter table unit drop column infoSharePartners;
alter table unit drop column foreignUnitId;
alter table internalInfections add infoSharePartner varchar(255);
alter table internalInfections add infoSharePartners varchar(255);
alter table internalInfections add foreignUnitId int(11) null after unitId;
update setup set dbVersion = 53;

#version 54 (260326)
alter table internalInfections add severity int not null default 0;
alter table internalInfections add botnetId int null;
update setup set dbVersion = 54;

#version 55 (260331)
alter table hackReport add infoSharePartners varchar(255);
alter table hackReport add severity int not null default 0;
alter table hackReport add botnetId int null;
alter table hackReport add partnerId int null;
alter table hackReport add infectionId int null;
alter table hackReport add remoteUnitId int null;
alter table hackReport modify status varchar(255);
update setup set dbVersion = 55;

#version 56 (260409)
create table syslog (
	syslogId int unsigned not null auto_increment, 
	senderIp int unsigned not null, 
    senderPort smallint unsigned not null,
	created timestamp not null default current_timestamp,
    pri integer null,
    facility integer null,
    severity integer null,
	hostname varchar(256),
    tag varchar(128),
    message text,
    rawmessage text,
	isSyslog tinyint null,
	primary key(syslogId)
);
create table syslogThreat(
	syslogThreatId int unsigned not null auto_increment, 
	syslogId int unsigned,
	created timestamp not null default current_timestamp,
	owner_id int unsigned null,
	unit_id int unsigned null,
	confirmed_unit_id int unsigned null,
    is_attack smallint unsigned null,
    action char(32),
	src_ip int unsigned not null, 
    src_port smallint unsigned not null,
	dst_ip int unsigned not null, 
    dst_port int unsigned not null,
    protocol varchar(16),
    device varchar(128),
	botnetId int unsigned null,
	severity int unsigned null, 
	handled bit(1),
	primary key(syslogThreatId)
);
update setup set dbVersion = 56;

#version 57 (260413)
alter table syslogThreat add service enum ('iptables','cisco','fortinet','palo_alto','cowrie','ssh','ddos','firewall','honeypot','web-service','login_attempt','db','other');
alter table syslogThreat add description varchar(255);
alter table syslogThreat add count integer not null default 1;
update setup set dbVersion = 57;

#version 58 (260414)
CREATE TABLE dhcpEvent (
    dhcpEventId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seenAt DATETIME(6) NOT NULL,
    interfaceName VARCHAR(64) NOT NULL,
    srcIp INT UNSIGNED NULL,
    dstIp INT UNSIGNED NULL,
    clientMac VARCHAR(17) NOT NULL,
    yourIp INT UNSIGNED NULL,
    hostname VARCHAR(255) NULL,
    vendorClass VARCHAR(255) NULL,
    dhcpMessageType TINYINT UNSIGNED NULL,  
    rawLine TEXT NULL,
	handled bit(1) not null default b'0',
    PRIMARY KEY (dhcpEventId),
    KEY idx_seenAt (seenAt),
    KEY idx_clientMac (clientMac),
    KEY idx_yourIp (yourIp),
    KEY idx_srcIp (srcIp)
);

CREATE TABLE dhcpClientState (
    dhcpClientStateId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clientMac VARCHAR(17) NOT NULL,
    currentIp INT UNSIGNED NULL,
    hostname VARCHAR(255) NULL,
    vendorClass VARCHAR(255) NULL,
    firstSeen DATETIME(6) NULL,
    lastSeen DATETIME(6) NULL,
    eventCount INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (dhcpClientStateId),
    UNIQUE KEY uq_clientMac (clientMac),
    KEY idx_currentIp (currentIp),
    KEY idx_lastSeen (lastSeen)
);

update setup set dbVersion = 58;

#version 59 (260415)
alter table dhcpEvent add unitId int unsigned null;
update setup set dbVersion = 59;

#version 60 (260509)
alter table user add isAdmin bit(1) not null default b'0' after verified; 
update setup set dbVersion = 60;

#version 61 (260512)
alter table traffic add lastSeen timestamp null;
update setup set dbVersion = 61;

#version 62 (260515)
alter table partnerRouter add statusOk bit(1) not null default b'0' after handled;
alter table partnerRouter add status varchar(255) not null default "" after statusOk;
ALTER TABLE traffic ADD COLUMN IF NOT EXISTS tag int unsigned null;
update setup set dbVersion = 62;

#version 63 (260518)
alter table setup add requireRegistration bit(1) not null default b'0' after hotspot;
alter table setup add selfRegistration bit(1) not null default b'1' after requireRegistration;
update setup set dbVersion = 63;

#version 64 (260519)
alter table syslog add lastSeen timestamp null;
alter table syslogThreat add lastSeen timestamp null;
update setup set dbVersion = 64;

#version 65 (260521)
alter table partnerRouter add showToAdminsOnly bit(1) not null default b'0' after statusOk;
alter table user add lastLogin timestamp null;
alter table user add loginFailsSinceSuccess int unsigned not null default 0;
alter table user add lastLoginIp int unsigned null;
alter table user add loginFailReportedTime timestamp null;
update setup set dbVersion = 65;

#version 66 (260522)
alter table hackReport add count int unsigned not null default 0;
alter table hackReport add why varchar(255);
alter table syslog add count int unsigned not null default 0;
update setup set dbVersion = 66;

#version 67 (260610)
alter table syslogThreat add handling varchar(255);
alter table setup add systemMessage varchar(255);
update setup set dbVersion = 67;

#version 68 (260610)
alter table internalInfections add why varchar(255);
update setup set dbVersion = 68;

#version 69 (260610)
create table dmesg (
    dmesgId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created timestamp not null default current_timestamp,
    txt varchar(255),
    primary key(dmesgId)
);
update setup set dbVersion = 69;

#version 70 (260629)
alter table setup add doingNAT bit(1) not NULL default b'0' after statusIntervalSec;
update setup set dbVersion = 70;

#version 71 (260703)
alter table syslog modify count int unsigned not null default 1;
alter table setup add isDbServer bit(1) not NULL default b'0' after statusIntervalSec;
update setup set dbVersion = 71;

#version 72 (260708)
alter table setup add blockSshThreshold tinyint not null default 0 after blockIncomingTaggedTrafficThreshold;
update setup set dbVersion = 72;

#version 73 (260721)
alter table setup modify networkStatus text;
alter table partnerRouter modify status text;
create table logTotals (
	ip int unsigned not null,
	last_seen timestamp null,
	iptables_last_hour int unsigned null,
	iptables_last_day int unsigned null,
	iptables_last_week int unsigned null,
	iptables_last_month int unsigned null,
	ssh_last_hour int unsigned null,
	ssh_last_day int unsigned null,
	ssh_last_week int unsigned null,
	ssh_last_month int unsigned null,
	untagged_rejects_last_hour int unsigned null,
	untagged_rejects_last_day int unsigned null,
	untagged_rejects_last_week int unsigned null,
	untagged_rejects_last_month int unsigned null,
	primary key(ip)
);
create table partnerRouterStatusLog (
	ip int unsigned not null,
	created timestamp not null default current_timestamp,
	status text null,
	primary key(ip, created)
);
update setup set dbVersion = 73;

#version 74 (260721)
alter table setup add systemErrorSet timestamp null;
alter table setup add systemErrorSeverity tinyint unsigned;
alter table setup add systemError varchar(255);
update setup set dbVersion = 74;

#******** NEXT TIME ALSO add *****
#update setup set dbVersion = 75;

#NOTE! The versions (#version nn ...) are here so that misc/system_diag.pl 
#can import DB changes automatically based on the content of this file...
#So just go to programming/misc and: sudo perl system_diag.pl

