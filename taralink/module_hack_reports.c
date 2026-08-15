//module_hack_reports.c

typedef struct  {
  char ip[3][30];
} _GlobalServers;

#include <arpa/inet.h>
#include <curl/curl.h>
#include <ctype.h>

typedef struct {
    char *buf;
    size_t capacity;
    size_t used;
} _ReportHttpBuffer;

static size_t reportHttpWriteCallback(char *ptr, size_t size, size_t nmemb, void *userdata)
{
    size_t bytes = size * nmemb;
    _ReportHttpBuffer *target = (_ReportHttpBuffer *)userdata;

    if (!target || !target->buf || target->capacity == 0)
        return bytes;

    size_t available = target->capacity - 1 - target->used;
    size_t copyBytes = (bytes < available ? bytes : available);

    if (copyBytes > 0) {
        memcpy(target->buf + target->used, ptr, copyBytes);
        target->used += copyBytes;
        target->buf[target->used] = 0;
    }

    return bytes;
}

static void trimHttpReply(char *reply)
{
    if (!reply)
        return;

    char *start = reply;
    while (*start && isspace((unsigned char)*start))
        start++;

    if (start != reply)
        memmove(reply, start, strlen(start) + 1);

    size_t len = strlen(reply);
    while (len > 0 && isspace((unsigned char)reply[len - 1]))
        reply[--len] = 0;
}

static int reportHttpGetOk(const char *url, char *reply, size_t replySize)
{
    if (!url || !reply || replySize == 0)
        return 0;

    reply[0] = 0;
    _ReportHttpBuffer target = { reply, replySize, 0 };

    curl_global_init(CURL_GLOBAL_DEFAULT);
    CURL *curl = curl_easy_init();
    if (!curl) {
        snprintf(reply, replySize, "curl init failed");
        return 0;
    }

    curl_easy_setopt(curl, CURLOPT_URL, url);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, reportHttpWriteCallback);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &target);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 5L);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);

    CURLcode result = curl_easy_perform(curl);
    long httpCode = 0;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &httpCode);
    curl_easy_cleanup(curl);

    trimHttpReply(reply);

    if (result != CURLE_OK) {
        snprintf(reply, replySize, "curl error: %s", curl_easy_strerror(result));
        return 0;
    }

    if (httpCode < 200 || httpCode >= 300) {
        char body[300];
        snprintf(body, sizeof(body), "%s", *reply ? reply : "no response body");
        snprintf(reply, replySize, "HTTP %ld: %.220s", httpCode, body);
        return 0;
    }

    if (strcmp(reply, "ok")) {
        char body[300];
        snprintf(body, sizeof(body), "%s", *reply ? reply : "empty response");
        snprintf(reply, replySize, "unexpected reply: %.220s", body);
        return 0;
    }

    return 1;
}

static void setTaralinkSystemError(const char *message, unsigned int severity)
{
    if (!message)
        return;

    MYSQL *errorConn = getConnection();
    if (!errorConn)
        return;

    char shortened[251];
    snprintf(shortened, sizeof(shortened), "%.250s", message);

    char escaped[520];
    mysql_real_escape_string(errorConn, escaped, shortened, strlen(shortened));

    char sql[800];
    snprintf(sql, sizeof(sql),
             "update setup set systemError='%s', systemErrorSeverity=%u, systemErrorSet=now()",
             escaped, severity);

    if (mysql_query(errorConn, sql))
        fprintf(stderr, "Unable to store systemError: %s\n", mysql_error(errorConn));

    mysql_close(errorConn);
}

static void clearHackReportDeliverySystemError(void)
{
    MYSQL *errorConn = getConnection();
    if (!errorConn)
        return;

    const char *sql =
        "update setup set systemError=null, systemErrorSeverity=null, systemErrorSet=null "
        "where systemError like 'Hack report delivery failed:%'";

    if (mysql_query(errorConn, sql))
        fprintf(stderr, "Unable to clear hack-report systemError: %s\n", mysql_error(errorConn));

    mysql_close(errorConn);
}

