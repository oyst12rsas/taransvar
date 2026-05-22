//module_hack_reports.c

typedef struct  {
  char ip[3][30];
} _GlobalServers;

#include <arpa/inet.h>

void sendToGlogalDbServers(_GlobalServers *cGlobalDb, char *szParams, uint32_t nMyIp, char *cMyIp);
void sendToGlogalDbServers(_GlobalServers *cGlobalDb, char *szParams, uint32_t nMyIp, char *cMyIp)
{
	for (int n = 0; n < 3; n++)
	{
    	//char *lpGlobalDbIp; //No idea why this isn't working: szGlobalDb[n];
    	char *lpGlobalDbIp = cGlobalDb->ip[n];

		if (strlen(lpGlobalDbIp))
		{
			struct in_addr addr;
			uint32_t nGlobalDbIp;

			if (inet_aton(lpGlobalDbIp, &addr)) {
				nGlobalDbIp = addr.s_addr;
	    		// success
			} else {
    			// invalid IP
				nGlobalDbIp = 0;
				printf("************************* Invalid ip: %s (skipping)\n", lpGlobalDbIp);
			}

			if (nGlobalDbIp)
			{
				//if (nGlobalDbIp != nMyIp)	- for some reason these are different... didn't debug, using strings instead...
				if (strcmp(lpGlobalDbIp, cMyIp))
				{
	    			if (lpGlobalDbIp && strlen(lpGlobalDbIp) > 7)
	    			{
						printf("About to send to global DB server: %s (me: %s)\n", lpGlobalDbIp, cMyIp);
						char szUrl[255];
						char szWgetBuff[2000];
						sprintf(szUrl, "http://%s/script/%s", lpGlobalDbIp, szParams);
						*szWgetBuff = 0;
						printf("Sending request: %s\n", szUrl);
						wget(szUrl, szWgetBuff, sizeof(szWgetBuff));  //Using global static buffers because reply doesn't come immediately.
	    			} else {
    					char szBuf[256];
	    				if (lpGlobalDbIp && *lpGlobalDbIp)
    						printf("****** Skipping wrong IP address for global DB server: %s\n", lpGlobalDbIp);
      					//addWarningRecord(conn, szBuf);
					}
				}
				else
					printf("******** WARNING ******** Skipping sending to myself: %s\n", lpGlobalDbIp);
			}
		}
	}
}

void setHackReportAsHandled(char *lpStatus, int nHackReportId);
void setHackReportAsHandled(char *lpStatus, int nHackReportId)
{
        MYSQL *conn = getConnection();
        char szSQL[200];
	sprintf(szSQL, "update hackReport set handledTime = now(), status = '%s' where reportId = %d", lpStatus, nHackReportId);
	printf("Updating DB: %s\n", szSQL);
	if (mysql_query(conn, szSQL)) {
      		fprintf(stderr, "********** ERROR ******** Updating hackReport: %s\n", mysql_error(conn));
		addWarningRecord("******** ERROR ****** Taralink: While updating hackReport");
	}
	mysql_close(conn);
}

void increaseSendAttemptCount(int nHackReportId);
void increaseSendAttemptCount(int nHackReportId)
{
  MYSQL *conn = getConnection();
  char szSQL[100];
  sprintf(szSQL, "update hackReport set sendAttemptCount = sendAttemptCount + 1 where reportId = %d", nHackReportId);  
  mysql_query(conn, szSQL);
  mysql_close(conn);
}

int isMeOrMine(uint32_t nIp, uint32_t nMyIp, uint32_t nNettmask)
{
	return ((nIp & nNettmask) == (nMyIp & nNettmask));
}

