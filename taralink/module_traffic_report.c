//module_traffic_report.c


static int test_stmt_error(MYSQL_STMT * stmt, int status)
{
	if (status) {
		char cBuf[200];
		sprintf(cBuf, "***** Error: %s (errno: %d)", mysql_stmt_error(stmt), mysql_stmt_errno(stmt)); 
		fprintf(stderr, "%s\n", cBuf);
		addWarningRecord(cBuf);
	}
    return status;
}

char *bufferToHex(char *lpBuffer, int len, char* lpTarget, int nBufSize)
{
      int i;  
      if (len * 3 >= nBufSize)
            len = nBufSize / 3 -1;
            
      for (i = 0; i < len; i++)
      {
          sprintf(lpTarget + i*3, "%02X ", lpBuffer[i]);
      }
      lpTarget[i*3] = 0;
      return lpTarget;
}

void checkUpdateTag(MYSQL *conn, char *lpIpHex, char *lpPortHex, char *lpTagHex)
{
	//MYSQL *conn = getConnection();

	char *lpRec;
	char *lpTokens = "^";
    
	int status;
	MYSQL_RES *result;
	MYSQL_ROW row;
	MYSQL_FIELD *field;
	MYSQL_RES *rs_metadata;
	MYSQL_BIND queryParams[2];

	MYSQL_STMT *stmt = mysql_stmt_init(conn);
	if (stmt == NULL) 
    {
		printf("************ ERROR ********** Could not initialize statement\n");
        exit(1);
    }

	char *lpSql = "select reportId, severity from hackReport where ip = CONV(?,16,10) and port = CONV(?,16,10) order by coalesce(lastSeen, created) limit 1";

	status = mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));
	test_stmt_error(stmt, status); //line which gives me the syntax error 

	//printf("\nRunning: %s\nWith: %s and %s\n", lpSql, lpIpHex, lpPortHex);

	memset(queryParams, 0, sizeof(queryParams));
	unsigned long nIpLen = strlen(lpIpHex);
	unsigned long nPortLen = strlen(lpPortHex);

	//ipFrom
	queryParams[0].buffer_type = MYSQL_TYPE_VAR_STRING;
	queryParams[0].buffer_length = 100; //Irrelevant because we'll only do insert
	queryParams[0].is_unsigned = 1;
	queryParams[0].is_null = 0; 
    queryParams[0].buffer = lpIpHex;
	queryParams[0].length = &nIpLen;

	//portFrom
	queryParams[1].buffer_type = MYSQL_TYPE_VAR_STRING;
	queryParams[1].buffer_length = 100; //Irrelevant because we'll only do insert
	queryParams[1].is_unsigned = 1;
	queryParams[1].is_null = 0;
    queryParams[1].buffer = lpPortHex;
	queryParams[1].length = &nPortLen;

	// bind parameters
	status = mysql_stmt_bind_param(stmt, queryParams); //muore qui
	test_stmt_error(stmt, status);

	status = mysql_stmt_execute(stmt);
//	printf("************ Debug ********** After execute..(status: %d)\n", status);
	test_stmt_error(stmt, status);

	MYSQL_BIND rec[2];
	memset(rec, 0, sizeof(rec));

	unsigned int nReportId;
	int severity;
	
	rec[0].buffer_type = MYSQL_TYPE_LONG;//MYSQL_TYPE_VAR_STRING;
    rec[0].buffer = (char*) &nReportId;   
	rec[0].buffer_length = sizeof(unsigned int);
	rec[0].length = 0; //int-field;
	rec[0].is_unsigned = 1;
	rec[0].is_null = 0;

	rec[1].buffer_type = MYSQL_TYPE_LONG;//MYSQL_TYPE_VAR_STRING;
    rec[1].buffer = (char*) &severity;   
	rec[1].buffer_length = sizeof(unsigned int);
	rec[1].length = 0; //int-field;
	rec[1].is_unsigned = 0;
	rec[1].is_null = 0;