static int sendReportToGlobalDbServersVerified(
    _GlobalServers *cGlobalDb,
    const char *szParams,
    const char *cMyIp,
    char *errorBuf,
    size_t errorBufSize)
{
    int allOk = 1;

    if (errorBuf && errorBufSize)
        errorBuf[0] = 0;

    for (int n = 0; n < 3; n++) {
        const char *globalIp = cGlobalDb->ip[n];
        if (!globalIp || !*globalIp || strlen(globalIp) < 7)
            continue;

        if (cMyIp && !strcmp(globalIp, cMyIp))
            continue;

        char url[700];
        snprintf(url, sizeof(url), "http://%s/script/report.php?%s", globalIp, szParams);

        char reply[500];
        if (!reportHttpGetOk(url, reply, sizeof(reply))) {
            allOk = 0;
            if (errorBuf && errorBufSize)
                snprintf(errorBuf, errorBufSize,
                         "global DB %s rejected report: %.300s", globalIp, reply);
        }
    }

    return allOk;
}

uint32_t getIpOfRegisteredPartnerRouter(
    MYSQL *conn,
    uint32_t ip,
    char *szRouterIp,
    size_t nBufSize
) {
    const char *lpSQL =
        "SELECT ip, INET_NTOA(ip) "
        "FROM partnerRouter "
        "WHERE (? & nettmask) = (ip & nettmask) "
        "ORDER BY BIT_COUNT(nettmask) DESC "
        "LIMIT 1";

    MYSQL_STMT *stmt = mysql_stmt_init(conn);
    if (!stmt) {
        printf("Could not initialize statement\n");
        return 0;
    }

    int status = mysql_stmt_prepare(stmt, lpSQL, strlen(lpSQL));
    test_stmt_error(stmt, status);

    MYSQL_BIND param[1];
    memset(param, 0, sizeof(param));

    param[0].buffer_type = MYSQL_TYPE_LONG;
    param[0].buffer = &ip;
    param[0].buffer_length = sizeof(ip);
    param[0].is_unsigned = 1;

    status = mysql_stmt_bind_param(stmt, param);
    test_stmt_error(stmt, status);

    status = mysql_stmt_execute(stmt);
    test_stmt_error(stmt, status);

    uint32_t nRouterIp = 0;
    unsigned long nIpLen = 0;
    my_bool is_null[2] = {0, 0};

    MYSQL_BIND rec[2];
    memset(rec, 0, sizeof(rec));

    rec[0].buffer_type = MYSQL_TYPE_LONG;
    rec[0].buffer = &nRouterIp;
    rec[0].buffer_length = sizeof(nRouterIp);
    rec[0].is_unsigned = 1;
    rec[0].is_null = &is_null[0];

    rec[1].buffer_type = MYSQL_TYPE_STRING;
    rec[1].buffer = szRouterIp;
    rec[1].buffer_length = nBufSize;
    rec[1].length = &nIpLen;
    rec[1].is_null = &is_null[1];

    status = mysql_stmt_bind_result(stmt, rec);
    test_stmt_error(stmt, status);

    status = mysql_stmt_fetch(stmt);

    if (status == MYSQL_NO_DATA) {
        printf("No router found for this ip\n");
        nRouterIp = 0;
        if (nBufSize > 0)
            szRouterIp[0] = '\0';
    } else if (status != 0 && status != MYSQL_DATA_TRUNCATED) {
        test_stmt_error(stmt, status);
    }

    mysql_stmt_close(stmt);
    return nRouterIp;
}

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
						char szUrl[400];
						char szWgetBuff[2000];
						snprintf(szUrl, sizeof(szUrl), "http://%s/script/config_update.php?%s", lpGlobalDbIp, szParams);
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
	if (!nMyIp || !nNettmask)
		return 0;

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

	char *lpSql = "select adminIP, inet_ntoa(adminIP), inet_ntoa(globalDb1ip), inet_ntoa(globalDb2ip), inet_ntoa(globalDb3ip), nettmask, internalIP from setup"; 
	//printf("About to query\n");
	if (mysql_query(conn, lpSql)) {
		fprintf(stderr, "**** ERROR ******* While finding setup: %s\n", mysql_error(conn));
		addWarningRecord("**** ERROR ******* While finding setup");
		printf("DB query error. Aborting.\n");
		return;
	}
	res = mysql_use_result(conn);
	row = mysql_fetch_row(res);
	//printf("After fetch_row\n");
	nMyIp = (row[0]?atoi(row[0]):0);
	uint32_t nInternalIp = (row[6]?atoi(row[6]):0);
	nNettmask = (row[5]?atoi(row[5]):0);
	strcpy(cMyIp, row[1]);
	for (int n=0; n < 3; n++)
	{
	  char *lpDest = (&cGlobalDb)->ip[n]; 
	  strcpy(lpDest, (row[2+n]?row[2+n]: ""));
	}
	//strcpy(szGlobalDb1, (row[2]row[2]);
	//strcpy(szGlobalDb2, row[3]);
	//strcpy(szGlobalDb3, row[4]);
	
	//printf("Freeing result\n");
    mysql_free_result(res);
	
	//NOTE! Not checking hackReports regarding units in our network until 10 seconds later to give the system the chance to import recent port assignments
	sprintf(szSQL, "select reportId, ip, port, inet_ntoa(ip), created, TIMESTAMPDIFF(SECOND, created, NOW()) as SecondsSince, sendAttemptCount, inet_ntoa(sentByIp), why from hackReport where handledTime is null and (ip <> %u or created < DATE_SUB(NOW(), INTERVAL 10 SECOND))", nMyIp);

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
		char cWhat[256];
		*cWhat = 0;
	    if (atoi(row[6]) > 10)
        {
			setHackReportAsHandled("Aborted (timed out 10 times)", atoi(row[0]));
            continue;
	    }
	        
		printf("Hack report %s, %s %s:%s, sent by: %s, count: %s, wt: %s\n", row[0], row[4], row[3], row[2], row[7], row[6], row[8]);

		if (updateConn == NULL)
			updateConn = getConnection();
            
		u_int32_t nNumericIp = (row[1]?atoi(row[1]):0);
		u_int32_t nInfectionId = 0;
		u_int32_t nUnitId = 0;
		char cSQL[400];
		int bUpdateHandled = 1;   //By default update the handled field after handling...
		char *lpIp = (row[3]?row[3]:"(null)");
		u_int32_t nInternaIpFromUnitPort = 0;

		if (isMeOrMine(nNumericIp, nInternalIp, nNettmask))	//260223 - Changed from nMyIP (adminIP) to nInternalIp for determining if isMeOrMine()
		{
			strcpy(cWhat, "Me or my unit. ");
			printf("Me or my unit caused hackReport to be filed..\n");

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
				strcpy(cWhat, "NAT'ed unit. ");
				//This is a hacking report regarding one of my NAT'ed units.. Find what unit it was based on 
				printf("This is a hacking report regarding one of my units.. Find what unit it was based on\n"); 
				//the port and put in internalInfections table
		
				//OT 250212 - Seems like this SQL did not select the most 
				sprintf(szSQL, "select portAssignmentId, UP.created, ifnull(U.unitId,0), UP.ipAddress, description, dhcpClientId, vci, hostname, inet_ntoa(UP.ipAddress) from unitPort UP join unit U on U.unitId = UP.unitId where port = %s order by portAssignmentId desc limit 1", row[2]); 
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
					printf("Hackreport %s port %s is %s %s %s %s %s\n", row[4], row[2], lookupRow[8], lookupRow[4], lookupRow[5], lookupRow[6], lookupRow[7]); 
					if (!lookupConn)
						lookupConn = getConnection();

					printf("Setting ip\n");
					nInternaIpFromUnitPort = (lookupRow[3]?atoi(lookupRow[3]):0);
					printf("ip set\n");
                                
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
					printf("Freeing\n");
				}
				else
				{
					printf("No port assignment found for %s:%s (should check conntrack?).\n", lpIp, row[2]);
				}
				printf("freeing lookupRes\n");
				mysql_free_result(lookupRes);
				printf("lookupRes freed\n");
			}

			printf("getting connection\n");
			localUpdate = getConnection();
			printf("got the connection\n");

			if (nInfectionId > 0)
			{
				printf("Updating internalInfections record\n");
				strncpy(cWhat, "Already reg as infected. ", sizeof(cWhat) - strlen(cWhat));
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
				printf("Creating new internalInfections record for %s:%s (internal ip: %u).\n", lpIp, (row[2]?row[2]:"(null)"), nInternaIpFromUnitPort);
				strncpy(cWhat, "Not reg as infected. ", sizeof(cWhat) - strlen(cWhat));

				//Check if special case... 
				char *lpWhat = row[8]?row[8]:"";
				if (strstr(lpWhat, "sinkhole"))
				{
					snprintf(cWhat, sizeof(cWhat) - strlen(cWhat), "Sinkhole accessed. ");
				}

				//This IP is not yet registered in internalInfections. Put it there.
				//(those are the IP addresses that will be sent to tarakernel and be subject to tagging and blocking).
				//NOTE! Check first if NAT'ed subnet. If so, store true IP.
				if (nInternaIpFromUnitPort)
				{
					nNumericIp = nInternaIpFromUnitPort;
					printf("Storing ip from unitPort as in (%d)", nInternaIpFromUnitPort);
				}
				//What about port? Should the original port of the NAT port be stored.. It's normally the same number if few units (name number is available)..

				sprintf(cSQL, "insert into internalInfections (ip, nettmask, status, unitId, severity, why) values (%d, inet_aton('255.255.255.255'), 'firsttime', %d, 7, ?)", nNumericIp, nUnitId);

			    MYSQL_STMT *stmt = mysql_stmt_init(localUpdate);
    			if (!stmt) {
        			printf("Could not initialize statement\n");
        			return;
    			}

				printf("Preparing statement.\n");
			    int status = mysql_stmt_prepare(stmt, cSQL, strlen(cSQL));
    			test_stmt_error(stmt, status);

    			MYSQL_BIND param[1];
    			memset(param, 0, sizeof(param));
			    unsigned long nWhatLen = strlen(cWhat);

    			param[0].buffer_type = MYSQL_TYPE_STRING;
    			param[0].buffer = cWhat;
			    param[0].buffer_length = sizeof(cWhat);
			    param[0].length = &nWhatLen;
    			param[0].is_unsigned = 0;

				printf("Binding statement.\n");
			    status = mysql_stmt_bind_param(stmt, param);
    			test_stmt_error(stmt, status);

				printf("Executing statement.\n");
    			status = mysql_stmt_execute(stmt);
    			test_stmt_error(stmt, status);

				mysql_stmt_close(stmt);

				lpHackReportStatus = "Infection registered";
				printf("New unit not yet registered as infected. Inserted now.\n");                                          
				/*if (mysql_query(localUpdate, cSQL)) {
					fprintf(stderr, "**** ERROR **** While inserting internalInfections: %s\n", mysql_error(localUpdate));
					addWarningRecord("******** ERROR ****** While updating internalInfections");
					return;
				}*/
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
			printf("Hack attempt from none of my units. Report back to ISP and global DB servers\n");

			char szRouterIp[50];
			if (!lookupConn)
				lookupConn = getConnection();

			uint32_t nRouterIp = getIpOfRegisteredPartnerRouter(lookupConn, nNumericIp, szRouterIp, sizeof(szRouterIp));
			char szParams[400], czCodedWhat[255];
			urlencode(row[8]?row[8]:"", czCodedWhat, sizeof(czCodedWhat));
			snprintf(szParams, sizeof(szParams), "ip=%s&port=%s&wt=%s&code=from_partner",
                     row[3]?row[3]:"", row[2]?row[2]:"", czCodedWhat);

            int deliveryFailed = 0;
            char deliveryError[600];
            *deliveryError = 0;

			if (nRouterIp)
			{
				printf("Found ISP: %s\n", szRouterIp);

				if (!strcmp(lpIp, szRouterIp) && (row[8] && !strcmp(row[8], "From traffic report.")))
				{
					printf("\nThis is hackReport based on tagged traffic report from ISP. No use sending it back to the same ISP (%s).. Skip\n\n", szRouterIp);
				}
				else
				{
					char szUrl[700];
                    char reply[500];
					snprintf(szUrl, sizeof(szUrl), "http://%s/script/report.php?%s", szRouterIp, szParams);
					printf("Sending ISP: %s\n", szUrl);

                    if (!reportHttpGetOk(szUrl, reply, sizeof(reply))) {
                        deliveryFailed = 1;
                        snprintf(deliveryError, sizeof(deliveryError),
                                 "partner %s rejected report: %.350s", szRouterIp, reply);
                    }
				}
   			}
			else
				printf("Not belonging to other router. Sending to global DB servers\n");

            char globalError[500];
            if (!sendReportToGlobalDbServersVerified(&cGlobalDb, szParams, cMyIp,
                                                     globalError, sizeof(globalError))) {
                deliveryFailed = 1;
                snprintf(deliveryError, sizeof(deliveryError), "%s", globalError);
            }

            if (deliveryFailed) {
                char systemError[700];
                snprintf(systemError, sizeof(systemError),
                         "Hack report delivery failed: %s:%s - %.450s",
                         row[3]?row[3]:"?", row[2]?row[2]:"?", deliveryError);
                setTaralinkSystemError(systemError, 7);
                addWarningRecord(systemError);
                increaseSendAttemptCount(atoi(row[0]));
                bUpdateHandled = 0;
            } else {
                clearHackReportDeliverySystemError();
            }
		}

		if (bUpdateHandled) {
			if (!*cWhat)			
				strcpy(cWhat, "FirstTime");

		    setHackReportAsHandled(cWhat, atoi(row[0]));
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

