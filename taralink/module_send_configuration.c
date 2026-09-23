#define MAX_INFECTIONS	100
#define MAX_COLORLISTINGS 100
#define MAX_SETUP_RECORDS 100
#define PACKET_LEN_SAFETY_BUFFER	100

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

/*
 * DEMO_NODES are gateway-local destinations.  They are sent to tarakernel as
 * partner destinations for packet tagging, but are deliberately not inserted
 * into partnerRouter and therefore do not become production trust records.
 */
static int appendDemoPartnersFromConfig(char *reply, size_t replySize)
{
    FILE *config = fopen("/etc/tarasecfw.conf", "r");
    char line[2048];
    char value[2048] = "";
    int added = 0;

    if (!config)
        return 0;

    while (fgets(line, sizeof(line), config)) {
        char *p = line;
        char *end;

        while (*p == ' ' || *p == '\t')
            p++;
        if (strncmp(p, "DEMO_NODES", strlen("DEMO_NODES")) != 0)
            continue;

        p += strlen("DEMO_NODES");
        while (*p == ' ' || *p == '\t')
            p++;
        if (*p++ != '=')
            continue;
        while (*p == ' ' || *p == '\t')
            p++;

        strncpy(value, p, sizeof(value) - 1);
        value[sizeof(value) - 1] = '\0';
        end = value + strlen(value);
        while (end > value && (end[-1] == '\n' || end[-1] == '\r' ||
               end[-1] == ' ' || end[-1] == '\t'))
            *--end = '\0';
        if (value[0] == '"' || value[0] == '\'') {
            char quote = value[0];
            memmove(value, value + 1, strlen(value));
            end = strrchr(value, quote);
            if (end)
                *end = '\0';
        }
        break;
    }
    fclose(config);

    if (!value[0])
        return 0;

    {
        char *save = NULL;
        char *token = strtok_r(value, ",", &save);

        while (token) {
            unsigned int a, b, d, e;
            char extra;
            char entry[32];
            char *start = token;
            char *finish;

            while (*start == ' ' || *start == '\t')
                start++;
            finish = start + strlen(start);
            while (finish > start && (finish[-1] == ' ' || finish[-1] == '\t'))
                *--finish = '\0';

            if (sscanf(start, "%u.%u.%u.%u%c", &a, &b, &d, &e, &extra) == 4 &&
                a <= 255 && b <= 255 && d <= 255 && e <= 255) {
                unsigned int ip = (a << 24) | (b << 16) | (d << 8) | e;
                snprintf(entry, sizeof(entry), "%08X:FFFFFFFF^", ip);

                if (!strstr(reply, entry)) {
                    size_t needed = strlen(reply) + strlen(entry) +
                                    (added == 0 ? strlen("PARTNER|") : 0) + 2;
                    if (needed >= replySize) {
                        fprintf(stderr, "DEMO_NODES partner list exceeds configuration buffer\n");
                        break;
                    }
                    if (added == 0)
                        strcat(reply, "PARTNER|");
                    strcat(reply, entry);
                    printf("Demo partner found: %s/32\n", start);
                    added++;
                }
            } else {
                fprintf(stderr, "Ignoring invalid DEMO_NODES address: %s\n", start);
            }
            token = strtok_r(NULL, ",", &save);
        }
    }

    if (added)
        strcat(reply, "|");
    return added;
}


/*
 * Demo 4's authorized destination is explicitly set by the gateway operator
 * in the same root-owned file used by the policy route. Send it to tarakernel
 * as a kernel-only /32 partner; never insert it into the local partnerRouter
 * table, whose entries carry wider production trust and expire via cron.
 * Only an IPv4 host route is accepted.
 */
