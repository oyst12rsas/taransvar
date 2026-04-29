
#include "../tarakernel/module_globals.h" 

/*	READ HERE!
	Current version is sending list of internal servers and their instructions for 
	required quality of senders.
	This function should also send list of infected servers in home network for tagging
	by taransvar kernel module (tarakernel). It also has to handle situations where there's too
	many entries in the list, so that it exceeds the size of the buffer. The string has
	a sequence number for retransmissions. Suggested solution is that taralink informs 
	tarakernel at the end of the string that there's more data available and it then sends
	a new request for more increasing the sequence number. This means data may get lost if 
	changes are made in the meantime unless userserver keeps data required to know which record
	is next.
	
	There should also be implemented a recurring request for updates (e.g. once a minute) 
	to keep the list updated. 

	Other data to send will be included later:
	- We're under attack, only let through safe data to one specific server or all

	Other possible future expansions:
	- We may also want to switch to sending binary data instead of string later.
	- We may want to allow address segments as 192.168.1.0/24	
*/

/*
int fileConfigurationSent(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound)
{
	#define C_BUF_SIZE 4000
	FILE *file;
	int nThreadId;

		if ((file = fopen(CONFIG_FILENAME, "r")))
	{
		char cBuf[C_BUF_SIZE];
		fgets(cBuf, C_BUF_SIZE, file);	
		fclose(file);
		
		nThreadId = syscall(SYS_gettid);//sys_gettid(); // //gettid()
		sendMessage(pSockData, cBuf);
		printf("Configuration file found and sent(%ld chars): %s.\nPreparing to read again\n", strlen(cBuf), cBuf);
		//printf("%s\n",cBuf);
		return 1;
	}
	return 0;
}*/

void updateHandled(MYSQL *updateConn, char *lpTableName, char *lpKeyField, char *lpId)
{
	char cSQL[300];
	snprintf(cSQL, sizeof(cSQL), "update %s set handled = b'1' where %s = %s", lpTableName, lpKeyField, lpId);
 	//printf("Updating: %s\n", cSQL);
	if (mysql_query(updateConn, cSQL)) {
	    fprintf(stderr, "%s\n", mysql_error(updateConn));
	    addWarningRecord("*********** ERROR *********** Taralink couldn't update handled fields.");
	}
}

void reportErrorReadin(char *lpWhat)
{
        char szMsg[1000];
        char *lpMsg = "****** ERROR ***** Taralink couldn't read %s. (T007)";
        int nRequiredBufSize = strlen(lpWhat) + strlen(lpMsg); 
        if (nRequiredBufSize >= sizeof(szMsg))
            sprintf(szMsg, "***** ERROR ****** Insufficient buffer in reportErrorReadin(). Buffer: %ld, required: %d.", sizeof(szMsg), nRequiredBufSize);
        else
        	sprintf(szMsg, lpMsg, lpWhat); 

        addWarningRecord(szMsg);
}