//	printf("************ Debug ********** About to bind result..(status: %d)\n", status);
	status = mysql_stmt_bind_result(stmt, rec);
	test_stmt_error(stmt, status);	

	status = mysql_stmt_fetch(stmt);
	unsigned int nTag = strtoul(lpTagHex, NULL, 16);		
	bool bInsertHackReport = false;

	if (status == MYSQL_NO_DATA) {

    	//printf("No hackReport found for 0x%s:0x%s. Tag: 0x%s (dec: %u\n", lpIpHex, lpPortHex, lpTagHex, nTag);

		if (nTag)
			bInsertHackReport = true;
	} 
	else 
		if (status == 0) 
		{
			//HackReport found... Check if they agree...
			bool bHackReportSaysInfected = (severity > 0);
			bool bTrafficSaysInfected = (nTag > 0);
			if (bHackReportSaysInfected != bTrafficSaysInfected)
			{
				bInsertHackReport = true;
				//printf("\n************ HackReport found but it disagrees with traffic.. Insert new hack report: Traffic tag: %s, hack report severity: %d\n", lpTagHex, severity);
				bInsertHackReport = false;
				printf("\n************ HackReport found but it disagrees with traffic.. Used to insert new hack report: Traffic tag: %s, hack report severity: %d (but now dropping inserting b'coz created lots of records - doesn't find the newly inserted record above so always makes new one.)\n", lpTagHex, severity);
	    		//printf("\n************ HackReport for 0x%s:0x%s. Tag: 0x%s - ID: %u, severity: %d\n\n", lpIpHex, lpPortHex, lpTagHex, nReportId, severity);
			}

		} else 
    		test_stmt_error(stmt, status);

	if (stmt)
		mysql_stmt_close(stmt);

	if (bInsertHackReport)
	{
		unsigned int nIp = strtoul(lpIpHex, NULL, 16);		
		unsigned short nPort = (unsigned short)strtoul(lpPortHex, NULL, 16);		
		char *lpInfo = "From traffic report.";
		unsigned int nSeverity = (nTag > 10? 7 : 0);

		insertHackReport(conn, nIp, nPort, 0 /*nSenderIp*/, lpInfo, 0 /*nInfectionId*/, nSeverity, 0 /*nBotnetId*/);
	}


//	mysql_close(conn);
}

/*
void tagChanged(char *lpFromIpHex, char *lpFromPortHex, char *lpToIpHex, char *lpToPortHex, int nFromTag, int nNewTag)
{
	printf("***** It's discovered that tag info for 0x%s:0x%s is %d, while it used to be: %d\n", lpFromIpHex, lpFromPortHex, nNewTag, nFromTag);
}*/