static int appendDemo4DestinationFromConfig(char *reply, size_t replySize)
{
    FILE *config = fopen("/etc/tarasec/demo4-wireguard-hotspot.conf", "r");
    char line[512];
    int added = 0;

    if (!config)
        return 0;

    while (fgets(line, sizeof(line), config)) {
        char *p = line;
        char *end;
        unsigned int a, b, c, d, prefix;
        char extra;
        char entry[32];
        size_t needed;

        while (*p == ' ' || *p == '\t')
            p++;
        if (strncmp(p, "PARTNER_DESTINATION", 19) != 0)
            continue;
        p += 19;
        while (*p == ' ' || *p == '\t')
            p++;
        if (*p++ != '=')
            continue;
        while (*p == ' ' || *p == '\t')
            p++;

        end = p + strlen(p);
        while (end > p && (end[-1] == '\n' || end[-1] == '\r' ||
                           end[-1] == ' ' || end[-1] == '\t'))
            *--end = '\0';
        if ((*p == '"' || *p == '\'') && end > p + 1 && end[-1] == *p) {
            end[-1] = '\0';
            p++;
        }

        if (sscanf(p, "%u.%u.%u.%u/%u%c", &a, &b, &c, &d, &prefix, &extra) != 5 ||
            a > 255 || b > 255 || c > 255 || d > 255 || prefix != 32) {
            fprintf(stderr, "Ignoring invalid Demo 4 destination (requires IPv4 /32)\n");
            break;
        }

        snprintf(entry, sizeof(entry), "%08X:FFFFFFFF^",
                 (a << 24) | (b << 16) | (c << 8) | d);
        if (strstr(reply, entry))
            break;
        needed = strlen(reply) + strlen(entry) + strlen("PARTNER|") + 2;
        if (needed >= replySize) {
            fprintf(stderr, "Demo 4 partner exceeds configuration buffer\n");
            break;
        }
        strcat(reply, "PARTNER|");
        strcat(reply, entry);
        strcat(reply, "|");
        printf("Demo 4 destination sent to kernel: %u.%u.%u.%u/32\n",
               a, b, c, d);
        added = 1;
        break;
    }
    fclose(config);
    return added;
}

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

/*
 * DB servers configured on this node are trusted TaraSec infrastructure
 * destinations.  Expose them to tarakernel as /32 partners so tagging and
 * destination-scoped Assistance Requests are evaluated for their traffic.
 * This does not create partnerRouter records or extend trust to arbitrary
 * Assistance Request destinations.
 */
static int appendRegisteredDbPartners(MYSQL *conn, char *reply, size_t replySize)
{
    MYSQL_RES *res;
    MYSQL_ROW row;
    int added = 0;
    int i;

    if (mysql_query(conn,
            "select lpad(hex(nullif(globalDb1ip,0)),8,'0'), "
            "lpad(hex(nullif(globalDb2ip,0)),8,'0'), "
            "lpad(hex(nullif(globalDb3ip,0)),8,'0') "
            "from setup limit 1")) {
        fprintf(stderr, "Unable to read registered DB server partners: %s\n",
                mysql_error(conn));
        return 0;
    }

    res = mysql_store_result(conn);
    if (!res)
        return 0;

    row = mysql_fetch_row(res);
    if (row) {
        for (i = 0; i < 3; i++) {
            char entry[32];
            size_t needed;

            if (!row[i] || !row[i][0])
                continue;

            snprintf(entry, sizeof(entry), "%s:FFFFFFFF^", row[i]);
            if (strstr(reply, entry))
                continue;

            needed = strlen(reply) + strlen(entry) +
                     (added == 0 ? strlen("PARTNER|") : 0) + 2;
            if (needed >= replySize) {
                fprintf(stderr,
                        "Registered DB server partner list exceeds configuration buffer\n");
                break;
            }

            if (added == 0)
                strcat(reply, "PARTNER|");
            strcat(reply, entry);
            printf("Registered DB server partner found: %s/32\n", row[i]);
            added++;
        }
    }

    mysql_free_result(res);
    if (added)
        strcat(reply, "|");
    return added;
}