bool getSetupStringNewOk(MYSQL *conn, MYSQL *updateConn, char *cSetupString, int nBuffSize, bool bReadChangesOnly)
{
	MYSQL_RES *res;
	MYSQL_ROW row;
	*cSetupString = 0;

	char *lpSQL = "select adminIp, \
			internalIP, \
			nettmask, \
			handled, \
			blockIncomingTaggedTrafficThreshold, \
			showStatus, \
			showPreRoutePartner, \
			showPreRouteNonPartner, \
			showForwardPartner, \
			showForwardNonPartner, \
			showUrgentPtrUsage, \
			showOwnerless, \
			showOther, \
			showNew1, \
			showNew2, \
			doTagging, \
			doReportTraffic, \
			doInspection, \
			doBlocking, \
			doOther, \
			dontDmesgIPs from setup";

	//select adminIp, internalIP, handled, showStatus, showPreRoutePartner, showPreRouteNonPartner, showForwardPartner, 	showForwardNonPartner, 	showUrgentPtrUsage, showOwnerless, 	showOther, showNew1, showNew2, doTagging, doReportTraffic, 	doInspection, doBlocking, doOther, dontDmesgIPs from setup			
		
	if (mysql_query(conn, lpSQL)) {
		fprintf(stderr, "taralink: %s\n", mysql_error(conn));
		reportErrorReadin("setup");
		return 0;
	}

	res = mysql_use_result(conn);
	//res = mysql_store_result(conn);		
	if (!res) {
	   	fprintf(stderr, "mysql_store_result failed: %s\n", mysql_error(conn));
		return 0;
	}		

	if ((row = mysql_fetch_row(res)) != NULL)
	{
		//printf("Found setup row...\n");
		if (!bReadChangesOnly || !atoi(row[3]))
		{
			//printf("processing it...\n");
			union _showStatusBitsUnion cShowStatusBits;
			cShowStatusBits.nValues = 0; //Initialize the whole union / structure
			//cShowStatusBits.bits.nDummy = 0;
			int nField = 5;
			//printf("reading bit fields...\n");
			cShowStatusBits.bits.showStatus  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRoutePartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRouteNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showUrgentPtrUsage  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOwnerless  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOther  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew1  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew2  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doTagging  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doReportTraffic = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doInspection  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doBlocking  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doOther  = (row[nField]?*row[nField]:0);	nField++;

			//printf("after reading bit fields...\n");

			#define N_MAX_DONT_DMSG_IPs 150
			int nDontMsgFldNo = nField++;	
			char szDontDmesgIPs[N_MAX_DONT_DMSG_IPs];
			szDontDmesgIPs[0] = 0;
//			uint32_t ip_numeric = 0;

			//if (row[nDontMsgFldNo] && *row[nDontMsgFldNo])	//260406 asdf
			if (row[nDontMsgFldNo] != NULL && *row[nDontMsgFldNo])
			{
				//printf("DontSendTo: %s\n", row[nDontMsgFldNo]);
				//strcpy(szDontDmesgIPs, row[nDontMsgFldNo]);

				snprintf(szDontDmesgIPs, sizeof(szDontDmesgIPs), "%s", row[nDontMsgFldNo]);					
				if (strlen(szDontDmesgIPs) > N_MAX_DONT_DMSG_IPs - 50)
					printf("************ WARNING **** Consider increasing buffer for IPs not to log to dmesg from %u (currently in use: %zu)\n", N_MAX_DONT_DMSG_IPs, strlen(szDontDmesgIPs));

				//NOTE! For now only handles one IP address
//				if (strlen(szDontDmesgIPs))
//					ip_numeric = inet_addr(szDontDmesgIPs);

				if (strchr(szDontDmesgIPs, '^') || strchr(szDontDmesgIPs, '\\') || strchr(szDontDmesgIPs, '\''))
				{
					printf("********* ERROR ********** List of IP addresses not to log to dmsg can only contain IP addresses separated by comma\n");
					strcpy(szDontDmesgIPs, "0");
				}
			}
			else
			{
				printf("No IP not to send dmesg set (fld no: %d)..\n", nDontMsgFldNo);
				strcpy(szDontDmesgIPs, "0");
			}

			//printf("Converting ips\n");				
			uint32_t adminIP = (uint32_t)strtoul(row[0]?row[0]:"0", NULL, 10);
			uint32_t internalIP = (uint32_t)strtoul(row[1]?row[1]:"0", NULL, 10);
			uint32_t nettmask = (uint32_t)strtoul(row[2]?row[2]:"0", NULL, 10);

			unsigned int  nBlockingThreshold = atoi(row[4]);

			snprintf(cSetupString, nBuffSize, "SETUP|%08X^%08X^%08X^%01X^%02X^%s^|", adminIP, internalIP, nettmask, nBlockingThreshold, cShowStatusBits.nValues, szDontDmesgIPs);
				//strcpy(cReply+strlen(cReply), "SETUP|");
				//strcpy(cReply+strlen(cReply), row[0]);
				//strcpy(cReply+strlen(cReply), "|");

			printf("Setup added now : %s^%s^%s\n", (row[0]?row[0]:"N/A"), (row[1]?row[1]:"N/A"), (row[2]?row[2]:"N/A"));
			if (!atoi(row[3]?row[3]:"0")) {
				//printf("Setting setup as handled..\n");
				if (mysql_query(updateConn, "update setup set handled = b'1'")) {
					fprintf(stderr, "%s\n", mysql_error(updateConn));
					addWarningRecord("****** ERROR Error updating setup handled field (meaning it will read again)");
			    	mysql_free_result(res);
					return 0;
				}
		  	}
			else
				printf("setup was handled.. not setting\n");
					//printf("Finished processing it...\n");
		}  
		else
			printf("Not adding setup.. handled was: %s\n", row[3]);
	}
	else
	{
		//Used to report failure to read setup to global DB server, but we no longer have that server
   	    //unsigned long nMinutes = minutesSincePing(); 
       	//if (nMinutes >= 10)
        //{
			//setPing();
  	         /*
    	     char szUrl[255];
          	strcpy(szUrl, "http://81.88.19.252/script/config_update.php?f=ping&status=Unable_to_read_setup");
           *szWgetBuff = 0;
            wget(szUrl, szWgetBuff, sizeof(szWgetBuff));  //Using global static buffers because reply doesn't come immediately.
   	        //printf("%s\n", szUrl);
       	    */
		//}
        //printf("Minutes: %lu (%s)\n", nMinutes, szWgetBuff);
		//printf("************ ERROR! Unable to read the setup\n");
	}	
   	mysql_free_result(res);
	return 1;
}//getSetupStringNewOk()