void handleTrafficReportFromKernel(char *lpPayload, int nDataLength)
{
    //ØT 260305 - Here's where receiving (new version)...
	//For now just printing.... 
	//Now sending both fromIP and toIP (not sure which the old version sent)

	MYSQL *conn = getConnection();
	//MYSQL *update = getConnection();

	char *lpRec;
	char *lpTokens = "^";
    
	int status;
	MYSQL_RES *result;
	MYSQL_ROW row;
	MYSQL_FIELD *field;
	MYSQL_RES *rs_metadata;
	MYSQL_BIND ps_params[4];

	//length[0] = strlen(cod);
    
	#define N_MAX_RECORDS 100
	char *cRecord[N_MAX_RECORDS];
	int nRecordCount = 0;

	char *token = strtok(lpPayload, "^");

	while (token != NULL) {
		cRecord[nRecordCount++] = token;
		token = strtok(NULL, "^");

		if (!token)
		{
			printf("****** ERROR ***** unable to find trailing '^'. Aborting.\n");
			break;
		}

		if (!strcmp(token, "EOF"))
		{
			//printf("EOF found. Aborting.\n");
			break;
		}

		//lpPayload is a NULL terminated string (sender, tarakernel, ensures).. Still make sure we don't go beyond the buffer...
		int nTokenOffset = token - lpPayload;
		int nCharsLeft = nDataLength - nTokenOffset;

		if (nCharsLeft < 28)	//Less than what "normally takes for one repot and not EOF (checked above)"
			printf("**** WARNING **** Only %d chars left of payload. Record: %s\n", nCharsLeft, (token?token:"(null)"));
		//else
		//	printf("**** %d chars left of payload: %s\n", nCharsLeft, (token?token:"(null)"));

		if (nCharsLeft < 3)
		{
			printf("*** ERROR! End reached without EOF... Aborting.\n");	//Probably never gets here...
			break;
		}

		if (nRecordCount > N_MAX_RECORDS - 3)
		{
			//No need for further error handling since this is just informational....????
			printf("\n***** ERROR ****** Too many records... Increase array size to handle more\n");
			break;
		}
	}

	int nInserts = 0;
	int nUpdates = 0;

	for (int j = 0; j < nRecordCount; j++)
	{
		//printf("Traffic found: %s\n", cRecord[j]);

		//Split the traffic record
		char cBackup[200];	//Just for debugging
		strcpy(cBackup, cRecord[j]);
		char *token = strtok(cRecord[j], "-");
		char *cFields[10];
		int n = 0;

		//Record format: AA4AFA8E-1BB-AA4AFA8E-D6CE-1-999   (6 fields... so 10 should be enough for a while)
		// <hex ip from>-<portfrom>-<hex ip to>-<port to>-<count>-<tag> 

		while (token != NULL && n<sizeof(cFields)) 
		{
			cFields[n++] = token;
			token = strtok(NULL, "-");
		}

		if (n != 6)
		{
			printf("***** ERROR ****** Incomplete record.. %d fields, supposed to be 6. Skipping record: %s\n", n, cBackup);
		}
		else
		{
           	char cSql[400];
			//First check if there's a recent traffic report we can update.

			//Making new version checking if tag is changed from the most recent stored in the table.. If so check it and maybe change hackReport table...
			//sprintf(cSql, "select trafficId, count from traffic where ipFrom = 0x%s and portFrom = 0x%s and ipTo = 0x%s and portTo = 0x%s and tag = 0x%s and (lastSeen is null or lastSeen > NOW() - INTERVAL 1 MINUTE) limit 1",
			//		cFields[0], cFields[1], cFields[2], cFields[3], cFields[5]);

			sprintf(cSql, "select trafficId, count, tag from traffic where ipFrom = 0x%s and portFrom = 0x%s and ipTo = 0x%s and portTo = 0x%s and (lastSeen is null or lastSeen > NOW() - INTERVAL 1 MINUTE) order by coalesce(lastSeen, created) limit 1",
					cFields[0], cFields[1], cFields[2], cFields[3]);

			int nUpdateTrafficId = 0;
			if (mysql_query(conn, cSql) == 0)
			{
				MYSQL_RES *res;
				MYSQL_ROW row;

				res = mysql_store_result(conn);
				if (res) {
					if ((row = mysql_fetch_row(res)) != NULL)
					{
						//*** ERROR - tag is hex... and this test is not sufficient because there may not be any recent traffic registered.. Better check all traffic records.. 
						int nTag = atoi(row[2]);
						uint32_t nNewTag = (uint32_t)strtoul(cFields[5], NULL, 16);
						//int nNewTag = atoi(cFields[5]);
						
						if (nTag == (int)nNewTag)	//Create new record if tag is changed. 
							nUpdateTrafficId = atoi(row[0]);
						else
							printf("\n**********Tag was changed, so creating new traffic record\n");						

						/*	tagChanged(cFields[0], cFields[1], cFields[2], cFields[3], nTag, nNewTag);
						else
						{
							printf("Tag unchanged for 0x%s:0x%s - tag %d\n", cFields[0], cFields[1], nTag);
						}*/
					}

					mysql_free_result(res);
				}
			}

			if (nUpdateTrafficId)
			{
				sprintf(cSql, "update traffic set count = count + 0x%s, lastSeen = now() where trafficId = %d", 
						cFields[4], nUpdateTrafficId);
				nUpdates++;
			}
			else
			{
				//printf("Record decoded: %s %s %s %s %s %s\n", cFields[0], cFields[1], cFields[2], cFields[3], cFields[4], cFields[5]);
				//OT_Changed: 260225 - Now also saving the tag...
				sprintf(cSql, "insert into traffic (ipFrom, portFrom, ipTo, portTo, count, tag, lastSeen) values (0x%s, 0x%s, 0x%s ,0x%s, 0x%s, 0x%s, now())", 
						cFields[0], cFields[1], cFields[2], cFields[3], cFields[4], cFields[5]);
				nInserts++;
			}
            
			//printf ("%s\n", cSql);
			if (!mysql_query(conn, cSql)){
				//According to manual, mysql_query() is supposed to return true if ok... But apparently not on all computers 
                //printf("******************************** ABLE TO INSERT ***********\n");
            }
            else
				fprintf(stderr, "MySQL error inserting/updating traffic record: %s\nSQL: %s\n", mysql_error(conn), cSql);
				//printf("******** ERROR inserting/updating traffic record.\n");

			checkUpdateTag(conn, cFields[0], cFields[1], cFields[5]);
		}
	}
	printf("%d records inserted, %d updated in traffic table.\n", nInserts, nUpdates);

	mysql_close(conn);
	//mysql_close(update);
}