static unsigned int readAdminSshPortFromConfig(void)
{
    FILE *config = fopen("/etc/tarasecfw.conf", "r");
    char line[512];
    unsigned int port = 48222;

    if (!config)
        return port;

    while (fgets(line, sizeof(line), config)) {
        char *p = line;
        unsigned int configuredPort;
        char extra;

        while (*p == ' ' || *p == '\t')
            p++;
        if (strncmp(p, "SSH_PORT", strlen("SSH_PORT")) != 0)
            continue;

        p += strlen("SSH_PORT");
        while (*p == ' ' || *p == '\t')
            p++;
        if (*p++ != '=')
            continue;
        while (*p == ' ' || *p == '\t')
            p++;

        int parsed;
        if (*p == '"')
            parsed = sscanf(p, "\"%u\" %c", &configuredPort, &extra);
        else if (*p == '\'')
            parsed = sscanf(p, "'%u' %c", &configuredPort, &extra);
        else
            parsed = sscanf(p, "%u %c", &configuredPort, &extra);

        if (parsed == 1 &&
            configuredPort >= 1 && configuredPort <= 65535)
            port = configuredPort;
        else
            fprintf(stderr, "Ignoring invalid SSH_PORT in /etc/tarasecfw.conf\n");
        break;
    }
    fclose(config);
    return port;
}

bool getSetupStringNewOk(MYSQL *conn, MYSQL *updateConn, char *cSetupString, int nBuffSize, bool bReadChangesOnly)
{
	return 1;
}//getSetupStringNewOk()



//int sentConfiguration(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
static int closeConfigurationConnections(MYSQL *conn, MYSQL *updateConn)
{
	if (conn)
		mysql_close(conn);
	if (updateConn)
		mysql_close(updateConn);
	return 0;
}