//int sentConfiguration(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
int sentConfiguration(int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
{
	//This is a request for configuration setup...
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST|INSPECT|DROP]

	//Below, the setup is read from database, but configuration sent to kernel is hard coded

        //printf("About to check setup\n");

	/*if (!bReadChangesOnly)
		if (fileConfigurationSent(nSequenceNumber, bIsInbound))
			return 0;*/

	MYSQL *conn, *updateConn;
	MYSQL_RES *res;
	MYSQL_ROW row;
	char cReply[C_BUFF_SIZE];	
	*cReply = 0;
	int bFoundData = 0;
	int nFound = 0;
	int nCharsTruncated = 0;

	if (nSequenceNumber == 0)	//This is the first batch (for now there's only 1 batch)
	{
	    char szSQL[400];	//NOTE! 256 is now too small for internalInfections SQL
	    char *lpHandledWhere;
		conn = getConnection();
		updateConn = getConnection();
		//printf("Reading configuration.....\n");
		sprintf(cReply, "CONFIG %d|", nSequenceNumber);
		
#ifdef SETUP_INTERNAL_SERVERS

		//***************** Internal servers **********************
		  
		//printf("********* WARNING - Dropping reading internal server setup due to error.\n");
		//if (0)
		//{
        //printf("Reading servers...\n");
		
		//NOTE! Only sends publicPort and protection to tarakernel but requres internal ip and port to set to handled
		sprintf(szSQL, "select publicPort, protection, ip, inet_ntoa(ip), port, coalesce(handled,0) from internalServers");
		
		if (bReadChangesOnly)
		      strcpy(szSQL+strlen(szSQL), " where handled is null");

                //printf("SQL: %s\n", szSQL);
		
		//if (bReadChangesOnly)
		//      strcpy(szSQL+strlen(szSQL), " where handled is null");

		//char *lpSQL = "show tables";
		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "%s\n", mysql_error(conn));
		    reportErrorReadin("servers");
		    return 0;
		}
		res = mysql_use_result(conn);

		//Read configuration from DB and put in cReply for sending back to kernel (tarakernel)
		//printf("Computer setup in mysql database (about to send kernel) - reading %s:\n", (bReadChangesOnly?"changes only":"full setup"));
		nFound =0;
		while ((row = mysql_fetch_row(res)) != NULL)
		{
		    bFoundData = 1;
			if (!nFound)
				sprintf(cReply+strlen(cReply), "SERVERS|");

			printf("%s:%s->%s - %s\n", row[3], row[4], row[0], row[1]);
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s-%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
				
			nFound++;
			if (atoi(row[5]) == 0)
			{
			    printf("Setting server as handled\n");
			    //Can't use this because we don't have id: updateHandled(updateConn, "internalServers", "ip", row[3]);
			    updateHandled(updateConn, "internalServers", "publicPort", row[0]);
			    //sprintf(szSQL, "update internalServer set handled = b'1' where ip = %s and port 
                printf("************** Updating internal server: %s\n", row[0]);
			}
			else
			    printf("Server was handled\n");
		}

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");
			
		mysql_free_result(res);

#endif //#ifdef SETUP_INTERNAL_SERVERS


		//printf("Setup after servers: %s\n", cReply);
		//}

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();


