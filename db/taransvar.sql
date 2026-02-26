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
  `category` enum('bruteForce','spoofing','test','other','cancel') DEFAULT NULL,
  `regardingRequestId` int(10) unsigned DEFAULT NULL,
  `comment` varchar(200) DEFAULT NULL,
  `handlingComment` varchar(200) DEFAULT NULL,
  `senttime` timestamp NULL DEFAULT NULL,
  `requestQuality` smallint(5) unsigned DEFAULT NULL,
  `wantSpoofed` bit(1) NOT NULL DEFAULT b'1',
  `active` bit(1) NOT NULL DEFAULT b'1',
  `fromOther` bit(1) NOT NULL DEFAULT b'0',
  `handled` bit(1) DEFAULT NULL,
  `purpose` enum('internalRequest','forDistribution','fromPartner') DEFAULT NULL,
  PRIMARY KEY (`requestId`)
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
  `status` varchar(40) DEFAULT NULL,
  `handledTime` timestamp NULL DEFAULT NULL,
  `unitId` int(10) unsigned DEFAULT NULL,
  `lastSeen` timestamp NOT NULL DEFAULT current_timestamp(),
  `taransvarId` int(10) unsigned DEFAULT NULL,
  `sentGlobalDB` timestamp NULL DEFAULT NULL,
  `ipOwnerId` varchar(100) DEFAULT NULL,
  `sendAttemptCount` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`reportId`)
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
  `active` bit(1) NOT NULL DEFAULT b'1',
  `lastSeen` timestamp NOT NULL DEFAULT current_timestamp(),
  `taransvarId` int(10) unsigned DEFAULT NULL,
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
  PRIMARY KEY (`partnerId`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `partnerRouter` (
  `routerId` int(11) NOT NULL AUTO_INCREMENT,
  `partnerId` int(11) NOT NULL,
  `ip` int(10) unsigned NOT NULL,
  `nettmask` int(10) unsigned NOT NULL,
  `handled` bit(1) DEFAULT NULL,
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
  `requestReboot` bit(1) NOT NULL DEFAULT b'0',
  `requestShutdown` bit(1) NOT NULL DEFAULT b'0',
  `hotspot` bit(1) NOT NULL DEFAULT b'1',
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
  `networkStatus` varchar(255) DEFAULT NULL,
  `dmesg` mediumtext DEFAULT NULL,
  `dmesgUpdated` timestamp NULL DEFAULT NULL,
  `secondsSinceBoot` int(10) unsigned NOT NULL DEFAULT 1000000,
  `blockIncomingTaggedTrafficThreshold` tinyint(4) NOT NULL DEFAULT 15,
  `background` varchar(20) DEFAULT NULL,
  `uptime` float NOT NULL DEFAULT 1000000,
  `workshopId` int(10) unsigned DEFAULT NULL,
  `suspendHotspotLoginUntil` timestamp NULL DEFAULT NULL,
  `suspendGKLoginUntil` timestamp NULL DEFAULT NULL
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
  `count` int(10) unsigned NOT NULL DEFAULT 1,
  `isLan` bit(1) NOT NULL DEFAULT b'0',
  `tag` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`trafficId`)
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
  `suspendedUntil` timestamp NULL DEFAULT NULL,
  `suspendedMinutes` timestamp NULL DEFAULT NULL,
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