int sentConfiguration(int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
{
	//This is a request for configuration setup...
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST|INSPECT|DROP]

	//Below, the setup is read from database, but configuration sent to kernel is hard coded

        //printf("About to check setup\n");

	/*if (!bReadChangesOnly)
		if (fileConfigurationSent(nSequenceNumber, bIsInbound))
			return closeConfigurationConnections(conn, updateConn);*/


	MYSQL *conn, *updateConn;

		conn = getConnection();
		updateConn = getConnection();



		if (!bReadChangesOnly)
		{
			/*It's a challenge when there's too many infections, white/black lists, port forwards and more... 
			Before, it was thought to be handled with batches... But maybe it's better to use the handled 
			field in the data. Set them all as not handled at the first sending of config. Then send the next ones on timer. 
			Then can read as many as we went and leave the others for the next batch (we may need a separate field "sentTarakernel" but can try without).
			*/

			/* A full configuration must rebuild only the current active state.
			 * Mark inactive history handled so the following incremental pass does
			 * not replay it as infection data. */
			if (mysql_query(conn, "update internalInfections set handled = IF(active=b'1',b'0',b'1')")
				|| mysql_query(conn, "update colorListings set handled = b'0'")
				|| mysql_query(conn, "update honeyport set handled = b'0'")
				|| mysql_query(conn, "update inspection set handled = b'0'")
				|| mysql_query(conn, "update internalServers set handled = b'0'")
				|| mysql_query(conn, "update partnerRouter set handled = b'0'")
				|| mysql_query(conn, "update assistanceRequest set handled = b'0'")
				) {
			    fprintf(stderr, "%s\n", mysql_error(conn));
			    reportErrorReadin("servers");
		    	return closeConfigurationConnections(conn, updateConn);
			}
			printf("********** WARNING ******* Testing using handled field to assemble batches of settings. Initiated now.\n");
		}




	MYSQL_RES *setupRes;
	MYSQL_ROW setupRow;

	char *lpSQL = "select adminIp, \
			internalIP, \
			nettmask, \
			handled, \
			blockIncomingTaggedTrafficThreshold, \
			blockSshThreshold, \
			doingNAT, \
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
		return closeConfigurationConnections(conn, updateConn);
	}

	setupRes = mysql_use_result(conn);
	//res = mysql_store_result(conn);		
	if (!setupRes) {
	   	fprintf(stderr, "mysql_store_result failed: %s\n", mysql_error(conn));
		return closeConfigurationConnections(conn, updateConn);
	}		

	if ((setupRow = mysql_fetch_row(setupRes)) == NULL)
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
		printf("************ ERROR! Unable to read the setup. Aborting\n");
		return closeConfigurationConnections(conn, updateConn);
	}	

	uint32_t adminIP = (uint32_t)strtoul(setupRow[0]?setupRow[0]:"0", NULL, 10);
	uint32_t internalIP = (uint32_t)strtoul(setupRow[1]?setupRow[1]:"0", NULL, 10);

	int nSetupHandled = (setupRow[3] && *setupRow[3]);

	//printf("Setup handled: %d\n", nSetupHandled);







	MYSQL_RES *res = 0;
	MYSQL_ROW row = 0;
	char cReply[C_BUFF_SIZE];	//4090 probably
	*cReply = 0;
	int bFoundData = 0;
	int nFound = 0;
	int nCharsTruncated = 0;

	if (nSequenceNumber == 0)	//This is the first batch (for now there's only 1 batch)
	{
	    char szSQL[1200];	//Configuration queries include generated assistance predicates
	    char *lpHandledWhere;
		//printf("Reading configuration.....\n");
		sprintf(cReply, "CONFIG %d|", nSequenceNumber);

		//ØT 260617 - moved here (to avoid setup from ending in later batch if lots of other info)
//#ifdef SETUP_SETUP
		//************** Add setup *****************
		//printf("Reading setup...\n");
		bool bReadSetup = 1;


/*   No longer needed because setup is already read			

		if (bReadChangesOnly)
		{

			//Thought there was problem reading lots of fields (but the problem was memory leak elsewhere).. so implemented this check to see if handled is true or false
			char *lpSQL = "select dmesgUpdated from setup where coalesce(handled, b'0') = b'1' limit 1";

			if (mysql_query(conn, lpSQL)) {
				fprintf(stderr, "taralink: %s\n", mysql_error(conn));
				reportErrorReadin("setup");
				return closeConfigurationConnections(conn, updateConn);
			}
				
			//res = mysql_use_result(conn);
			res = mysql_store_result(conn);		
			if (!res) {
			    fprintf(stderr, "mysql_use_result failed: %s\n", mysql_error(conn));
    			return closeConfigurationConnections(conn, updateConn);
			}		

			if ((row = mysql_fetch_row(res)) == NULL)
				printf("Setup is changed. Sending to tarakernel.\n");
			else 
			{
				//printf("Setup unchanged. Skipping sending. Dmsg read: %s\n", row[0]?row[0]:"(NULL)");
				bReadSetup = false;
			}

	    	mysql_free_result(res);
			res = NULL;
		}
			*/
	
		if (bReadSetup)
		{
			char cSetupString[1000];
			//20k memory leak per minute before due to long mysql query.. Old method saved in getSetupStringOk() function...
			//char cSetupStringNew[1000];
			//if (//!getSetupStringOk(conn, updateConn, cSetupString, sizeof(cSetupString), bReadChangesOnly) ||
			//	!getSetupStringNewOk(conn, updateConn, cSetupStringNew, sizeof(cSetupStringNew), bReadChangesOnly))
			//	return closeConfigurationConnections(conn, updateConn);




			//printf("Found setup row...\n");
			if (!bReadChangesOnly || !nSetupHandled)
			{
				//printf("processing it...\n");
				union _showStatusBitsUnion cShowStatusBits;
				cShowStatusBits.nValues = 0; //Initialize the whole union / structure
				//cShowStatusBits.bits.nDummy = 0;
				int nField = 6;
				//printf("reading bit fields...\n");
				cShowStatusBits.bits.doingNAT  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showStatus  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showPreRoutePartner  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showPreRouteNonPartner  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showForwardPartner  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showForwardNonPartner  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showUrgentPtrUsage  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showOwnerless  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showOther  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showNew1  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.showNew2  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.doTagging  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.doReportTraffic = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.doInspection  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.doBlocking  = (setupRow[nField]?*setupRow[nField]:0);	nField++;
				cShowStatusBits.bits.doOther  = (setupRow[nField]?*setupRow[nField]:0);	nField++;

				//printf("after reading bit fields...\n");

				printf("This server is %sdoing NAT\n", (cShowStatusBits.bits.doingNAT?"":"NOT "));

				#define N_MAX_DONT_DMSG_IPs 150
				int nDontMsgFldNo = nField++;	
				char szDontDmesgIPs[N_MAX_DONT_DMSG_IPs];
				szDontDmesgIPs[0] = 0;
//				uint32_t ip_numeric = 0;

				//if (row[nDontMsgFldNo] && *row[nDontMsgFldNo])	//260406 asdf
				if (setupRow[nDontMsgFldNo] != NULL && *setupRow[nDontMsgFldNo])
				{
					//printf("DontSendTo: %s\n", row[nDontMsgFldNo]);
					//strcpy(szDontDmesgIPs, row[nDontMsgFldNo]);

					snprintf(szDontDmesgIPs, sizeof(szDontDmesgIPs), "%s", setupRow[nDontMsgFldNo]);					
					if (strlen(szDontDmesgIPs) > N_MAX_DONT_DMSG_IPs - 50)
						printf("************ WARNING **** Consider increasing buffer for IPs not to log to dmesg from %u (currently in use: %zu)\n", N_MAX_DONT_DMSG_IPs, strlen(szDontDmesgIPs));

					//NOTE! For now only handles one IP address
//					if (strlen(szDontDmesgIPs))
//						ip_numeric = inet_addr(szDontDmesgIPs);

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
				uint32_t nettmask = (uint32_t)strtoul(setupRow[2]?setupRow[2]:"0", NULL, 10);

				unsigned int  nBlockingThreshold = atoi(setupRow[4]);
				unsigned int  nBlockSshThreshold = atoi(setupRow[5]);
				unsigned int  nAdminSshPort = readAdminSshPortFromConfig();

				snprintf(cSetupString, sizeof(cSetupString), "SETUP|%08X^%08X^%08X^%01X^%01X^%04X^%02X^%s^", adminIP, internalIP, nettmask, nBlockingThreshold, nBlockSshThreshold, nAdminSshPort, cShowStatusBits.nValues, szDontDmesgIPs);
					//strcpy(cReply+strlen(cReply), "SETUP|");
					//strcpy(cReply+strlen(cReply), row[0]);
					//strcpy(cReply+strlen(cReply), "|");

				printf("Setup added now : %s^%s^%s\n", (setupRow[0]?setupRow[0]:"N/A"), (setupRow[1]?setupRow[1]:"N/A"), (setupRow[2]?setupRow[2]:"N/A"));

				if (!nSetupHandled) {
					//printf("Setting setup as handled..\n");
					if (mysql_query(updateConn, "update setup set handled = b'1'")) {
						fprintf(stderr, "%s\n", mysql_error(updateConn));
						addWarningRecord("****** ERROR Error updating setup handled field (meaning it will read again)");
				    	mysql_free_result(setupRes);
						return closeConfigurationConnections(conn, updateConn);
					}
			  	}
				else
					printf("setup was handled.. not setting\n");
						//printf("Finished processing it...\n");


				/*if (strcmp(cSetupString, cSetupStringNew))
					printf("********** WARNING ********* Setting strings differ: (old/new)\n%s\n%s\n", cSetupString, cSetupStringNew);
				else
					printf("New and old setup routines agree: %s\n", cSetupString);
					*/
				int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
				if (nPosLeft > 0)
					snprintf(cReply+strlen(cReply), nPosLeft, "%s|", cSetupString);	//ØT added "|"" 
				else
					nCharsTruncated += strlen(cSetupString);

		    	bFoundData = 1;


			}  
//			else
//				printf("Not adding setup.. handled was: %s\n", nSetupHandled);







		}
		//else	
		//	printf("Skipping reading (already handled)\n");
			
		//printf("Freeing up connections\n");
	   	mysql_free_result(setupRes);
		setupRes = 0;

//#endif //#ifdef SETUP_SETUP


//#ifdef SETUP_INTERNAL_SERVERS

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
		    return closeConfigurationConnections(conn, updateConn);
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
			if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s-%s^", row[0], row[1]);
			else
			{
				nCharsTruncated += 25;
				printf("WARNING! Breaking on buffer almost full when reading internal servers");
				break;
			}

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

//#endif //#ifdef SETUP_INTERNAL_SERVERS


		//printf("Setup after servers: %s\n", cReply);
		//}

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();


//#ifdef SETUP_BLACK_AND_WHITELISTS

		//************** Add the white- and blacklistings *****************
	    //printf("Reading black- and white listings...\n");
		sprintf(szSQL, "select inet_ntoa(ip) as ip, upper(color), ip as aIp, handled from vListings"); 
		
		if (bReadChangesOnly)
		    strcpy(szSQL+strlen(szSQL), " where handled is null");

		sprintf(szSQL + strlen(szSQL), " limit %u", MAX_COLORLISTINGS);

		if (mysql_query(conn, szSQL)) {
			fprintf(stderr, "%s\n", mysql_error(conn));
  			reportErrorReadin("white- and blacklists");
  			return closeConfigurationConnections(conn, updateConn);
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
			if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s^", row[0]);
			else
			{
				nCharsTruncated += 12;
				printf("WARNING! Breaking on buffer almost full when reading white/blacklist");
				break;
			}

			nFound++;
			updateHandled(updateConn, "colorListings", "ip", row[2]);
			updateHandled(updateConn, "domainIp", "ip", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

//#endif //#ifdef SETUP_BLACK_AND_WHITELISTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

//#ifdef SETUP_INTERNAL_INFECTIONS

		if (internalIP)
		{
			//Probably set up as a router..

			//*************************Send info on internal infections (in the network) ****************
			//printf("Reading internal unit infections...\n");
		
			if (bReadChangesOnly)
				lpHandledWhere = "WHERE COALESCE(handled, b'0') = b'0'";
			else
				lpHandledWhere = "WHERE active = b'1'";

			sprintf(szSQL, "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, coalesce(status,'NULL'), \
				infectionId, handled, coalesce(CAST(active AS UNSIGNED),0) as active, coalesce(infoSharePartners,'NULL'), \
				coalesce(unitId,0), coalesce(severity,0), coalesce(botnetId,0), ip, nettmask, coalesce(why,'') from internalInfections %s limit %d", lpHandledWhere, MAX_INFECTIONS);
			//printf("SQL: %s\n", szSQL);

			if (mysql_query(conn, szSQL)) {
			    fprintf(stderr, "%s\n", mysql_error(conn));
 			    reportErrorReadin("internal infections");
		    	return closeConfigurationConnections(conn, updateConn);
			}
			res = mysql_use_result(conn);

			nFound =0;

			while ((row = mysql_fetch_row(res)) != NULL)
			{
    			bFoundData = 1;

				if (!nFound)
					sprintf(cReply+strlen(cReply), "INFECTION|");

				char *lpSendInfectionInfo = row[6];
				char *lpSendSeverity = row[8];

				int nActive = atoi(row[5]);
				if (!nActive)
				{
					/* Inactive is a state transition, not a reduced-severity
					 * infection.  Sending active=0 removes any cached kernel
					 * entry.  Partner notification below still receives the
					 * database's inactive state. */
					lpSendInfectionInfo = (row[12] && !strncmp(row[12], "DEMO:", 5))
						? "DEMO:clean" : "cleaning_unverified";
					lpSendSeverity = "0";
					printf("Sending inactive state so tarakernel removes cached infection\n");
				}

				printf("****** Active: %d (%s), info: %s, severity: %s. After: %s/%s\n", nActive, row[5], row[6], row[8], lpSendInfectionInfo, lpSendSeverity);

				//printf("taralink: Infection found : %s-%s-%s-%s\n", row[0], row[1], row[5], row[2]);
				//															ip		nett	active status  infID   severity botnetId info
				int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
				if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
					snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%d-%s-%s-%s-%s-%s^", 
							row[0], row[1], nActive, row[2], row[3], lpSendSeverity, row[9], lpSendInfectionInfo);
				else
				{
					nCharsTruncated += 70;
					printf("WARNING! Breaking on buffer almost full when reading internal infections");
					break;
				}

				//	ip				nett	active status  infID   severity botnetId info
	/*INFECTION|	100.100.100.100:255.255.255.255-1-(null)-       -1503633950-        -1503633942-0-(null)^
				100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
				100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
	*/
				if (!row[4] || !atoi(row[4])) 
					updateHandled(updateConn, "internalInfections", "infectionId", row[3]);

				if (bReadChangesOnly)
					init_background_infecton_change_partner_notification(atol(row[10]), atol(row[11]), row[5], atol(row[2]), atol(row[3]), atol(lpSendSeverity), atol(row[9]), lpSendInfectionInfo);	//ip		nett	active status  infID   severity botnetId info

				nFound++;
			}

			mysql_free_result(res);

			if (nFound == MAX_INFECTIONS)
			{
				printf("\n\n\n********** WARNING ********** Stopped at %u infections. You should clean up or increase the limit if it's safe...\n\n", MAX_INFECTIONS);
			}


			if (nFound)
				strcpy(cReply+strlen(cReply), "|");
		}
		else
			if (!bReadChangesOnly)
				printf ("No internal IP (not a router) so skipping sending internal infections\n");

//#endif //#ifdef SETUP_INTERNAL_INFECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

//#ifdef SETUP_PARTNERS

		//*************************Send partner info ****************
		//printf("Reading partners...\n");
		strcpy(szSQL, "select hex(ip), hex(nettmask), routerId from partnerRouter");
		
		if (bReadChangesOnly)
		      strcpy(szSQL+strlen(szSQL), " where handled is null");

		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "%s\n", mysql_error(conn));
 		    reportErrorReadin("partner info");
		    return closeConfigurationConnections(conn, updateConn);
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
				snprintf(cReply+strlen(cReply), nPosLeft, "PARTNER|");
				/* The append pointer moved; do not pass the old, larger
				 * capacity to the next snprintf call. */
				nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			}

			printf("Partner found : %s-%s\n", row[0], row[1]);

			if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
			{
				nCharsTruncated += 25;
				printf("WARNING! Breaking on buffer almost full when reading partern routers");
				break;
			}

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

		/* Add registered TaraSec DB servers as kernel-only /32 partners. */
		if (appendRegisteredDbPartners(conn, cReply, sizeof(cReply)) > 0)
			bFoundData = 1;

		/* Add explicitly configured demo destinations without persisting trust. */
		if (appendDemoPartnersFromConfig(cReply, sizeof(cReply)) > 0)
			bFoundData = 1;

		/* Scope Demo 4 tagging to the operator's configured /32 only. */
		if (appendDemo4DestinationFromConfig(cReply, sizeof(cReply)) > 0)
			bFoundData = 1;
		//else
		//	printf("No routers updated\n", nFound);

//#endif //#define SETUP_PARTNERS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

//#ifdef SETUP_INSPECTIONS

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
		    return closeConfigurationConnections(conn, updateConn);
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
			if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
			{
				nCharsTruncated += 25;
				printf("WARNING! Breaking on buffer almost full when reading honeypots");
				break;
			}

			nFound++;
			updateHandled(updateConn, "inspection", "ip", row[3]);
		}
		mysql_free_result(res);

		if (nFound) {
			strcpy(cReply+strlen(cReply), "|");
		    bFoundData = 1;
        }