#ifdef SETUP_BLACK_AND_WHITELISTS

		//************** Add the white- and blacklistings *****************
	    //printf("Reading black- and white listings...\n");
		strcpy(szSQL, "select inet_ntoa(ip) as ip, upper(color), ip as aIp, handled from vListings");
		
		if (bReadChangesOnly)
		    strcpy(szSQL+strlen(szSQL), " where handled is null");

		if (mysql_query(conn, szSQL)) {
			fprintf(stderr, "%s\n", mysql_error(conn));
  			reportErrorReadin("white- and blacklists");
  			return 0;
		}
		res = mysql_use_result(conn);
		char szColorList[20];
		*szColorList = 0;

		//Read configuration from DB and put in cReply for sending back to kernel (tarakernel)
		nFound =0;
		while ((row = mysql_fetch_row(res)) != NULL)
		{
    	    bFoundData = 1;
			if (strcmp(szColorList, row[1]))
			{
				if (nFound)
					strcpy(cReply+strlen(cReply), "|");

				strcpy(szColorList, row[1]);
				sprintf(cReply+strlen(cReply), "%s_LIST|", szColorList);
				//printf("New color: %s\n", szColorList);
			}

			//printf("%s : %s\n", row[0], row[1]);
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s^", row[0]);
			else
				nCharsTruncated += 12;

			nFound++;
			updateHandled(updateConn, "colorListings", "ip", row[2]);
			updateHandled(updateConn, "domainIp", "ip", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_BLACK_AND_WHITELISTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INTERNAL_INFECTIONS

		//*************************Send info on internal infections (in the network) ****************
		//printf("Reading internal unit infections...\n");
		
		if (bReadChangesOnly)
			lpHandledWhere = "handled is null or handled = b'0'";
	    else
	        lpHandledWhere = "active = b'1'";

		sprintf(szSQL, "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, coalesce(status,'NULL'), infectionId, handled, coalesce(CAST(active AS UNSIGNED),0) as active, coalesce(infoSharePartners,'NULL'), coalesce(unitId,0), coalesce(severity,0), coalesce(botnetId,0), ip, nettmask from internalInfections where %s", lpHandledWhere);
		//printf("SQL: %s\n", szSQL);

		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "%s\n", mysql_error(conn));
 		    reportErrorReadin("internal infections");
		    return 0;
		}
		res = mysql_use_result(conn);

		nFound =0;

		while ((row = mysql_fetch_row(res)) != NULL)
		{
    		bFoundData = 1;

			if (!nFound)
				sprintf(cReply+strlen(cReply), "INFECTION|");

			//printf("taralink: Infection found : %s-%s-%s-%s\n", row[0], row[1], row[5], row[2]);
			//															ip		nett	active status  infID   severity botnetId info
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%s-%s-%s-%s^", row[0], row[1], row[5], row[2], row[3], row[8], row[9], row[6]);
			else
				nCharsTruncated += 70;

			//	ip				nett	active status  infID   severity botnetId info
/*INFECTION|	100.100.100.100:255.255.255.255-1-(null)-       -1503633950-        -1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
*/

			if (!row[4] || !atoi(row[4])) 
				updateHandled(updateConn, "internalInfections", "infectionId", row[3]);
      			     
			if (bReadChangesOnly)
				init_background_infecton_change_partner_notification(atol(row[10]), atol(row[11]), row[5], atol(row[2]), atol(row[3]), atol(row[8]), atol(row[9]), row[6]);	//ip		nett	active status  infID   severity botnetId info

			nFound++;
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_INTERNAL_INFECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_PARTNERS

		//*************************Send partner info ****************
		//printf("Reading partners...\n");
		strcpy(szSQL, "select hex(ip), hex(nettmask), routerId from partnerRouter");
		
		if (bReadChangesOnly)
		      strcpy(szSQL+strlen(szSQL), " where handled is null");

		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "%s\n", mysql_error(conn));
 		    reportErrorReadin("partner info");
		    return 0;
		}
		res = mysql_use_result(conn);

		nFound =0;

		while ((row = mysql_fetch_row(res)) != NULL)
		{
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (!nFound)
			{
				if (nPosLeft < 15)
					break;
				sprintf(cReply+strlen(cReply), "PARTNER|");
			}

			printf("Partner found : %s-%s\n", row[0], row[1]);

			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;

			nFound++;
			updateHandled(updateConn, "partnerRouter", "routerId", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
		{
			printf("%d routers updated\n", nFound);
			strcpy(cReply+strlen(cReply), "|");
	        bFoundData = 1;
        }
		//else
		//	printf("No routers updated\n", nFound);

#endif //#define SETUP_PARTNERS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INSPECTIONS

		//************** Add packet inspection into ([INSPECT|DROP])the white- and blacklistings *****************
		//printf("Reading inspections...\n");
		
		if (bReadChangesOnly)
    		strcpy(szSQL, "select hex(ip), hex(nettmask), handling, ip from inspection ip where active = b'1' and handled is null order by handling");
	    else
  		    strcpy(szSQL, "select hex(ip), hex(nettmask), handling, ip from inspection ip where active = b'1' order by handling");

		//printf("SQL: %s\n", szSQL);

		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "taralink: %s\n", mysql_error(conn));
 		    reportErrorReadin("inspection info");
		    return 0;
		}

		res = mysql_use_result(conn);
		char szHandling[20];
		*szHandling = 0;

		nFound =0;
		
		while ((row = mysql_fetch_row(res)) != NULL)
		{
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;

			if (strcmp(szHandling, row[2]))
			{
				if (nPosLeft < 15)
					break;

				if (nFound)
					strcpy(cReply+strlen(cReply), "|");

				strcpy(szHandling, row[2]);
				snprintf(cReply+strlen(cReply), sizeof(cReply)-strlen(cReply)-1, (!strcmp(row[2], "Inspect")?"INSPECT|":"DROP|"));
				printf("Now handling: %s\n", szHandling);
				nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			}

			printf("%s : %s\n", row[0], row[1]);
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
			nFound++;
			updateHandled(updateConn, "inspection", "ip", row[3]);
		}
		mysql_free_result(res);

		if (nFound) {
			strcpy(cReply+strlen(cReply), "|");
		    bFoundData = 1;
        }
