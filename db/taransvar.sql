-- Fresh TaraSec schema, version 100. Seed data is in postcreate.sql.
CREATE TABLE `LanguageElement` (
  `ElementKey` varchar(100) DEFAULT NULL,
  `TxtENG` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `Setup` (
  `LastSqlErrorTime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `LastSqlErrorBy` varchar(10) DEFAULT NULL,
  `LastSqlErrorSql` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `SystemMessage` (
  `SystemMessageId` int(11) NOT NULL AUTO_INCREMENT,
  `PostedBy` varchar(10) DEFAULT NULL,
  `RegardingWhat` varchar(20) DEFAULT NULL,
  `RegardingId` int(11) DEFAULT NULL,
  `Warning` varchar(100) DEFAULT NULL,
  `URL` varchar(100) DEFAULT NULL,
  `IP` varchar(20) DEFAULT NULL,
  `BinaryIp` bit(30) DEFAULT NULL,
  `TechInfo` varchar(25) DEFAULT NULL,
  `Category` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`SystemMessageId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `access` (
  `ip` varchar(20) NOT NULL,
  `hasaccess` int(11) DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `assistanceRequest` (
  `requestId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` int(10) unsigned DEFAULT NULL,
  `port` int(10) unsigned DEFAULT NULL,
  `senderIp` int(10) unsigned DEFAULT NULL,
  `senderPort` int(10) unsigned DEFAULT NULL,
  category varchar(64) null,
  `regardingRequestId` int(10) unsigned DEFAULT NULL,
  `comment` varchar(200) DEFAULT NULL,
  `handlingComment` varchar(200) DEFAULT NULL,
  `senttime` timestamp NULL DEFAULT NULL,
  `requestQuality` smallint(5) unsigned DEFAULT NULL,
  `wantSpoofed` bit(1) NOT NULL DEFAULT b'1',
  `active` bit(1) NOT NULL DEFAULT b'1',
  `fromOther` bit(1) NOT NULL DEFAULT b'0',
  `handled` bit(1) DEFAULT NULL,
  `sentPartners` bit(1) NOT NULL DEFAULT b'0',
  `isDemo` bit(1) NOT NULL DEFAULT b'0',
  `purpose` enum('internalRequest','forDistribution','fromPartner') DEFAULT NULL,
  PRIMARY KEY (`requestId`),
  KEY `idx_assistance_outbound` (`sentPartners`,`requestId`),
  KEY `idx_assistance_changed` (`handled`,`ip`,`port`),
  KEY `idx_assistance_endpoint` (`ip`,`port`,`requestId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `attack` (
  `attackId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hackReportId` int(10) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`attackId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `colorListings` (
  `ip` int(10) unsigned NOT NULL,
  `color` enum('white','black') DEFAULT NULL,
  `active` bit(1) DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `demo` (
  `demoId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `userId` int(10) unsigned DEFAULT NULL,
  `ipTargetHost` int(10) unsigned NOT NULL,
  `ipBotHost` int(10) unsigned NOT NULL,
  `ipBot` int(10) unsigned DEFAULT NULL,
  `ipAdditionalBot1` int(10) unsigned DEFAULT NULL,
  `ipAdditionalBot2` int(10) unsigned DEFAULT NULL,
  `ipAdditionalBot3` int(10) unsigned DEFAULT NULL,
  `iAm` enum('targetHost','botHost','bot') DEFAULT NULL,
  `status` text DEFAULT NULL,
  `error` bit(1) NOT NULL DEFAULT b'0',
  `warning` bit(1) NOT NULL DEFAULT b'0',
  `activeDemo` bit(1) NOT NULL DEFAULT b'0',
  `statusInstalled` bit(1) NOT NULL DEFAULT b'0',
  `statusConnected` bit(1) NOT NULL DEFAULT b'0',
  `statusTaggingOk` bit(1) NOT NULL DEFAULT b'0',
  `statusTaggingReceivedOk` bit(1) NOT NULL DEFAULT b'0',
  `statusInfectedOk` bit(1) NOT NULL DEFAULT b'0',
  `statusRequestAssistanceOk` bit(1) NOT NULL DEFAULT b'0',
  `targetHostChecked` timestamp NULL DEFAULT NULL,
  `targetHostStatus` text DEFAULT NULL,
  `targetHostOk` bit(1) NOT NULL DEFAULT b'0',
  `botHostChecked` timestamp NULL DEFAULT NULL,
  `botHostStatus` text DEFAULT NULL,
  `botHostOk` bit(1) NOT NULL DEFAULT b'0',
  `botChecked` timestamp NULL DEFAULT NULL,
  `botStatus` text DEFAULT NULL,
  `botOk` bit(1) NOT NULL DEFAULT b'0',
  `handled` bit(1) DEFAULT NULL,
  `lastVisited` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`demoId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `dhcpClient` (
  `clientId` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dhcpClientId` binary(7) NOT NULL,
  `mac` binary(16) DEFAULT NULL,
  `vci` binary(20) DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`clientId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `dhcpDumpFile` (
  `dhcpDumpFileId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fileName` varchar(100) DEFAULT NULL,
  `imported` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`dhcpDumpFileId`)
) ENGINE=InnoDB AUTO_INCREMENT=252 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `dhcpDumpLog` (
  `dhcpDumpLogId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dhcpDumpFileId` int(10) unsigned NOT NULL,
  `logTime` timestamp NULL DEFAULT NULL,
  `unitId` int(10) unsigned DEFAULT NULL,
  `macAddress` varchar(255) DEFAULT NULL,
  `ipAddress` int(10) unsigned DEFAULT NULL,
  `dhcpClientId` binary(20) DEFAULT NULL,
  `mac` binary(16) DEFAULT NULL,
  `vci` varchar(100) DEFAULT NULL,
  `hostname` varchar(200) DEFAULT NULL,
  `comment` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`dhcpDumpLogId`)
) ENGINE=InnoDB AUTO_INCREMENT=557 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `dhcpSession` (
  `sessionId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clientId` bigint(20) unsigned NOT NULL,
  `discovered` timestamp NOT NULL,
  `ip` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`sessionId`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `domain` (
  `domainId` int(11) NOT NULL AUTO_INCREMENT,
  `domainName` varchar(100) DEFAULT NULL,
  `color` enum('white','black') DEFAULT NULL,
  `active` bit(1) DEFAULT NULL,
  PRIMARY KEY (`domainId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `domainIp` (
  `domainId` int(11) NOT NULL AUTO_INCREMENT,
  `ip` int(10) unsigned NOT NULL,
  `handled` bit(1) DEFAULT NULL,
  PRIMARY KEY (`domainId`,`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `fw_accept` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `regTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `regBy` varchar(20) DEFAULT NULL,
  `chain` enum('INPUT','OUTPUT','FORWARD') DEFAULT NULL,
  `fromIP` char(15) DEFAULT NULL,
  `toIP` char(15) DEFAULT NULL,
  `fromPort` smallint(5) unsigned DEFAULT NULL,
  `toPort` smallint(5) unsigned DEFAULT NULL,
  `prot` enum('tcp','utc') DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `fw_acceptTemplate` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `regTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `regBy` varchar(20) DEFAULT NULL,
  `active` bit(1) NOT NULL DEFAULT b'1',
  `ruleTemplate` enum('SSH','Samba','HTTP') DEFAULT NULL,
  `outwardsInside` bit(1) NOT NULL DEFAULT b'0',
  `incomingInside` bit(1) NOT NULL DEFAULT b'0',
  `outwardsOutside` bit(1) NOT NULL DEFAULT b'0',
  `incomingOutside` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `fw_rejected` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `createTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `logTime` datetime DEFAULT NULL,
  `fromIP` char(15) DEFAULT NULL,
  `toIP` char(15) DEFAULT NULL,
  `fromPort` smallint(5) unsigned DEFAULT NULL,
  `toPort` smallint(5) unsigned DEFAULT NULL,
  `protocol` enum('tcp','utc') DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `groupcampaign` (
  `campaignid` smallint(6) NOT NULL AUTO_INCREMENT,
  `groupname` varchar(20) NOT NULL,
  `campaindescription` text DEFAULT NULL,
  `purpose` enum('groupmembers','generatetempusers') NOT NULL,
  `usernameprefix` varchar(10) NOT NULL,
  `randomchars` tinyint(4) DEFAULT NULL,
  `successive` bit(1) NOT NULL DEFAULT b'0',
  `numbersonly` bit(1) NOT NULL DEFAULT b'0',
  `printStartOffset` smallint(5) unsigned DEFAULT NULL,
  `giveHoursAfterLogin` smallint(5) unsigned DEFAULT NULL,
  `giveMB` int(10) unsigned DEFAULT NULL,
  `createtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `count` smallint(5) unsigned DEFAULT NULL,
  `price` float DEFAULT NULL,
  `priceinfo` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`campaignid`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `hackReport` (
  `reportId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` int(10) unsigned DEFAULT NULL,
  `port` smallint(5) unsigned DEFAULT NULL,
  `partnerIp` int(10) unsigned DEFAULT NULL,
  `partnerPort` smallint(5) unsigned DEFAULT NULL,
  `sentByIp` int(10) unsigned DEFAULT NULL,
  status varchar(255),
  `handledTime` timestamp NULL DEFAULT NULL,
  `unitId` int(10) unsigned DEFAULT NULL,
  `lastSeen` timestamp NOT NULL DEFAULT current_timestamp(),
  `taransvarId` int(10) unsigned DEFAULT NULL,
  `sentGlobalDB` timestamp NULL DEFAULT NULL,
  `ipOwnerId` varchar(100) DEFAULT NULL,
  `sendAttemptCount` int(11) NOT NULL DEFAULT 0,
  infoSharePartners varchar(255),
  severity int not null default 0,
  botnetId int null,
  partnerId int null,
  infectionId int null,
  remoteUnitId int null,
  ownerConfirmedTime timestamp null,
  count int unsigned not null default 0,
  why varchar(255),
  hrCategory enum ('login_fail','tagged_traffic','from_dbserver', 'from_partner', 'ssh_fail', 'ssh_when_blocked', 'iptables', 'attack_severity_1', 'attack_severity_3', 'attack_severity_7', 'unverified_threat_info', 'other','demo'),
  PRIMARY KEY (`reportId`),
  KEY `idx_hackreport_pending` (`handledTime`,`created`,`reportId`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `honeyport` (
  `port` smallint(5) unsigned NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `handling` enum('block','normal','ssh','mysql','SQL-server','samba','other') DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  PRIMARY KEY (`port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `hotspotSetup` (
  `WAN` varchar(20) DEFAULT NULL,
  `LAN` varchar(20) DEFAULT NULL,
  `install_started` timestamp NULL DEFAULT current_timestamp(),
  `install_ended` timestamp NULL DEFAULT NULL,
  `hashkey` char(41) DEFAULT NULL,
  `seller` char(100) DEFAULT NULL,
  `licencedto` char(100) DEFAULT NULL,
  `loginmsg` text DEFAULT NULL,
  `logRejected` bit(1) DEFAULT b'0',
  `ananlyzeAll` bit(1) NOT NULL DEFAULT b'0',
  `allowUsersToLoginElsewhere` bit(1) NOT NULL DEFAULT b'0',
  `allowAlienUsers` bit(1) NOT NULL DEFAULT b'0',
  `requiresAccessUpdate` bit(1) NOT NULL DEFAULT b'0',
  `lastAccessUpdate` timestamp NOT NULL DEFAULT current_timestamp(),
  `lastAccessUpdatePoll` timestamp NULL DEFAULT NULL,
  `selfreg` enum('none','sms','email','semiEmail') DEFAULT NULL,
  `defaultSubscriptionType` enum('quota','expiry','limited') NOT NULL DEFAULT 'quota',
  `printPadding` smallint(5) unsigned DEFAULT NULL,
  `printFontSize` smallint(5) unsigned DEFAULT NULL,
  `printNumbersAcross` smallint(5) unsigned DEFAULT NULL,
  `printNumbers` smallint(5) unsigned DEFAULT NULL,
  `defaultpurpose` enum('groupmembers','generatetempusers') DEFAULT NULL,
  `installationCode` varchar(10) DEFAULT NULL,
  `installationPopularName` varchar(30) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `SSID` varchar(20) DEFAULT NULL,
  `installCompletedTime` timestamp NULL DEFAULT NULL,
  `maintenanceRequest` varchar(200) DEFAULT NULL,
  `internalIP` char(15) DEFAULT '192.168.0.1',
  `dummyToBeDeleted` varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `inspection` (
  `ip` int(10) unsigned NOT NULL,
  `nettmask` int(10) unsigned NOT NULL,
  `handling` enum('Drop','Inspect') NOT NULL,
  `active` bit(1) NOT NULL DEFAULT b'1',
  `handled` bit(1) DEFAULT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `internalInfections` (
  `infectionId` int(11) NOT NULL AUTO_INCREMENT,
  `ip` int(10) unsigned NOT NULL,
  `nettmask` int(10) unsigned NOT NULL DEFAULT 4294967295,
  `status` enum('unknown','bot','ccs','firsttime','sporadic','hack','dos','hotspot') DEFAULT NULL,
  `inserted` timestamp NOT NULL DEFAULT current_timestamp(),
  `handled` bit(1) DEFAULT NULL,
  `unitId` int(11) DEFAULT NULL,
  foreignUnitId int(11) null,
  `active` bit(1) NOT NULL DEFAULT b'1',
  `lastSeen` timestamp NOT NULL DEFAULT current_timestamp(),
  `taransvarId` int(10) unsigned DEFAULT NULL,
  infoSharePartner varchar(255),
  infoSharePartners varchar(255),
  severity int not null default 0,
  botnetId int null,
  why varchar(255),
  PRIMARY KEY (`infectionId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `internalServers` (
  `ip` int(10) unsigned NOT NULL,
  `port` smallint(5) unsigned NOT NULL,
  `publicPort` int(10) unsigned NOT NULL,
  `protection` enum('clean','presumed_clean','no_bots','all') DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  PRIMARY KEY (`ip`,`port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `loadavg` (
  `logtime` timestamp NOT NULL DEFAULT current_timestamp(),
  `serverload` varchar(30) DEFAULT NULL,
  `min1` float DEFAULT NULL,
  `min5` float DEFAULT NULL,
  `min10` float DEFAULT NULL,
  `processes` varchar(10) DEFAULT NULL,
  `lastprocess` int(11) DEFAULT NULL,
  PRIMARY KEY (`logtime`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `logEntry` (
  `logEntryId` int(11) NOT NULL AUTO_INCREMENT,
  `fromIP` varchar(100) NOT NULL,
  `toIP` varchar(100) NOT NULL,
  `protocol` varchar(15) DEFAULT NULL,
  `action` varchar(10) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`logEntryId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `log_arin` (
  `from_ip_bin` int(10) unsigned NOT NULL,
  `to_ip_bin` int(10) unsigned NOT NULL,
  `from_ip` varchar(15) DEFAULT NULL,
  `to_ip` varchar(15) DEFAULT NULL,
  `CIDR` varchar(30) DEFAULT NULL,
  `NetName` varchar(30) DEFAULT NULL,
  `NetHandle` varchar(30) DEFAULT NULL,
  `Parent` varchar(255) DEFAULT NULL,
  `NetType` varchar(30) DEFAULT NULL,
  `OriginAS` varchar(30) DEFAULT NULL,
  `Organization` varchar(100) DEFAULT NULL,
  `RegDate` date DEFAULT NULL,
  `Updated` date DEFAULT NULL,
  `Comment` text DEFAULT NULL,
  `OrgName` varchar(100) DEFAULT NULL,
  `OrgId` varchar(20) DEFAULT NULL,
  `Address` varchar(255) DEFAULT NULL,
  `City` varchar(40) DEFAULT NULL,
  `StateProv` varchar(40) DEFAULT NULL,
  `PostalCode` varchar(40) DEFAULT NULL,
  `Country` varchar(40) DEFAULT NULL,
  `OrgAbuseHandle` varchar(30) DEFAULT NULL,
  `OrgAbuseName` varchar(30) DEFAULT NULL,
  `OrgAbusePhone` varchar(30) DEFAULT NULL,
  `OrgAbuseEmail` varchar(100) DEFAULT NULL,
  `OrgAbuseRef` varchar(200) DEFAULT NULL,
  `OrgTechHandle` varchar(40) DEFAULT NULL,
  `OrgTechName` varchar(40) DEFAULT NULL,
  `OrgTechEmail` varchar(100) DEFAULT NULL,
  `OrgTechRef` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`from_ip_bin`,`to_ip_bin`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `log_contact` (
  `contact_id` bigint(21) NOT NULL AUTO_INCREMENT,
  `internal_ip` tinyint(3) unsigned NOT NULL,
  `external_ip` bigint(21) NOT NULL,
  `reg_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `username` varchar(15) DEFAULT NULL,
  `mb_in` int(10) unsigned DEFAULT NULL,
  `mb_out` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`contact_id`),
  KEY `log_contact_reg_time` (`reg_time`)
) ENGINE=InnoDB AUTO_INCREMENT=29995 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `log_resolv` (
  `ip_id` bigint(21) NOT NULL AUTO_INCREMENT,
  `ip` char(15) DEFAULT NULL,
  `domain` varchar(100) DEFAULT NULL,
  `fetched` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('unresolved','unknown','clean','dirty','suspicious','NXDOMAIN','SERVFAIL','timeout','NA') NOT NULL DEFAULT 'unresolved',
  `arin_from_ip` varchar(15) DEFAULT NULL,
  `arin_from_ip_bin` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`ip_id`),
  UNIQUE KEY `log_resolv_ip_ndx` (`ip`),
  KEY `log_resolv_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2895 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `loginAttempt` (
  `loginAttemptId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `theTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` int(10) unsigned NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`loginAttemptId`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `nas` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `nasname` varchar(128) NOT NULL,
  `shortname` varchar(32) DEFAULT NULL,
  `type` varchar(30) DEFAULT 'other',
  `ports` int(5) DEFAULT NULL,
  `secret` varchar(60) NOT NULL DEFAULT 'secret',
  `server` varchar(64) DEFAULT NULL,
  `community` varchar(50) DEFAULT NULL,
  `description` varchar(200) DEFAULT 'RADIUS Client',
  PRIMARY KEY (`id`),
  KEY `nasname` (`nasname`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `owner` (
  `ownerId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`ownerId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `partner` (
  `partnerId` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `adminEmail` varchar(150) DEFAULT NULL,
  `adminPhone` varchar(150) DEFAULT NULL,
  `techEmail` varchar(150) DEFAULT NULL,
  `techPhone` varchar(150) DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  infoAdmin varchar(255),
  infoTech varchar(255),
  infoSharePartner varchar(255),
  infoSharePartners varchar(255),
  PRIMARY KEY (`partnerId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `partnerRouter` (
  `routerId` int(11) NOT NULL AUTO_INCREMENT,
  `partnerId` int(11) NOT NULL,
  `ip` int(10) unsigned NOT NULL,
  `nettmask` int(10) unsigned NOT NULL,
  `taggedTrafficRoute` int(10) unsigned DEFAULT NULL,
  `taggedTrafficRouteUpdated` timestamp NULL DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  statusOk bit(1) not null default b'0',
  showToAdminsOnly bit(1) not null default b'0',
  showInStatusList tinyint(1) NOT NULL DEFAULT 1,
  status text,
  `demoStatusReceived` timestamp NULL DEFAULT NULL,
  `demoStatusReplied` timestamp NULL DEFAULT NULL,
  `partnerStatusReceived` timestamp NULL DEFAULT NULL,
  `partnerStatusReplied` timestamp NULL DEFAULT NULL,
  `workshopVerified` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`routerId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `pendingWget` (
  `wgetId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `category` enum('AssistanceRequest','Other') DEFAULT NULL,
  `regardingId` int(10) unsigned DEFAULT NULL,
  `handled` timestamp NULL DEFAULT NULL,
  reply text,
  PRIMARY KEY (`wgetId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `ping` (
  `pingId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` int(10) unsigned DEFAULT NULL,
  `received` timestamp NOT NULL DEFAULT current_timestamp(),
  `info` varchar(255) DEFAULT NULL,
  `nickName` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`pingId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('hourly','daily','monthly') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `data_limit` varchar(20) NOT NULL,
  `speed` varchar(50) NOT NULL,
  `devices` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `radacct` (
  `radacctid` bigint(21) NOT NULL AUTO_INCREMENT,
  `acctsessionid` varchar(64) NOT NULL DEFAULT '',
  `acctuniqueid` varchar(32) NOT NULL DEFAULT '',
  `username` varchar(64) NOT NULL DEFAULT '',
  `groupname` varchar(64) NOT NULL DEFAULT '',
  `realm` varchar(64) DEFAULT '',
  `nasipaddress` varchar(15) NOT NULL DEFAULT '',
  `nasportid` varchar(15) DEFAULT NULL,
  `nasporttype` varchar(32) DEFAULT NULL,
  `acctstarttime` datetime DEFAULT NULL,
  `acctstoptime` datetime DEFAULT NULL,
  `acctsessiontime` int(12) unsigned DEFAULT NULL,
  `acctauthentic` varchar(32) DEFAULT NULL,
  `connectinfo_start` varchar(50) DEFAULT NULL,
  `connectinfo_stop` varchar(50) DEFAULT NULL,
  `acctinputoctets` bigint(20) DEFAULT NULL,
  `acctoutputoctets` bigint(20) DEFAULT NULL,
  `calledstationid` varchar(50) NOT NULL DEFAULT '',
  `callingstationid` varchar(50) NOT NULL DEFAULT '',
  `acctterminatecause` varchar(32) NOT NULL DEFAULT '',
  `servicetype` varchar(32) DEFAULT NULL,
  `framedprotocol` varchar(32) DEFAULT NULL,
  `framedipaddress` varchar(15) NOT NULL DEFAULT '',
  `acctstartdelay` int(12) unsigned DEFAULT NULL,
  `acctstopdelay` int(12) unsigned DEFAULT NULL,
  `xascendsessionsvrkey` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`radacctid`),
  UNIQUE KEY `acctuniqueid` (`acctuniqueid`),
  KEY `username` (`username`),
  KEY `framedipaddress` (`framedipaddress`),
  KEY `acctsessionid` (`acctsessionid`),
  KEY `acctsessiontime` (`acctsessiontime`),
  KEY `acctstarttime` (`acctstarttime`),
  KEY `acctstoptime` (`acctstoptime`),
  KEY `nasipaddress` (`nasipaddress`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radcheck` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '==',
  `value` varchar(255) DEFAULT NULL,
  `mbquota` int(11) DEFAULT 0,
  `mbusage` float DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `createdTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `confirmedTime` timestamp NULL DEFAULT NULL,
  `confirmCode` varchar(20) DEFAULT NULL,
  `wrongConfCodeCount` int(11) DEFAULT 0,
  `wrongPasswordTime` timestamp NULL DEFAULT NULL,
  `wrongPasswordCount` int(11) DEFAULT 0,
  `subscriptionType` enum('quota','expiry','limited') NOT NULL,
  `expirytime` timestamp NULL DEFAULT NULL,
  `giveHoursAfterLogin` smallint(5) unsigned DEFAULT NULL,
  `campaignid` smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `username` (`username`(32))
) ENGINE=InnoDB AUTO_INCREMENT=178 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radgroupcheck` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `groupname` varchar(64) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '==',
  `value` varchar(253) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `groupname` (`groupname`(32))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radgroupreply` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `groupname` varchar(64) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '=',
  `value` varchar(253) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `groupname` (`groupname`(32))
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radpostauth` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `pass` varchar(64) NOT NULL DEFAULT '',
  `reply` varchar(32) NOT NULL DEFAULT '',
  `authdate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radreply` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `attribute` varchar(64) NOT NULL DEFAULT '',
  `op` char(2) NOT NULL DEFAULT '=',
  `value` varchar(253) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `radusergroup` (
  `username` varchar(64) NOT NULL DEFAULT '',
  `groupname` varchar(64) NOT NULL DEFAULT '',
  `priority` int(11) NOT NULL DEFAULT 1,
  KEY `username` (`username`(32))
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `requestDmesg` (
  `requestId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `registered` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip` int(10) unsigned NOT NULL,
  `updated` timestamp NULL DEFAULT NULL,
  `dmesg` text DEFAULT NULL,
  PRIMARY KEY (`requestId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `session` (
  `sessionid` int(11) NOT NULL AUTO_INCREMENT,
  `id` int(11) DEFAULT NULL,
  `ip` varchar(20) DEFAULT NULL,
  `username` varchar(150) DEFAULT NULL,
  `logintime` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lastrequest` timestamp NOT NULL DEFAULT current_timestamp(),
  `logouttime` timestamp NULL DEFAULT NULL,
  `active` int(11) DEFAULT NULL,
  PRIMARY KEY (`sessionid`)
) ENGINE=InnoDB AUTO_INCREMENT=447 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `setup` (
  `dbVersion` int(11) NOT NULL DEFAULT 0,
  `adminIP` int(10) unsigned DEFAULT NULL,
  `internalIP` int(10) unsigned DEFAULT NULL,
  `nettmask` int(10) unsigned DEFAULT NULL,
  `internalNic` varchar(20) DEFAULT NULL,
  `externalNic` varchar(20) DEFAULT NULL,
  `statusIntervalSec` tinyint(4) NOT NULL DEFAULT 60,
  isDbServer bit(1) not NULL default b'0',
  doingNAT bit(1) not NULL default b'0',
  `showStatus` bit(1) NOT NULL DEFAULT b'1',
  `showPreRoutePartner` bit(1) NOT NULL DEFAULT b'1',
  `showPreRouteNonPartner` bit(1) NOT NULL DEFAULT b'1',
  `showForwardPartner` bit(1) NOT NULL DEFAULT b'1',
  `showForwardNonPartner` bit(1) NOT NULL DEFAULT b'1',
  `showUrgentPtrUsage` bit(1) NOT NULL DEFAULT b'1',
  `showOwnerless` bit(1) NOT NULL DEFAULT b'1',
  `showOther` bit(1) NOT NULL DEFAULT b'1',
  `showNew1` bit(1) NOT NULL DEFAULT b'1',
  `showNew2` bit(1) NOT NULL DEFAULT b'1',
  `doTagging` bit(1) NOT NULL DEFAULT b'1',
  `doReportTraffic` bit(1) DEFAULT b'1',
  `doDemo` bit(1) NOT NULL DEFAULT b'1',
  `doInspection` bit(1) NOT NULL DEFAULT b'1',
  `doBlocking` bit(1) NOT NULL DEFAULT b'1',
  `doOther` bit(1) NOT NULL DEFAULT b'1',
  iptablesSetupChanged bit(1) not null default b'0',
  whoMaySsh int unsigned default 0,
  sshPort tinyint not null default 0,
  iptablesAllowSsh bit(1) not null default b'1',
  iptablesAllowPing bit(1) not null default b'1',
  `requestReboot` bit(1) NOT NULL DEFAULT b'0',
  `requestShutdown` bit(1) NOT NULL DEFAULT b'0',
  `hotspot` bit(1) NOT NULL DEFAULT b'1',
  requireRegistration bit(1) not null default b'0',
  selfRegistration bit(1) not null default b'1',
  `ssid` varchar(150) NOT NULL DEFAULT '',
  `isGlobalDbServer` bit(1) NOT NULL DEFAULT b'0',
  `globalDb1ip` int(10) unsigned DEFAULT NULL,
  `globalDb2ip` int(10) unsigned DEFAULT NULL,
  `globalDb3ip` int(10) unsigned DEFAULT NULL,
  `handled` bit(1) DEFAULT NULL,
  `lastPing` timestamp NULL DEFAULT NULL,
  `systemNick` varchar(100) DEFAULT NULL,
  `dhcpChecked` timestamp NULL DEFAULT NULL,
  `dhcpAdded` timestamp NULL DEFAULT NULL,
  `portUsageChecked` timestamp NULL DEFAULT NULL,
  `portUsageAdded` timestamp NULL DEFAULT NULL,
  `networkStatusChecked` timestamp NULL DEFAULT NULL,
  networkStatus text,
  `dmesg` mediumtext DEFAULT NULL,
  `dmesgUpdated` timestamp NULL DEFAULT NULL,
  `secondsSinceBoot` int(10) unsigned NOT NULL DEFAULT 1000000,
  `blockIncomingTaggedTrafficThreshold` tinyint(4) NOT NULL DEFAULT 15,
  blockSshThreshold tinyint not null default 0,
  `background` varchar(20) DEFAULT NULL,
  `uptime` float NOT NULL DEFAULT 1000000,
  `workshopId` int(10) unsigned DEFAULT NULL,
  `suspendHotspotLoginUntil` timestamp NULL DEFAULT NULL,
  `suspendGKLoginUntil` timestamp NULL DEFAULT NULL,
  dontDmesgIPs varchar(150),
  nickname varchar(100),
  systemMessage varchar(255),
  systemErrorSet timestamp null,
  systemErrorSeverity tinyint unsigned,
  systemError varchar(255),
  aiAssessment text,
  aiAssessmentTime timestamp null
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `systemMessage` (
  `systemMessageId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `createdTime` timestamp NOT NULL DEFAULT current_timestamp(),
  `message` text DEFAULT NULL,
  `seen` bit(1) NOT NULL DEFAULT b'0',
  `lastDiscovered` timestamp NULL DEFAULT NULL,
  `count` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `sysSnapshotSection` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`systemMessageId`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `traffic` (
  `trafficId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ipFrom` int(10) unsigned DEFAULT NULL,
  `ipTo` int(10) unsigned DEFAULT NULL,
  `whoIsId` int(10) unsigned DEFAULT NULL,
  `portFrom` int(10) unsigned DEFAULT NULL,
  `portTo` int(10) unsigned DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `lastSeen` timestamp NULL DEFAULT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 1,
  `isLan` bit(1) NOT NULL DEFAULT b'0',
  `tag` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`trafficId`),
  KEY `idx_traffic_flow_latest` (`ipFrom`,`portFrom`,`ipTo`,`portTo`,`trafficId`),
  KEY `idx_traffic_whois_pending` (`whoIsId`,`isLan`,`trafficId`),
  KEY `idx_traffic_active_lastseen` (`lastSeen`,`ipFrom`),
  KEY `idx_traffic_active_created` (`created`,`ipFrom`)
) ENGINE=InnoDB AUTO_INCREMENT=109459 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `unit` (
  `unitId` int(11) NOT NULL AUTO_INCREMENT,
  `ownerId` int(10) unsigned DEFAULT NULL,
  `macAddress` varchar(255) DEFAULT NULL,
  `ipAddress` int(10) unsigned DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  `dhcpClientId` binary(7) NOT NULL,
  `mac` binary(16) DEFAULT NULL,
  `vci` varchar(100) DEFAULT NULL,
  `hostname` varchar(200) DEFAULT NULL,
  `lastSeen` timestamp NULL DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`unitId`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `unitPort` (
  `portAssignmentId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `unitId` int(11) DEFAULT NULL,
  `ipAddress` int(10) unsigned DEFAULT NULL,
  `port` smallint(5) unsigned DEFAULT NULL,
  `lastSeen` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`portAssignmentId`)
) ENGINE=InnoDB AUTO_INCREMENT=6655 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `user` (
  `userId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(200) NOT NULL,
  `password` varchar(200) NOT NULL,
  `inserted` timestamp NOT NULL DEFAULT current_timestamp(),
  `emailSent` timestamp NULL DEFAULT NULL,
  `verified` bit(1) NOT NULL DEFAULT b'0',
  isAdmin bit(1) not null default b'0',
  `suspendedUntil` timestamp NULL DEFAULT NULL,
  `suspendedMinutes` timestamp NULL DEFAULT NULL,
  lastLogin timestamp null,
  loginFailsSinceSuccess int unsigned not null default 0,
  lastLoginIp int unsigned null,
  loginFailReportedTime timestamp null,
  PRIMARY KEY (`userId`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `usergroup` (
  `groupname` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `defaultpurpose` enum('groupmembers','generatetempusers') NOT NULL,
  PRIMARY KEY (`groupname`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `userusage` (
  `user` varchar(30) NOT NULL,
  `ip` char(15) NOT NULL,
  `yyyymmddhh` varchar(30) NOT NULL,
  `mb` float DEFAULT NULL,
  PRIMARY KEY (`user`,`ip`,`yyyymmddhh`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
SET character_set_client = @saved_cs_client;
CREATE TABLE `warning` (
  `warningId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `warning` text DEFAULT NULL,
  `inserted` timestamp NOT NULL DEFAULT current_timestamp(),
  `handled` timestamp NULL DEFAULT NULL,
  `count` int(10) unsigned NOT NULL DEFAULT 0,
  `lastWarned` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`warningId`)
) ENGINE=InnoDB AUTO_INCREMENT=9362 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `whoIs` (
  `whoIsId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ipFrom` int(10) unsigned DEFAULT NULL,
  `ipTo` int(10) unsigned DEFAULT NULL,
  `name` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`whoIsId`)
) ENGINE=InnoDB AUTO_INCREMENT=327 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `workshop` (
  `workshopId` int(10) unsigned NOT NULL,
  `ip` int(10) unsigned NOT NULL,
  `role` varchar(10) NOT NULL,
  `publicIp` int(10) unsigned NOT NULL,
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  `lastseen` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`workshopId`,`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `syslog` (
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
  lastSeen timestamp null,
  count int unsigned not null default 1,
  handled bit(1) not null default b'0',
  primary key(syslogId)
);
CREATE TABLE `syslogThreat` (
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
  service enum ('iptables','cisco','fortinet','palo_alto','cowrie','ssh','ddos','firewall','honeypot','web-service','login_attempt','db','other'),
  description varchar(255),
  count integer not null default 1,
  lastSeen timestamp null,
  handling varchar(255),
  demoSshSessionId bigint unsigned null,
  primary key(syslogThreatId),
  key idx_syslogthreat_pending (handled, syslogThreatId),
  key idx_syslogThreat_demoSshSession(demoSshSessionId)
);
CREATE TABLE `dhcpEvent` (
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
  unitId int unsigned null,
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
create table dmesg (
    dmesgId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created timestamp not null default current_timestamp,
    txt varchar(255),
    primary key(dmesgId)
);
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
CREATE TABLE `partnerRouterStatusLog` (
  ip int unsigned not null,
  created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status text null,
  primary key(ip, created)
);
create table aiResponse (
    aiResponseId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	created timestamp not null default current_timestamp,
	seconds int null,
	response text null,
	primary key (aiResponseId)
);
create table managerRequest (
	managerRequestId int unsigned not null auto_increment,
	created timestamp not null default current_timestamp,
	email varchar(255) not null,
	requestTokenHash char(64) not null,
	credentialPlain varchar(128) null,
	credentialHash char(64) null,
	credentialCreatedTime timestamp null,
	credentialClaimedTime timestamp null,
	emailVerifyTokenPlain varchar(128) null,
	emailVerifyTokenHash char(64) null,
	emailSentTime timestamp null,
	emailVerifiedTime timestamp null,
	gatewayApprovedTime timestamp null,
	approvedByUserId int unsigned null,
	rejectedTime timestamp null,
	active bit(1) not null default b'0',
	lastUsedTime timestamp null,
	expires timestamp null,
	primary key(managerRequestId),
	key idx_manager_email(email),
	key idx_manager_credential(credentialHash),
	key idx_manager_email_token(emailVerifyTokenHash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
create table demoSshNodeB (
	demoSshNodeBId int unsigned not null auto_increment,
	name varchar(128) not null,
	ip int unsigned not null,
	port smallint unsigned not null,
	sensorTokenHash char(64) not null,
	username varchar(16) null,
	passwordPlain varchar(32) null,
	passwordHash char(64) null,
	credentialGeneration int unsigned not null default 0,
	credentialCreated timestamp null,
	active bit(1) not null default b'1',
	created timestamp not null default current_timestamp,
	primary key(demoSshNodeBId),
	unique key uq_demoSshNodeB_endpoint(ip,port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `demoSshSetup` (
  demoSshSetupId int unsigned not null auto_increment,
  name varchar(128) not null,
  nodeAIp int unsigned not null,
  nodeAPort smallint unsigned not null default 22,
  demoSshNodeBId int unsigned not null,
  challengeTtlSeconds smallint unsigned not null default 1800,
  active bit(1) not null default b'1',
  created timestamp not null default current_timestamp,
  primary key(demoSshSetupId),
  key idx_demoSshSetup_nodes(nodeAIp,demoSshNodeBId,active),
  constraint fk_demoSshSetup_nodeB foreign key(demoSshNodeBId) references demoSshNodeB(demoSshNodeBId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `demoSshSession` (
  demoSshSessionId bigint unsigned not null auto_increment,
  demoSshSetupId int unsigned not null,
  demoSshNodeBId int unsigned not null,
  sourceIp int unsigned not null,
  unitId int unsigned null,
  nodeBSourcePort smallint unsigned null,
  credentialGeneration int unsigned not null,
  accessTokenHash char(64) not null,
  state enum('created','awaiting_node_a','demo_infected','awaiting_node_b','cleared','owner_clear_required','expired','cancelled','real_infection_detected') not null default 'awaiting_node_a',
  attempts tinyint unsigned not null default 0,
  nodeAEvidenceId int unsigned null,
  nodeBEvidenceId int unsigned null,
  created timestamp not null default current_timestamp,
  expires timestamp not null,
  completed timestamp null,
  lastSeen timestamp not null default current_timestamp,
  gatewayVerified BIT(1) NOT NULL DEFAULT b'0',
  primary key(demoSshSessionId),
  key idx_demoSshSession_source(sourceIp,state,expires),
  key idx_demoSshSession_access(accessTokenHash),
  key idx_demoSshSession_unit(unitId,state,expires),
  constraint fk_demoSshSession_setup foreign key(demoSshSetupId) references demoSshSetup(demoSshSetupId),
  constraint fk_demoSshSession_nodeB foreign key(demoSshNodeBId) references demoSshNodeB(demoSshNodeBId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
create table demoSshEvent (
	demoSshEventId bigint unsigned not null auto_increment,
	demoSshSessionId bigint unsigned not null,
	created timestamp not null default current_timestamp,
	eventType enum('created','node_a_observed','node_b_attempt','cleared','rejected','expired','cancelled','real_infection') not null,
	nodeIp int unsigned null,
	sourceIp int unsigned null,
	syslogThreatId int unsigned null,
	details varchar(255) null,
	primary key(demoSshEventId),
	key idx_demoSshEvent_session(demoSshSessionId,created),
	constraint fk_demoSshEvent_session foreign key(demoSshSessionId) references demoSshSession(demoSshSessionId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
create table demoSshAttempt (
	demoSshAttemptId bigint unsigned not null auto_increment,
	demoSshNodeBId int unsigned not null,
	demoSshSessionId bigint unsigned null,
	created timestamp not null default current_timestamp,
	sourceIp int unsigned not null,
	sourcePort smallint unsigned not null,
	destinationPort smallint unsigned not null,
	unitId int unsigned null,
	credentialGeneration int unsigned not null,
	credentialValid bit(1) not null default b'0',
	correlation enum('unit','single_source','pending','none') not null default 'pending',
	primary key(demoSshAttemptId),
	key idx_demoSshAttempt_tuple(sourceIp,sourcePort,destinationPort,created),
	key idx_demoSshAttempt_session(demoSshSessionId),
	constraint fk_demoSshAttempt_nodeB foreign key(demoSshNodeBId) references demoSshNodeB(demoSshNodeBId),
	constraint fk_demoSshAttempt_session foreign key(demoSshSessionId) references demoSshSession(demoSshSessionId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE gatewayDemoConfiguration (
	gatewayIp int unsigned not null,
	gatewayName varchar(120) not null default '',
	demo1ReceiverIp int unsigned null,
	demo1ReceiverName varchar(120) not null default '',
	demoSshSetupId int unsigned null,
	demo4RouterId int null,
	organisationLabel varchar(120) not null default '',
	updated timestamp not null default current_timestamp on update current_timestamp,
	primary key(gatewayIp),
	constraint fk_gatewayDemoConfiguration_ssh foreign key(demoSshSetupId) references demoSshSetup(demoSshSetupId),
	constraint fk_gatewayDemoConfiguration_demo4 foreign key(demo4RouterId) references partnerRouter(routerId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE demo4GatewayState (
	gatewayIp int unsigned not null,
	routerId int not null,
	state enum('configured','applied','error') not null default 'configured',
	message varchar(255) not null default '',
	reported timestamp not null default current_timestamp on update current_timestamp,
	primary key(gatewayIp,routerId),
	constraint fk_demo4GatewayState_router foreign key(routerId) references partnerRouter(routerId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `demoAssistanceSession` (
  sessionId INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL DEFAULT 'Community infection exercise',
  threshold TINYINT UNSIGNED NOT NULL DEFAULT 7,
  state ENUM('setup','active','contained','releasing','closed') NOT NULL DEFAULT 'setup',
  controllerToken CHAR(64) NOT NULL,
  targetIp VARCHAR(45) NOT NULL,
  visibility ENUM('public','group') NOT NULL DEFAULT 'public',
  groupLabel VARCHAR(120) NOT NULL DEFAULT '',
  joinCodeHash CHAR(64) NULL,
  containmentSeconds SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  assistanceRequestId INT UNSIGNED NULL,
  releaseRequestId INT UNSIGNED NULL,
  startsAt DATETIME NULL,
  blockAt DATETIME NULL,
  assistanceIssuedAt DATETIME NULL,
  releaseAt DATETIME NULL,
  closedAt DATETIME NULL,
  createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sessionId),
  UNIQUE KEY uq_demo_assistance_controller (controllerToken),
  KEY ix_demo_assistance_state (state, sessionId),
  KEY ix_demo_assistance_visibility (visibility, state, sessionId),
  KEY ix_demo_assistance_block (state, blockAt),
  KEY ix_demo_assistance_release (state, releaseAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `demoAssistanceParticipant` (
  participantId INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sessionId INT UNSIGNED NOT NULL,
  participantToken CHAR(64) NOT NULL,
  nickname VARCHAR(80) NOT NULL DEFAULT '',
  observedIp VARCHAR(45) NOT NULL,
  severity tinyint unsigned null default null,
  decision enum('pending','connected','silent','recovered','left') not null default 'pending',
  joinedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lastSeenAt DATETIME NULL,
  firstSilentAt DATETIME NULL,
  recoveredAt DATETIME NULL,
  leftAt DATETIME NULL,
  updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (participantId),
  UNIQUE KEY uq_demo_assistance_participant_token (participantToken),
  KEY ix_demo_assistance_session (sessionId, participantId),
  KEY ix_demo_assistance_seen (sessionId, lastSeenAt),
  CONSTRAINT fk_demo_assistance_participant_session
        FOREIGN KEY (sessionId) REFERENCES demoAssistanceSession(sessionId)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE demo4Session (
    sessionId CHAR(32) NOT NULL,
    tokenHash CHAR(64) NOT NULL,
    gatewayIp INT UNSIGNED NOT NULL,
    routerId INT NOT NULL,
    destinationIp INT UNSIGNED NOT NULL,
    relayIp INT UNSIGNED NOT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiresAt DATETIME NOT NULL,
    cleanGatewayAt DATETIME NULL,
    infectedGatewayAt DATETIME NULL,
    cleanWebsiteIp INT UNSIGNED NULL,
    cleanWebsiteAt DATETIME NULL,
    infectedWebsiteIp INT UNSIGNED NULL,
    infectedWebsiteAt DATETIME NULL,
    PRIMARY KEY (sessionId),
    KEY idx_demo4_session_expiry (expiresAt),
    KEY idx_demo4_session_gateway (gatewayIp, createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE demoSshIdentityProof (
    tokenHash CHAR(64) NOT NULL PRIMARY KEY,
    unitId INT UNSIGNED NOT NULL,
    clientIp INT UNSIGNED NOT NULL,
    expires DATETIME NOT NULL,
    KEY idx_demoSshIdentityProof_expiry (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE issue (
    issueId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nodeIp INT UNSIGNED NOT NULL,
    issueKey VARCHAR(255) NOT NULL,
    discoveredAt DOUBLE NOT NULL,
    lastSeenAt DOUBLE NOT NULL,
    checkedAt DOUBLE NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    commands TEXT NULL,
    verification VARCHAR(64) NOT NULL,
    blocker TEXT NULL,
    nextActionAt DOUBLE NULL,
    solvedAt DOUBLE NULL,
    UNIQUE KEY idx_issue_node_key (nodeIp, issueKey),
    KEY idx_issue_pending (status, nextActionAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE issueReport (
    reportId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    issueId BIGINT UNSIGNED NOT NULL,
    eventId CHAR(64) NOT NULL,
    reportedAt DOUBLE NOT NULL,
    kind VARCHAR(32) NOT NULL,
    detail TEXT NOT NULL,
    UNIQUE KEY idx_issue_report_event (issueId, eventId),
    KEY idx_issue_report_history (issueId, reportedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TRIGGER pendingWget_assistance_retry BEFORE UPDATE ON pendingWget FOR EACH ROW SET NEW.handled = IF(NEW.category = 'AssistanceRequest' AND NEW.reply IS NOT NULL AND TRIM(REPLACE(REPLACE(REPLACE(NEW.reply, CHAR(13), ''), CHAR(10), ''), CHAR(9), '')) <> 'ok', NULL, NEW.handled);
