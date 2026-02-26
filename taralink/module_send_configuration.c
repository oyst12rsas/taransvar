
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
}

void updateHandled(MYSQL *updateConn, char *lpTableName, char *lpKeyField, char *lpId)
{
	char cSQL[200];
 	sprintf(cSQL, "update %s set handled = b'1' where %s = %s", lpTableName, lpKeyField, lpId); 
 	printf("Updating: %s\n", cSQL);
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

int sentConfiguration(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
{
	//This is a request for configuration setup...
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST|INSPECT|DROP]

	//Below, the setup is read from database, but configuration sent to kernel is hard coded

        //printf("About to check setup\n");

        if (!bReadChangesOnly)
	    if (fileConfigurationSent(pSockData, nSequenceNumber, bIsInbound))
	  	return 0;

#ifdef USE_MYSQL 
	MYSQL *conn, *updateConn;
	MYSQL_RES *res;
	MYSQL_ROW row;
	char cReply[C_BUFF_SIZE];	
	char cBuf[C_BUFF_SIZE];
	memset(cBuf, 0, C_BUFF_SIZE);
	*cReply = 0;
	int nThreadId;
        int bFoundData = 0;

	if (nSequenceNumber == 0)	//This is the first batch (for now there's only 1 batch)
	{
	        char szSQL[256];
	        char *lpHandledWhere;
		conn = getConnection();
		updateConn = getConnection();
		printf("Reading configuration.....\n");
		sprintf(cReply, "CONFIG %d|", nSequenceNumber);
		
		//***************** Internal servers **********************

		int nFound = 0;
		  
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
			sprintf(cReply+strlen(cReply), "%s-%s^", row[0], row[1]);
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
		//printf("Setup after servers: %s\n", cReply);
//}
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
			sprintf(cReply+strlen(cReply), "%s^", row[0]);
			nFound++;
			updateHandled(updateConn, "colorListings", "ip", row[2]);
			updateHandled(updateConn, "domainIp", "ip", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

		//*************************Send info on internal infections (in the network) ****************
		//printf("Reading internal unit infections...\n");
		
		if (bReadChangesOnly)
	              lpHandledWhere = "handled is null or handled = b'0'";
	        else
	              lpHandledWhere = "active = b'1'";

		sprintf(szSQL, "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, infectionId, handled, CAST(active AS UNSIGNED) as active from internalInfections where %s", lpHandledWhere);
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
			sprintf(cReply+strlen(cReply), "%s:%s-%s-%s^", row[0], row[1], row[5], row[2]);

                        if (!row[4] || !atoi(row[4])) 
      			        updateHandled(updateConn, "internalInfections", "infectionId", row[3]);
      			        
			nFound++;
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

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
			if (!nFound)
				sprintf(cReply+strlen(cReply), "PARTNER|");

			printf("Partner found : %s-%s\n", row[0], row[1]);
			sprintf(cReply+strlen(cReply), "%s:%s^", row[0], row[1]);
			nFound++;
			updateHandled(updateConn, "partnerRouter", "routerId", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
		{
			strcpy(cReply+strlen(cReply), "|");
		        bFoundData = 1;
                }

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
			if (strcmp(szHandling, row[2]))
			{
				if (nFound)
					strcpy(cReply+strlen(cReply), "|");

				strcpy(szHandling, row[2]);
				sprintf(cReply+strlen(cReply), (!strcmp(row[2], "Inspect")?"INSPECT|":"DROP|"));
				printf("Now handling: %s\n", szHandling);
			}

			printf("%s : %s\n", row[0], row[1]);
			sprintf(cReply+strlen(cReply), "%s:%s^", row[0], row[1]);
			nFound++;
			updateHandled(updateConn, "inspection", "ip", row[3]);
		}
		mysql_free_result(res);

		if (nFound) {
			strcpy(cReply+strlen(cReply), "|");
		        bFoundData = 1;
                }

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
        		if (!nFound)
				strcpy(cReply+strlen(cReply), "HONEY|");

                        printf("%s : %s\n", row[0], row[1]);
			sprintf(cReply+strlen(cReply), "%s:%s^", row[0], row[1]);
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
		        
		sprintf(szSQL, "select requestId, hex(ip), port, requestQuality, CAST(wantSpoofed AS UNSIGNED) as wantSpoofed, handled, CAST(active AS UNSIGNED) as active from assistanceRequest where purpose = 'fromPartner' %s order by ip", lpHandledWhere);
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
			sprintf(cReply+strlen(cReply), "%s:%s-%s-%s-%d^", row[1], row[2], row[3], row[4], nActive);
			nFound++;
			updateHandled(updateConn, "assistanceRequest", "requestId", row[0]);
		}
		mysql_free_result(res);

		if (nFound) {
		        bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
                }

		//************** Add setup *****************
		//printf("Reading setup...\n");
		char *lpSQL = "select hex(ifnull(adminIp,0)), hex(ifnull(internalIP,0)), hex(ifnull(nettmask,0)), if (handled,1,0), ifnull(blockIncomingTaggedTrafficThreshold,0), if(showStatus,1,0) as showStatus, if (showPreRoutePartner,1,0), if (showPreRouteNonPartner,1,0), if (showForwardPartner,1,0), if (showForwardNonPartner,1,0), if (showUrgentPtrUsage,1,0), if (showOwnerless,1,0), if (showOther,1,0), if (showNew1,1,0), if (showNew2,1,0), if (doTagging,1,0), if (doReportTraffic,1,0), if (doInspection,1,0), if (doBlocking,1,0), if (doOther,1,0)  from setup";
		
		if (mysql_query(conn, lpSQL)) {
			fprintf(stderr, "taralink: %s\n", mysql_error(conn));
    	                reportErrorReadin("setup");
			return 0;
		}

		res = mysql_use_result(conn);
		
		if ((row = mysql_fetch_row(res)) != NULL)
		{
			if (!bReadChangesOnly || !atoi(row[3]))
			{
  			        bFoundData = 1;
				union _showStatusBitsUnion cShowStatusBits;
				cShowStatusBits.nValues = 0; //Initialize the whole union / structure
				//cShowStatusBits.bits.nDummy = 0;
				int nField = 5;
				cShowStatusBits.bits.showStatus  = atoi(row[nField++]);
				cShowStatusBits.bits.showPreRoutePartner  = atoi(row[nField++]);
				cShowStatusBits.bits.showPreRouteNonPartner  = atoi(row[nField++]);
				cShowStatusBits.bits.showForwardPartner  = atoi(row[nField++]);
				cShowStatusBits.bits.showForwardNonPartner  = atoi(row[nField++]);
				cShowStatusBits.bits.showUrgentPtrUsage  = atoi(row[nField++]);
				cShowStatusBits.bits.showOwnerless  = atoi(row[nField++]);
				cShowStatusBits.bits.showOther  = atoi(row[nField++]);
				cShowStatusBits.bits.showNew1  = atoi(row[nField++]);
				cShowStatusBits.bits.showNew2  = atoi(row[nField++]);
				cShowStatusBits.bits.doTagging  = atoi(row[nField++]);
				cShowStatusBits.bits.doReportTraffic = atoi(row[nField++]);
				cShowStatusBits.bits.doInspection  = atoi(row[nField++]);
				cShowStatusBits.bits.doBlocking  = atoi(row[nField++]);
				cShowStatusBits.bits.doOther  = atoi(row[nField++]);

                                unsigned int  nBlockingThreshold = atoi(row[4]);
				sprintf(cReply+strlen(cReply), "SETUP|%s^%s^%s^%01X^%02X^|", row[0], row[1], row[2], nBlockingThreshold, cShowStatusBits.nValues);
					//strcpy(cReply+strlen(cReply), "SETUP|");
					//strcpy(cReply+strlen(cReply), row[0]);
					//strcpy(cReply+strlen(cReply), "|");
				printf("Setup added now : %s^%s^%s\n", row[0], row[1], row[2]);
 				if (!atoi(row[3])) {
					printf("Setting setup as handled..\n");
					if (mysql_query(updateConn, "update setup set handled = b'1'")) {
						fprintf(stderr, "%s\n", mysql_error(updateConn));
						addWarningRecord("****** ERROR Error updating setup handled field (meaning it will read again)");
						return 0;
					}
			  	}
			}  
			//else
			//	printf("Not adding setup.. handled was: %s\n", row[13]);
		}
		else
		{
		        unsigned long nMinutes = minutesSincePing(); 
		        if (nMinutes >= 10)
		        {
		              setPing();
    		              /*
    		              char szUrl[255];
		              strcpy(szUrl, "http://81.88.19.252/script/config_update.php?f=ping&status=Unable_to_read_setup");
		              *szWgetBuff = 0;
		              wget(szUrl, szWgetBuff, sizeof(szWgetBuff));  //Using global static buffers because reply doesn't come immediately.
		              //printf("%s\n", szUrl);
		              */
		        }
	              printf("Minutes: %lu (%s)\n", nMinutes, szWgetBuff);
			printf("************ ERROR! Unable to read the setup\n");
		}
			
		//printf("Freeing up connections\n");
    		mysql_free_result(res);

                //***************** Finish it up 
		strcpy(cReply+strlen(cReply), "EOF");

		/* close connection */
		mysql_close(conn);
		mysql_close(updateConn);

		//This is the hard coding.. Replace with data read from server above.
		//sprintf(cReply, "%d|192.168.1.20:8080-clean^192.168.1.20:64-nobot", nSequenceNumber); 
	}
	else
		sprintf(cReply, "%d|EOF", nSequenceNumber); //For now only handles one sequence.. but will requri more in future....

        nThreadId = syscall(SYS_gettid);//sys_gettid(); // //gettid()
        //printf("Setup before sending: %s\n", cReply); 
        
        if (bFoundData)
        {
        	sendMessage(pSockData, cReply);
		printf("Configuration sent(%ld chars): %s.\nPreparing to read again\n", strlen(cReply), cReply);
		return 1; //Did send data
	}
	else
		printf("Configuration is unchanged.\n");
	
	
	return 0;
      #endif
}