#endif //#ifdef SETUP_INSPECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_HONEYPOTS

		//************** Add honeyports ([HONEY]) *****************
		//printf("Reading honeypots...\n");
        if (!bReadChangesOnly)
			strcpy(szSQL, "select port, handling from honeyport order by port");
        else	        
			strcpy(szSQL, "select port, handling from honeyport where handled is null order by port");
		
		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "taralink: %s\n", mysql_error(conn));
		    reportErrorReadin("honeypot info");
		    return 0;
		}
		res = mysql_use_result(conn);

		nFound =0;
		
		while ((row = mysql_fetch_row(res)) != NULL)
		{
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
				break;

        	if (!nFound)
			{
				if (nPosLeft < 22)
					break;

				strcpy(cReply+strlen(cReply), "HONEY|");
				nPosLeft -= strlen("HONEY|");
			}

            printf("%s : %s\n", row[0], row[1]);
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;

			nFound++;
			updateHandled(updateConn, "honeyport", "port", row[0]);
		}
		mysql_free_result(res);

		if (nFound) {
			bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
		}

		//************** Add assistance request ([ASSIST]) *****************
		/* Customers can at any time request assistance from partners fighting D-Dos or brute force attack. mics/checkload.pl
		will initiate request for assistance by putting record in assistanceRequest table. Taralink will so send it to the listed
		global servers (see table setup->globalDb1ip..3 for ip address). This is done by calling script/requestAssistance.php 
		(see taralink/module_request_assistance.c).. On the global DB servers, taralink will so distribute such request to
		all routers using the same function in taralink/module_request_assistance.c by calling script/partnerRequest.php on all partners
		script/partnerRequest.php will put it in the local assistanceRequest, ABBmonitor will then forward this to the abscurity program
		for filtering outbound presumed infected traffic. */ 
		
		if (bReadChangesOnly)
		    lpHandledWhere = " and handled is null";
		else
		    lpHandledWhere = " and active = b'1'";
		        
		snprintf(szSQL, sizeof(szSQL), "select requestId, hex(ip), port, requestQuality, CAST(wantSpoofed AS UNSIGNED) as wantSpoofed, handled, CAST(active AS UNSIGNED) as active from assistanceRequest where purpose = 'fromPartner' %s order by ip", lpHandledWhere);
		//printf("Assist requests: %s\n", szSQL);
		
		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "taralink: %s\n", mysql_error(conn));
		    reportErrorReadin("requests for assistance");
		    return 0;
		}
		res = mysql_use_result(conn);

		nFound =0;
		
		while ((row = mysql_fetch_row(res)) != NULL)
		{
		    int nActive;
        	if (!nFound)
				strcpy(cReply+strlen(cReply), "ASSIST|");
				
			nActive = (atoi(row[6])? 1 : 0);

            //printf("Assistance request: %s:%s-%s-%s-%d\n", row[1], row[2], row[3], row[4], nActive);
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%d^", row[1], (row[2]?row[2]:"0"), row[3], row[4], nActive);
			else
				nCharsTruncated += 10;
			nFound++;
			updateHandled(updateConn, "assistanceRequest", "requestId", row[0]);
		}
		mysql_free_result(res);

		if (nFound) {
		    bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
        }