void checkHackReports()
{
	//Checks if there's reported attacks by units in our network  
	MYSQL *conn, *updateConn, *lookupConn, *localUpdate;
	MYSQL_RES *res;
	MYSQL_ROW row;

	char *lpHackReportStatus = "updated";

	//printf("About to check hack reports\n");

	conn = getConnection();
	//printf("Got connected to DB\n");
	updateConn = 0;
	lookupConn = 0;
	localUpdate = 0;
	uint32_t nMyIp;
	uint32_t nNettmask;
	char cMyIp[20];
	char szSQL[400]; 
	_GlobalServers cGlobalDb;
	//char szGlobalDb1[30];
	//char szGlobalDb2[30];
	//char szGlobalDb3[30];

	char *lpSql = "select adminIP, inet_ntoa(adminIP), inet_ntoa(globalDb1ip), inet_ntoa(globalDb2ip), inet_ntoa(globalDb3ip), nettmask from setup"; 
	if (mysql_query(conn, lpSql)) {
		fprintf(stderr, "**** ERROR ******* While finding setup: %s\n", mysql_error(conn));
		addWarningRecord("**** ERROR ******* While finding setup");
		return;
	}
	res = mysql_use_result(conn);
	row = mysql_fetch_row(res);
	nMyIp = atoi(row[0]);
	nNettmask = atoi(row[5]);
	strcpy(cMyIp, row[1]);
	for (int n=0; n < 3; n++)
	{
	  char *lpDest = (&cGlobalDb)->ip[n]; 
	  strcpy(lpDest, (row[2+n]?row[2+n]: ""));
	}
	//strcpy(szGlobalDb1, (row[2]row[2]);
	//strcpy(szGlobalDb2, row[3]);
	//strcpy(szGlobalDb3, row[4]);
	
    mysql_free_result(res);
	
	//NOTE! Not checking hackReports regarding units in our network until 10 seconds later to give the system the chance to import recent port assignments
	sprintf(szSQL, "select reportId, ip, port, inet_ntoa(ip), created, TIMESTAMPDIFF(MINUTE, created, NOW()) as MinutesSince, sendAttemptCount, inet_ntoa(sentByIp) from hackReport where handledTime is null and (ip <> %u or created < DATE_SUB(NOW(), INTERVAL 10 SECOND))", nMyIp);

	if (mysql_query(conn, szSQL)) {
		fprintf(stderr, "**** ERROR *** While fetching hackReports: %s\n", mysql_error(conn));
		addWarningRecord("**** ERROR ******* While fetching hackReports");
		return;
	}
	
	//printf("********************** Processing hackReports ***********\n");
	
	res = mysql_use_result(conn);
	//printf("About to traverse the rows\n");

	while ((row = mysql_fetch_row(res)) != NULL)
	{
	    if (atoi(row[6]) > 10)
        {
			setHackReportAsHandled("Aborted (timed out 10 times)", atoi(row[0]));
            continue;
	    }
	        
		printf("Hack report %s, %s %s:%s, sent by: %s, count: %s\n", row[0], row[4], row[3], row[2], row[7], row[6]);

		if (updateConn == NULL)
			updateConn = getConnection();
            
		u_int32_t nNumericIp = (row[1]?atoi(row[1]):0);
		u_int32_t nInfectionId = 0;
		u_int32_t nUnitId = 0;
		char cSQL[400];
		int bUpdateHandled = 1;   //By default update the handled field after handling...

		if (isMeOrMine(nNumericIp, nMyIp, nNettmask))
		{

			if (nNumericIp != nMyIp)
			{
				//Child unit without NAT
				sprintf(szSQL, "select U.unitId, infectionId from unit U left outer join internalInfections I on I.unitId = U.unitId where ipAddress = %d  order by infectionId desc, U.unitId desc", nNumericIp);
				printf ("System thinks it's local unit without NAT. Maybe report from partner that threat info changed?):\n%s\n", szSQL);
				if (mysql_query(updateConn, szSQL)) {
					fprintf(stderr, "****** ERROR ***** While finding port assignment: %s\n", mysql_error(updateConn));
					return;
				}
			
				MYSQL_RES *lookupRes = mysql_use_result(updateConn);
				if (!lookupRes) {
					printf("******* ERROR ***** Fetching unit and infection.\n");
					return;
				}

				MYSQL_ROW lookupRow = mysql_fetch_row(lookupRes);
				if (lookupRow){
					nUnitId = (lookupRow[0]?atoi(lookupRow[0]):0);
					nInfectionId = (lookupRow[1]?atoi(lookupRow[1]):0);
					printf("Found infection (%d) and unit(%d)\n", nInfectionId, nUnitId);
				}
				mysql_free_result(lookupRes);
			}
			else
			{
				//This is a hacking report regarding one of my units.. Find what unit it was based on 
				printf("This is a hacking report regarding one of my units.. Find what unit it was based on\n"); 
				//the port and put in internalInfections table
		
				//OT 250212 - Seems like this SQL did not select the most 
				sprintf(szSQL, "select portAssignmentId, UP.created, ifnull(U.unitId,0), UP.ipAddress, description, dhcpClientId, vci, hostname from unitPort UP join unit U on U.unitId = UP.unitId where port = %s order by portAssignmentId desc limit 1", row[2]); 
				//printf ("SQL: %s\n", szSQL);
				if (mysql_query(updateConn, szSQL)) {
					fprintf(stderr, "****** ERROR ***** While finding port assignment: %s\n", mysql_error(updateConn));
					return;
				}
			
				MYSQL_RES *lookupRes = mysql_use_result(updateConn);
				MYSQL_ROW lookupRow = mysql_fetch_row(lookupRes);
				if (lookupRow)
				{
					nUnitId = atoi(lookupRow[2]);
					printf("Hackreport %s port %s is %s %s %s %s %s\n", row[4], row[2], lookupRow[3], lookupRow[4], lookupRow[5], lookupRow[6], lookupRow[7]); 
					if (!lookupConn)
						lookupConn = getConnection();
                                
					//Hacking report found on one of our connected units.
					//Check if this address is already registered. Get the last one if several and check if not different unit.. 
					//**** NOTE! This table should reflect changes in IP address..  
					sprintf(cSQL, "select infectionId, unitId, handled, inserted, status from internalInfections where ip = %s and (unitId is null or unitId = %s) order by infectionId desc limit 1", lookupRow[3], lookupRow[2]);
					if (mysql_query(lookupConn, cSQL)) {
						fprintf(stderr, "****** ERROR ******* While finding port assignment: %s\n", mysql_error(lookupConn));
						return;
					}
                                
					MYSQL_RES *lookupRes2 = mysql_use_result(lookupConn);
					MYSQL_ROW lookupRow2 = mysql_fetch_row(lookupRes2);
					if (lookupRow2)
						nInfectionId = atoi(lookupRow2[0]);

					mysql_free_result(lookupRes2);
				}
				mysql_free_result(lookupRes);
			}

			localUpdate = getConnection();

			if (nInfectionId > 0)
			{
				//NOTE! 260312 - Even though it's in the internalInfections table, it may be deactivated... so don't put it back in active state here...
				//This IP is already registered in internalInfections. Update it (those are the IP  
				//addresses that will be sent to tarakernel and be subject to tagging and blocking). 
				//sprintf(cSQL, "update internalInfections set unitId = %s, lastSeen = now(), active = 1, handled = null where infectionId = %s", lookupRow[2], lookupRow2[0]);
				sprintf(cSQL, "update internalInfections set unitId = %d, lastSeen = now() where infectionId = %d", nUnitId, nInfectionId);
				lpHackReportStatus = "infection->lastSeen updated";
				printf("Already in internalInfections, update it (NOTE! Was setting to active - which may be a problem...).\n");                                          
						
				if (mysql_query(localUpdate, cSQL)) {
					fprintf(stderr, "******** ERROR ****** While updating internalInfections: %s\n", mysql_error(localUpdate));
					addWarningRecord("******** ERROR ****** While updating internalInfections");
					return;
				}
        	          		
			} else {
				//This IP is not yet registered in internalInfections. Put it there.
				//(those are the IP addresses that will be sent to tarakernel and be subject to tagging and blocking).
				sprintf(cSQL, "insert into internalInfections (ip, nettmask, status, unitId) values (%d, inet_aton('255.255.255.255'), 'firsttime', %d)", nNumericIp, nUnitId);
				lpHackReportStatus = "Infection registered";
				printf("New unit not yet registered as infected. Inserted now.\n");                                          
				if (mysql_query(localUpdate, cSQL)) {
					fprintf(stderr, "**** ERROR **** While inserting internalInfections: %s\n", mysql_error(localUpdate));
					addWarningRecord("******** ERROR ****** While updating internalInfections");
					return;
				}
			}
				
			if (nUnitId)
			{
				sprintf(cSQL, "update hackReport set unitId = %d where reportId = %d", nUnitId, atoi(row[0]));
				//printf("******** Updating unitId: %s\n", cSQL);
				if (mysql_query(localUpdate, cSQL)) {
					fprintf(stderr, "******** ERROR ****** While updating hackReport: %s\n", mysql_error(localUpdate));
					addWarningRecord("******** ERROR ****** While updating hackReport");
					return;
				}
			}
				
			//*************** Send message to global DB servers that one of our units reported infected 
			char szParams[200];
			sprintf(szParams, "config_update.php?f=confession&ip=%s&port=%s&ourid=%d", row[3], row[2], nUnitId);
			sendToGlogalDbServers(&cGlobalDb, szParams, nMyIp, cMyIp);

			sprintf(cSQL, "update hackReport set sentGlobalDB = now(), status = concat(status, '(confessed)') where reportId = %d", atoi(row[0]));
				
			if (mysql_query(localUpdate, cSQL)) {
				fprintf(stderr, "******** ERROR ****** While updating hackReport: %s\n", mysql_error(localUpdate));
				addWarningRecord("******** ERROR ****** While updating hackReport");
				return;
			}
		}//me or mine
		else
		{
			//Not mine.... 
			char szBuffer[1000];
			sprintf(szBuffer, "** WARNING ** : Hackreport %s port %s: No matching port assignment found\n", row[4], row[2]);
			if (atoi(row[5]) < 5)
			{
				strcpy(szBuffer+strlen(szBuffer), ", but just received.. So waiting before setting to handled...\n");
				bUpdateHandled = 0;   //Don't set at handled yet.. Waiting for port assignments to be imported by misc/conntrack.pl and/or process_dhcpdump.pl (hopefully running as cron job)
			}
			else
				sprintf(szBuffer+strlen(szBuffer), "****** ERROR ******* And %s minutes since received.. So setting to handled...\n", row[5]);
                 	                
			printf("%s",szBuffer);
			addWarningRecord(szBuffer);
		}

		if (bUpdateHandled) {
		    setHackReportAsHandled("firstTime", atoi(row[0]));
		}
	}//while more records.

	//printf("About to close databases\n");

	if (updateConn)
		mysql_close(updateConn);
	if (lookupConn)
		mysql_close(lookupConn);
	if (localUpdate)
		mysql_close(localUpdate);
	
	mysql_free_result(res);
	mysql_close(conn);
}