//#endif //#ifdef SETUP_INSPECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

//#ifdef SETUP_HONEYPOTS

		//************** Add honeyports ([HONEY]) *****************
		//printf("Reading honeypots...\n");
        if (!bReadChangesOnly)
			strcpy(szSQL, "select port, handling from honeyport order by port");
        else	        
			strcpy(szSQL, "select port, handling from honeyport where handled is null order by port");
		
		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "taralink: %s\n", mysql_error(conn));
		    reportErrorReadin("honeypot info");
		    return closeConfigurationConnections(conn, updateConn);
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
		
		/* The table is an event history, while tarakernel needs one effective
		 * state per target. Collapse duplicate/overlapping requests so a stale
		 * history cannot overflow the netlink configuration packet. For an
		 * incremental update, include each target touched by an unhandled event,
		 * but calculate its state from all currently active rows. */
		if (bReadChangesOnly)
			/* Begin with the usually tiny set of changed endpoints, then use the
			 * endpoint index to aggregate only their history. A correlated EXISTS
			 * caused MariaDB to scan the complete history every five seconds. */
			lpHandledWhere = "join (select distinct ip, port from assistanceRequest where handled is null) changed on changed.ip=ar.ip and changed.port=ar.port";
		else
			lpHandledWhere = "where ar.active=b'1'";

		snprintf(szSQL, sizeof(szSQL),
			"select min(ar.requestId), hex(ar.ip), ar.port, "
			"coalesce(max(case when ar.active=b'1' then coalesce(ar.requestQuality,0) end),0), "
			"coalesce(max(case when ar.active=b'1' then CAST(ar.wantSpoofed AS UNSIGNED) end),0), "
			"null, if(sum(CAST(ar.active AS UNSIGNED))>0,1,0) "
			"from assistanceRequest ar %s group by ar.ip,ar.port order by ar.ip,ar.port",
			lpHandledWhere);
		//printf("Assist requests: %s\n", szSQL);
		
		if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "taralink: %s\n", mysql_error(conn));
		    reportErrorReadin("requests for assistance");
		    return closeConfigurationConnections(conn, updateConn);
		}
		res = mysql_use_result(conn);

		nFound =0;
		
		while ((row = mysql_fetch_row(res)) != NULL)
		{
		    int nActive;
        	if (!nFound)
				strcpy(cReply+strlen(cReply), "ASSIST|");
				
			nActive = (atoi(row[6])? 1 : 0);

            printf("Found %s assistance request: %s:%s-%s-%s-%d\n", (nActive?"active":"inactive (informing tarakernel to lift filtering)"), row[1], row[2], row[3], row[4], nActive);
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > PACKET_LEN_SAFETY_BUFFER)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%d^", row[1], (row[2]?row[2]:"0"), row[3], row[4], nActive);
			else
			{
				nCharsTruncated += 10;
				printf("WARNING! Breaking on buffer almost full when reading honeypots");
				break;
			}
			nFound++;
			/* Mark every pending history row represented by this effective target. */
			char szHandledSql[300];
			snprintf(szHandledSql, sizeof(szHandledSql),
				"update assistanceRequest set handled=b'1' where ip=CAST(CONV('%s',16,10) AS UNSIGNED) and port=%s and handled is null",
				row[1], row[2]?row[2]:"0");
			if (mysql_query(updateConn, szHandledSql))
				fprintf(stderr, "taralink: Could not mark assistance target handled: %s\n", mysql_error(updateConn));
		}
		mysql_free_result(res);
	//	printf("After assistance request..\n");

		if (nFound) {
		    bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
        }

//#endif //#ifdef SETUP_HONEYPOTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();


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