//void OLD_VERSION2_handleTrafficReportFromKernel(char *lpPayload, int nDataLength)
/*void OLD_VERSION2_handleTrafficReportFromKernel(char *lpPayload, int nDataLength)
{
    //ØT 260305 - Here's where receiving...
	MYSQL *conn;
	conn = getConnection();
	char *lpRec;
	char *lpTokens = "^";
    
	int status;
	MYSQL_RES *result;
	MYSQL_ROW row;
	MYSQL_FIELD *field;
	MYSQL_RES *rs_metadata;
	MYSQL_STMT *stmt;
	MYSQL_BIND ps_params[4];
	//unsigned long length[4];
	//char cod[64];
	//unsigned int ipFrom, portFrom, portTo, count;
	//unsigned long int bIsUnsigned = 1;
	char szIpFrom[100];
	char szPortFrom[100];
	char szPortTo[100];
	char szCount[100];

	//length[0] = strlen(cod);
    
	stmt = mysql_stmt_init(conn);
	if (stmt == NULL) {
		printf("Could not initialize statement\n");
		exit(1);
	}



	/* 260305 This block was already commented out...
	
	char *lpSql = "insert into traffic (ipFrom, portFrom, portTo, count) values (unhex(?), unhex(?) ,unhex(?), unhex(?))"; 
			
	status = mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));
	test_stmt_error(stmt, status); //line which gives me the syntax error 

	memset(ps_params, 0, sizeof(ps_params));
	//ipFrom = pPost->ip;
	//portFrom = pPost->sPort;
	//portTo = pPost->dPort;
	//count = pPost->nCount;
	unsigned int nIpFrom, nPortFrom, nPortTo, nCount;
                        
	//ipFrom
	ps_params[0].buffer_type = MYSQL_TYPE_LONG;
	ps_params[0].buffer_length = sizeof(int); //Irrelevant because we'll only do insert
	ps_params[0].is_unsigned = 1;
	ps_params[0].is_null = 0;

	//portFrom
	ps_params[1].buffer_type = MYSQL_TYPE_VAR_STRING;
	ps_params[1].buffer_length = 100; //Irrelevant because we'll only do insert
	ps_params[1].is_unsigned = 1;
	ps_params[1].is_null = 0; 

	//portTo
	ps_params[2].buffer_type = MYSQL_TYPE_VAR_STRING;
	ps_params[2].buffer_length = 100; //Irrelevant because we'll only do insert
	ps_params[2].is_unsigned = 1;
	ps_params[2].is_null = 0;

	//count
	ps_params[3].buffer_type = MYSQL_TYPE_VAR_STRING;
	ps_params[3].buffer_length = 100; //Irrelevant because we'll only do insert
	ps_params[3].is_unsigned = 1;
	ps_params[3].is_null = 0;

	        ps_params[0].buffer = &nIpFrom;   
		ps_params[0].length = 0; 
		ps_params[1].buffer = &nPortFrom;
		ps_params[1].length = 0;
		ps_params[2].buffer = &nPortTo;
		ps_params[2].length = 0;
		ps_params[3].buffer = &nCount;
		ps_params[3].length = 0;

	// bind parameters
	status = mysql_stmt_bind_param(stmt, ps_params); //muore qui
	test_stmt_error(stmt, status);

	260306 Block already commented out ends here
*/
    