#endif //#ifdef SETUP_HONEYPOTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_SETUP
		//************** Add setup *****************
		//printf("Reading setup...\n");
		bool bReadSetup = 1;

		if (bReadChangesOnly)
		{
			char *lpSQL = "select dmesgUpdated from setup where handled is null limit 1";

			if (mysql_query(conn, lpSQL)) {
				fprintf(stderr, "taralink: %s\n", mysql_error(conn));
				reportErrorReadin("setup");
				return 0;
			}
				
			//res = mysql_use_result(conn);
			res = mysql_store_result(conn);		
			if (!res) {
			    fprintf(stderr, "mysql_use_result failed: %s\n", mysql_error(conn));
    			return 0;
			}		

			if ((row = mysql_fetch_row(res)) == NULL)
			{
				//printf("Setup not changed. Skipping reading.\n");
				bReadSetup = false;
			}
			else 
				printf("Setup row found. Dmsg read: %s\n", row[0]?row[0]:"(NULL)");

	    	mysql_free_result(res);
			res = NULL;
		}

		if (bReadSetup)
		{
			//char cSetupString[1000];
			//20k memory leak per minute before due to long mysql query.. Old method saved in getSetupStringOk() function...
			char cSetupStringNew[1000];
			if (//!getSetupStringOk(conn, updateConn, cSetupString, sizeof(cSetupString), bReadChangesOnly) ||
				!getSetupStringNewOk(conn, updateConn, cSetupStringNew, sizeof(cSetupStringNew), bReadChangesOnly))
				return 0;

			/*if (strcmp(cSetupString, cSetupStringNew))
				printf("********** WARNING ********* Setting strings differ: (old/new)\n%s\n%s\n", cSetupString, cSetupStringNew);
			else
				printf("New and old setup routines agree: %s\n", cSetupString);
				*/
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s", cSetupStringNew);
			else
				nCharsTruncated += strlen(cSetupStringNew);

		    bFoundData = 1;
		}
		//else	
		//	printf("Skipping reading (already handled)\n");
			
		//printf("Freeing up connections\n");
#endif //#ifdef SETUP_SETUP

        //***************** Finish it up 

		int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
		if (nPosLeft > 3)
			strcpy(cReply+strlen(cReply), "EOF");
		else
			nCharsTruncated += 3;

		/* close connection */
		mysql_close(conn);
		mysql_close(updateConn);

		//This is the hard coding.. Replace with data read from server above.
		//sprintf(cReply, "%d|192.168.1.20:8080-clean^192.168.1.20:64-nobot", nSequenceNumber); 
	}
	else
		sprintf(cReply, "%d|EOF", nSequenceNumber); //For now only handles one sequence.. but may requrie more in future....

	//int nThreadId;
    //nThreadId = syscall(SYS_gettid);//sys_gettid(); // //gettid()
    //printf("Setup before sending: %s\n", cReply); 
        
    if (bFoundData)
    {
        //sendMessage(pSockData, cReply);
		send_to_kernel(fd, cReply, strlen(cReply));		
		printf("Configuration sent(%ld chars): %s\n", strlen(cReply), cReply);
		return 1; //Did send data
	}
	//else
	//	printf("Configuration is unchanged.\n");
	
	//Note... This is not complete.. If there's some available space, it will add just part of the buffer and not add anything to nCharsTruncated (especially if it's the last section - the setup table)
	if (nCharsTruncated)
		printf("\n************* WARNING **************************\n\nLacking estimated at least %d char buffer space to send setup!\n\n*************************************************\n", nCharsTruncated);
	//else	
	//	printf("Setup: %lu chars, buffer size: %lu\n", strlen(cReply), sizeof(cReply));

	return 0;
}
