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










void handleTrafficReportFromKernel(char *lpPayload, int nDataLength)
{
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
	/*char *lpSql = "insert into traffic (ipFrom, portFrom, portTo, count) values (unhex(?), unhex(?) ,unhex(?), unhex(?))"; 
			
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
*/
    
	char *lpIp, *lpPortFrom, *lpPortTo, *lpCount, *lpTag;
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

/*			printf("strtok finished\n");
                	nIpFrom = strtol(lpIp, 0, 16);
                	nPortFrom = strtol(lpPortFrom, 0, 16);
                	nPortTo = strtol(lpPortTo, 0, 16);
                	nCount = strtol(lpCount, 0, 16);

			printf("about to insert\n");

			// Run the stored procedure
			status = mysql_stmt_execute(stmt);
			test_stmt_error(stmt, status);
			printf("inserted\n");
*/		
			/*u32*/ unsigned int nIpFrom = strtol(lpIp, 0, 16);
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


void OLD_handleTrafficReportFromKernel(struct _ipPort2 *lpPayload, int nDataLength);
void OLD_handleTrafficReportFromKernel(struct _ipPort2 *lpPayload, int nDataLength)
{
/* NOTE! Maybe this function should only store the report and return and then save to the database later... (not sure if it's syncron or asyncron) */

	int nArrSize = nDataLength / sizeof(struct _ipPort2);
	if (nArrSize != C_TRAFFIC_REPORT_ARRAY_SIZE)
	{
		printf("\n***** Traffic report array size mismatch. Should have been: %d, is: %d (bytes: %d+8)\n", C_TRAFFIC_REPORT_ARRAY_SIZE, nArrSize, nDataLength);
		if (nArrSize > C_TRAFFIC_REPORT_ARRAY_SIZE)
			nArrSize = C_TRAFFIC_REPORT_ARRAY_SIZE;
	}
	else
		printf("\nTraffic report from kernel (length: %d, %d posts)\n", nDataLength, nArrSize);
      
        char cBuf[200];
        bufferToHex((char*)lpPayload, (nDataLength>50?50:nDataLength), cBuf, 200);
        printf("**** Received: %s\n", cBuf);
      
	//nArrSize = 2;
	MYSQL *conn;
	conn = getConnection();
      
      
	for (int n = 0; n < nArrSize; n++)
		{
		struct _ipPort2 *pPost = lpPayload + n * sizeof(struct _ipPort2);
		if (pPost->ip)
		{
			printf("Found: %u: %u %u (count: %u)\n", pPost->ip, pPost->sPort, pPost->dPort, pPost->nCount);

			int status;
			MYSQL_RES *result;
			MYSQL_ROW row;
			MYSQL_FIELD *field;
			MYSQL_RES *rs_metadata;
			MYSQL_STMT *stmt;
			MYSQL_BIND ps_params[4];
			//unsigned long length[4];
			//char cod[64];
                        unsigned int ipFrom, portFrom, portTo, count;
                        //unsigned long int bIsUnsigned = 1;

			//length[0] = strlen(cod);
    
			stmt = mysql_stmt_init(conn);
			if (stmt == NULL) {
				printf("Could not initialize statement\n");
                        	exit(1);
                        }
			char *lpSql = "insert into traffic (ipFrom, portFrom, portTo, count) values (?, ? , ?, ?)"; 
			
			status = mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));
			test_stmt_error(stmt, status); //line which gives me the syntax error 

			memset(ps_params, 0, sizeof(ps_params));
                        ipFrom = pPost->ip;
                        portFrom = pPost->sPort;
                        portTo = pPost->dPort;
                        count = pPost->nCount;
                        
                        //ipFrom
			ps_params[0].buffer_type = MYSQL_TYPE_LONG;//MYSQL_TYPE_VAR_STRING;
                        ps_params[0].buffer = (char*) &ipFrom;   
                        ps_params[0].buffer_length = sizeof(int);
                        ps_params[0].length = 0; //int-field;
                        ps_params[0].is_unsigned = 1;
                        ps_params[0].is_null = 0;

                        //portFrom
			ps_params[1].buffer_type = MYSQL_TYPE_LONG;
                        ps_params[1].buffer = (char*) &portFrom;
                        ps_params[1].buffer_length = 0; //Int field
                        ps_params[1].length = 0;//Int field
                        ps_params[1].is_unsigned = 1;
                        ps_params[1].is_null = 0; 

                        //portTo
			ps_params[2].buffer_type = MYSQL_TYPE_LONG;
                        ps_params[2].buffer = (char*) &portTo;
                        ps_params[2].buffer_length = 0; //Int field
                        ps_params[2].length = 0; //Int field
                        ps_params[2].is_unsigned = 1;
                        ps_params[2].is_null = 0;

                        //count
			ps_params[3].buffer_type = MYSQL_TYPE_LONG;
                        ps_params[3].buffer = (char*) &count;
                        ps_params[3].buffer_length = 0; //Int field
                        ps_params[3].length = 0; //Int field
                        ps_params[3].is_unsigned = 1;
                        ps_params[3].is_null = 0;


                        // bind parameters
                        status = mysql_stmt_bind_param(stmt, ps_params); //muore qui
                        test_stmt_error(stmt, status);

                        // Run the stored procedure
                        status = mysql_stmt_execute(stmt);
                        test_stmt_error(stmt, status);






            }
            else
                  printf ("This slot was blank...\n");
      }
      
      mysql_close(conn);
}