/*	char *lpIp, *lpPortFrom, *lpPortTo, *lpCount, *lpTag;
	int nCount = 0;
    
	for (lpRec = strtok (lpPayload, lpTokens); lpRec ; lpRec = strtok (NULL, lpTokens))
	{
		char cBackup[100];
		strncpy(cBackup, lpRec, (strlen(lpRec) > sizeof(cBackup) -1?sizeof(cBackup)-1:strlen(lpRec)+1));
		cBackup[sizeof(cBackup)-1] = 0;
		//Record format: AA4AFA8E-1BB-D6CE-1-999 
		// <hex ip>-<portfrom>-<port to>-<count>-<tag>

                if (strlen(lpRec) < 15)
                      printf("Short record: %s\n", lpRec);

                bool bFailed = 0;
                lpIp = lpRec;
		char *lpSep = strchr(lpIp, '-');
		
		if (!lpSep)
		{
		        printf("\n***** ERROR! Record is incomplete: %s... Aborting...\n", cBackup);
		        break;
		}

		*lpSep = 0;
		lpPortFrom = lpSep+1; 
		char *lpSep2 = strchr(lpPortFrom, '-');

        char *lpSep3, *lpSep4;
		
		if (lpSep2) {
    		*lpSep2 = 0;
			lpPortTo = lpSep2+1; 
			lpSep3 = strchr(lpPortTo, '-');
			
			if (lpSep3) {
  				*lpSep3 = 0;
          		lpCount = lpSep3+1;
          		int nLen = strlen(lpCount);
          		if (nLen == 0)
          		{
  		            printf("**** count was blank. lpPortTo = %s, count = %s (len = %d)\n", lpPortTo, lpCount, nLen);
		            bFailed = 1;
          		}
				else
				{
					//OT_Changed: 260225 - Also find the tag field....
					//Get the tag (urg_ptr)
					lpSep4 = strchr(lpCount, '-');
					if (lpSep4)
					{
		  				*lpSep4 = 0;
						lpTag = lpSep4+1;
						//printf("***** Tag found: %s\n", lpTag);
					}
					else
					{
						bFailed = 1;
						printf("***** ERROR lpSep4 was null (no tag field)\n");
					}
				}
			}
  		        else
  		        {
		                bFailed = 1;
		                printf("**** ERROR lpSep3 was null\n");
		        }
		}
		else
	        {
        	        bFailed = 1;
	                printf("**** lpSep2 was null\n");
	        }

                if (bFailed)
                {
		        printf("\n***** ERROR! Data was incomplete..: %s (len: %d, rec#: %d)\n", cBackup, nDataLength, nCount);
		        
                } else
                {
                	char cSql[400];
                	//sprintf(cSql, "insert into traffic (ipFrom, portFrom, portTo, count) values (unhex('%s'), unhex('%s') ,unhex('%s'), unhex('%s'))", lpIp, lpPortFrom, lpPortTo, lpCount);
                	
					//OT_Changed: 260225 - Now also saving the tag...
					sprintf(cSql, "insert into traffic (ipFrom, portFrom, portTo, count, tag) values (0x%s, 0x%s ,0x%s, 0x%s, 0x%s)", lpIp, lpPortFrom, lpPortTo, lpCount, lpTag);
                	
			//printf("%s\n", cSql);
*/
/*
			260306 - was alread commented out
			printf("strtok finished\n");
                	nIpFrom = strtol(lpIp, 0, 16);
                	nPortFrom = strtol(lpPortFrom, 0, 16);
                	nPortTo = strtol(lpPortTo, 0, 16);
                	nCount = strtol(lpCount, 0, 16);

			printf("about to insert\n");

			// Run the stored procedure
			status = mysql_stmt_execute(stmt);
			test_stmt_error(stmt, status);
			printf("inserted\n");

			already commented out block ends here
*/		

/*
			unsigned int nIpFrom = strtol(lpIp, 0, 16);
  		        //printf("Found %s (%u.%u.%u.%u)\n", cBackup, IPADDRESS(nIpFrom));

                        if (mysql_query(conn, cSql)){
                              //According to manual, mysql_query() is supposed to return true if ok... But apparently not on all computers 
                              //     printf("******************************** ABLE TO INSERT ***********\n");
                        }
                        //else
                         //     printf("******** ERROR inserting traffic record.\n");
                              
			nCount++;
			
			if (nCount % 10 == 0)
			    printf("%d records inserted\n",nCount); 
		}
	}
	printf("%d records inserted in traffic table.\n", nCount);

	mysql_close(conn);
}
*/


