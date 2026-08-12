//module_traffic_report.c
#include <inttypes.h>

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

void checkUpdateHackReport(MYSQL *conn, char *lpIpHex, char *lpPortHex, char *lpTagHex)
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

	char *lpSql = "select reportId, severity from hackReport where ip = CONV(?,16,10) and port = CONV(?,16,10) order by coalesce(lastSeen, created) desc limit 1";	//260623 - Was ordering ascending.. meaing we got an old probably...

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
				//bInsertHackReport = false;
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

		union _TagUnion cUnion;
		cUnion.nTag = nTag;
		unsigned int nSeverity = cUnion.cTag.presumed_infected;	//Changed from what's below....
		//unsigned int nSeverity = (nTag > 10? 7 : 0);
		//260623 - NOTE! FIND CORRECT SEVERITY AT LEAST...

		insertHackReport(conn, nIp, nPort, 0 /*nSenderIp*/, "tagged_traffic", lpInfo, cUnion.cTag.owners_id, 0 /*nInfectionId*/, nSeverity, 0 /*nBotnetId*/);
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

unsigned long ip_from;
unsigned int  port_from;
unsigned long ip_to;
unsigned int  port_to;
unsigned long count;
unsigned long tag;

/*Init internalInfections select*/
MYSQL_STMT *stmtSelectInternalInfection;

MYSQL_BIND bindInternalInfectionSelectParam[1];
const char *sqlSelectInternalInfection = "select infectionId from internalInfections where ip = ? order by lastSeen desc limit 1";

stmtSelectInternalInfection = mysql_stmt_init(conn);
if (!stmtSelectInternalInfection) {
    fprintf(stderr, "mysql_stmt_init failed\n");
    return;
}

if (mysql_stmt_prepare(stmtSelectInternalInfection, sqlSelectInternalInfection, strlen(sqlSelectInternalInfection)) != 0) {
    fprintf(stderr, "Prepare failed: %s\n", mysql_stmt_error(stmtSelectInternalInfection));
    mysql_stmt_close(stmtSelectInternalInfection);
    return;
}

memset(bindInternalInfectionSelectParam, 0, sizeof(bindInternalInfectionSelectParam));

bindInternalInfectionSelectParam[0].buffer_type = MYSQL_TYPE_LONG;
bindInternalInfectionSelectParam[0].buffer      = &ip_from;
bindInternalInfectionSelectParam[0].is_unsigned = 1;
bindInternalInfectionSelectParam[0].is_null     = NULL;
bindInternalInfectionSelectParam[0].length      = NULL;

if (mysql_stmt_bind_param(
        stmtSelectInternalInfection,
        bindInternalInfectionSelectParam
    ) != 0) {

    fprintf(
        stderr,
        "Internal infection bind failed: %s\n",
        mysql_stmt_error(stmtSelectInternalInfection)
    );

    mysql_stmt_close(stmtSelectInternalInfection);
    return;
}




/*
bindInternalInfectionSelectParam[1].buffer_type = MYSQL_TYPE_LONG;
bindInternalInfectionSelectParam[1].buffer      = &port_from;
bindInternalInfectionSelectParam[1].is_unsigned = 1;
*/

uint32_t selectedInfectionId;

MYSQL_BIND bindInfectionSelectResult[3];
my_bool infectionResultIsNull[3];
my_bool infectionResultError[3];
unsigned long infectionResultLength[3];

memset(bindInfectionSelectResult, 0, sizeof(bindInfectionSelectResult));
memset(infectionResultIsNull,      0, sizeof(infectionResultIsNull));
memset(infectionResultError,       0, sizeof(infectionResultError));
memset(infectionResultLength,      0, sizeof(infectionResultLength));

bindInfectionSelectResult[0].buffer_type = MYSQL_TYPE_LONG;
bindInfectionSelectResult[0].buffer      = &selectedInfectionId;
bindInfectionSelectResult[0].is_unsigned = 1;
bindInfectionSelectResult[0].is_null     = &infectionResultIsNull[0];
bindInfectionSelectResult[0].error       = &infectionResultError[0];
bindInfectionSelectResult[0].length      = &infectionResultLength[0];

if (mysql_stmt_bind_result(stmtSelectInternalInfection, bindInfectionSelectResult) != 0) {
    fprintf(
        stderr,
        "SELECT result bind failed: %s\n",
        mysql_stmt_error(stmtSelectInternalInfection)
    );

    mysql_stmt_close(stmtSelectInternalInfection);
    return;
}







/*Init Traffic select*/

MYSQL_STMT *stmtSelect;
MYSQL_BIND bindSelectParam[6];
const char *sqlSelect =
	"select trafficId, count, tag from traffic where "
		"ipFrom = ? and portFrom = ? and "
		"ipTo = ? and portTo = ? and "
		"(lastSeen is null or lastSeen > NOW() - INTERVAL 1 MINUTE) "
		"order by coalesce(lastSeen, created) desc limit 1";

stmtSelect = mysql_stmt_init(conn);
if (!stmtSelect) {
    fprintf(stderr, "mysql_stmt_init failed\n");
    return;
}

if (mysql_stmt_prepare(stmtSelect, sqlSelect, strlen(sqlSelect)) != 0) {
    fprintf(stderr, "Prepare failed: %s\n", mysql_stmt_error(stmtSelect));
    mysql_stmt_close(stmtSelect);
    return;
}

memset(bindSelectParam, 0, sizeof(bindSelectParam));

bindSelectParam[0].buffer_type = MYSQL_TYPE_LONG;
bindSelectParam[0].buffer      = &ip_from;
bindSelectParam[0].is_unsigned = 1;

bindSelectParam[1].buffer_type = MYSQL_TYPE_LONG;
bindSelectParam[1].buffer      = &port_from;
bindSelectParam[1].is_unsigned = 1;

bindSelectParam[2].buffer_type = MYSQL_TYPE_LONG;
bindSelectParam[2].buffer      = &ip_to;
bindSelectParam[2].is_unsigned = 1;

bindSelectParam[3].buffer_type = MYSQL_TYPE_LONG;
bindSelectParam[3].buffer      = &port_to;
bindSelectParam[3].is_unsigned = 1;

if (mysql_stmt_bind_param(stmtSelect, bindSelectParam) != 0) {
    fprintf(
        stderr,
        "SELECT parameter bind failed: %s\n",
        mysql_stmt_error(stmtSelect)
    );

    mysql_stmt_close(stmtSelect);
    return;
}


MYSQL_STMT *stmtInsert;
MYSQL_BIND bindInsert[6];
const char *sqlInsert =
    "INSERT INTO traffic "
    "(ipFrom, portFrom, ipTo, portTo, count, tag, lastSeen) "
    "VALUES (?, ?, ?, ?, ?, ?, NOW())";

stmtInsert = mysql_stmt_init(conn);
if (!stmtInsert) {
    fprintf(stderr, "mysql_stmt_init failed\n");
    return;
}

if (mysql_stmt_prepare(stmtInsert, sqlInsert, strlen(sqlInsert)) != 0) {
    fprintf(stderr, "Prepare failed: %s\n", mysql_stmt_error(stmtInsert));
    mysql_stmt_close(stmtInsert);
    return;
}

memset(bindInsert, 0, sizeof(bindInsert));

bindInsert[0].buffer_type = MYSQL_TYPE_LONG;
bindInsert[0].buffer = &ip_from;
bindInsert[0].is_unsigned = 1;

bindInsert[1].buffer_type = MYSQL_TYPE_LONG;
bindInsert[1].buffer = &port_from;
bindInsert[1].is_unsigned = 1;

bindInsert[2].buffer_type = MYSQL_TYPE_LONG;
bindInsert[2].buffer = &ip_to;
bindInsert[2].is_unsigned = 1;

bindInsert[3].buffer_type = MYSQL_TYPE_LONG;
bindInsert[3].buffer = &port_to;
bindInsert[3].is_unsigned = 1;

bindInsert[4].buffer_type = MYSQL_TYPE_LONG;
bindInsert[4].buffer = &count;
bindInsert[4].is_unsigned = 1;

bindInsert[5].buffer_type = MYSQL_TYPE_LONG;
bindInsert[5].buffer = &tag;
bindInsert[5].is_unsigned = 1;

if (mysql_stmt_bind_param(stmtInsert, bindInsert) != 0) {
    fprintf(stderr, "Insert bind failed: %s\n", mysql_stmt_error(stmtInsert));
}


uint32_t selectedTrafficId;
uint32_t selectedCount;
uint32_t selectedTag;

MYSQL_BIND bindSelectResult[3];
my_bool resultIsNull[3];
my_bool resultError[3];
unsigned long resultLength[3];

memset(bindSelectResult, 0, sizeof(bindSelectResult));
memset(resultIsNull,      0, sizeof(resultIsNull));
memset(resultError,       0, sizeof(resultError));
memset(resultLength,      0, sizeof(resultLength));

bindSelectResult[0].buffer_type = MYSQL_TYPE_LONG;
bindSelectResult[0].buffer      = &selectedTrafficId;
bindSelectResult[0].is_unsigned = 1;
bindSelectResult[0].is_null     = &resultIsNull[0];
bindSelectResult[0].error       = &resultError[0];
bindSelectResult[0].length      = &resultLength[0];

bindSelectResult[1].buffer_type = MYSQL_TYPE_LONG;
bindSelectResult[1].buffer      = &selectedCount;
bindSelectResult[1].is_unsigned = 1;
bindSelectResult[1].is_null     = &resultIsNull[1];
bindSelectResult[1].error       = &resultError[1];
bindSelectResult[1].length      = &resultLength[1];

bindSelectResult[2].buffer_type = MYSQL_TYPE_LONG;
bindSelectResult[2].buffer      = &selectedTag;
bindSelectResult[2].is_unsigned = 1;
bindSelectResult[2].is_null     = &resultIsNull[2];
bindSelectResult[2].error       = &resultError[2];
bindSelectResult[2].length      = &resultLength[2];

if (mysql_stmt_bind_result(stmtSelect, bindSelectResult) != 0) {
    fprintf(
        stderr,
        "SELECT result bind failed: %s\n",
        mysql_stmt_error(stmtSelect)
    );

    mysql_stmt_close(stmtSelect);
    return;
}



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
			char *end;

			ip_from = strtoul(cFields[0], &end, 16);
			if (*cFields[0] == '\0' || *end != '\0') {
				printf("******** ERROR when receiving traffic: invalid hexadecimal value\n");
			}

			port_from = (unsigned int)strtoul(cFields[1], &end, 16);
			if (*cFields[1] == '\0' || *end != '\0' || port_from > 65535) {
				printf("******** ERROR when receiving traffic: invalid port value\n");
			}

			ip_to = strtoul(cFields[2], &end, 16);
			if (*cFields[2] == '\0' || *end != '\0') {
				printf("******** ERROR when receiving traffic: invalid hexadecimal value\n");
			}

			port_to = (unsigned int)strtoul(cFields[3], &end, 16);
			if (*cFields[3] == '\0' || *end != '\0' || port_from > 65535) {
				printf("******** ERROR when receiving traffic: invalid port value\n");
			}

			count = strtoul(cFields[4], &end, 16);
			if (*cFields[4] == '\0' || *end != '\0') {
				printf("******** ERROR when receiving traffic: invalid hexadecimal value\n");
			}

			tag = (unsigned int)strtoul(cFields[5], &end, 16);
			if (*cFields[5] == '\0' || *end != '\0' || port_from > 65535) {
				printf("******** ERROR when receiving traffic: invalid port value\n");
			}


			//First check if there's a recent traffic report we can update.
			//Making new version checking if tag is changed from the most recent stored in the table.. If so check it and maybe change hackReport table...
//			sprintf(cSql, "select trafficId, count, tag from traffic where ipFrom = 0x%s and portFrom = 0x%s and ipTo = 0x%s and portTo = 0x%s and (lastSeen is null or lastSeen > NOW() - INTERVAL 1 MINUTE) order by coalesce(lastSeen, created) limit 1",
//					cFields[0], cFields[1], cFields[2], cFields[3]);

			//int nUpdateTrafficId = 0;

			uint32_t nUpdateTrafficId = 0;

			if (mysql_stmt_execute(stmtSelect) != 0) {
				fprintf(
        			stderr,
        			"SELECT traffic execute failed: %s\n",
        			mysql_stmt_error(stmtSelect)
    			);
			}
			else {
    			/*
     			* Buffer the result so the statement is ready to be reused
     			* cleanly on the next loop iteration.
     			*/
    			if (mysql_stmt_store_result(stmtSelect) != 0) {
			        fprintf(
            			stderr,
			            "SELECT store_result failed: %s\n",
			            mysql_stmt_error(stmtSelect)
        			);
    			}
    			else {
        			int fetchStatus = mysql_stmt_fetch(stmtSelect);

			        if (fetchStatus == 0 ||
			            fetchStatus == MYSQL_DATA_TRUNCATED) {

			            if (!resultIsNull[0] &&
			                !resultIsNull[2]) {

            			    if (selectedTag == tag) {
			                    nUpdateTrafficId = selectedTrafficId;
            			    }
			                else {
            			        printf(
                        			"Tag changed from %u to %lu; "
			                        "creating a new traffic row\n",
            			            selectedTag,
			                        tag
                    			);
			                }
			            }
        			}
        			else if (fetchStatus == MYSQL_NO_DATA) {
            			/* No recent matching row. Insert a new one. */
        			}
			        else {
			            fprintf(
			                stderr,
            			    "SELECT fetch failed: %s\n",
                			mysql_stmt_error(stmtSelect)
            			);
        			}

        			mysql_stmt_free_result(stmtSelect);
    			}
			}


/*
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
					}

					mysql_free_result(res);
				}
			}
*/
			if (nUpdateTrafficId)
			{
				char cSql[400];
				sprintf(cSql, "update traffic set count = count + 0x%s, lastSeen = now() where trafficId = %d", 
						cFields[4], nUpdateTrafficId);
				nUpdates++;

				if (!mysql_query(conn, cSql)){
					//According to manual, mysql_query() is supposed to return true if ok... But apparently not on all computers 
        	        //printf("******************************** ABLE TO INSERT ***********\n");
            	}
	            else
					fprintf(stderr, "MySQL error inserting/updating traffic record: %s\nSQL: %s\n", mysql_error(conn), cSql);
					//printf("******** ERROR inserting/updating traffic record.\n");

			}
			else
			{
				if (mysql_stmt_execute(stmtInsert) != 0) {
    				fprintf(stderr, "Execute failed: %s\n", mysql_stmt_error(stmtInsert));
				} else {
    				nInserts++;
				}				

				/*
				sprintf(cSql, "insert into traffic (ipFrom, portFrom, ipTo, portTo, count, tag, lastSeen) values (0x%s, 0x%s, 0x%s ,0x%s, 0x%s, 0x%s, now())", 
						cFields[0], cFields[1], cFields[2], cFields[3], cFields[4], cFields[5]);
				nInserts++;

				if (!mysql_query(conn, cSql)){
					//According to manual, mysql_query() is supposed to return true if ok... But apparently not on all computers 
        	        //printf("******************************** ABLE TO INSERT ***********\n");
            	}
	            else
					fprintf(stderr, "MySQL error inserting/updating traffic record: %s\nSQL: %s\n", mysql_error(conn), cSql);
					//printf("******** ERROR inserting/updating traffic record.\n");
				*/
			}
            
			//printf ("%s\n", cSql);

			checkUpdateHackReport(conn, cFields[0], cFields[1], cFields[5]);

			/************************** Update lastSeen in internalInfections - if found there...************ */

			if (mysql_stmt_execute(stmtSelectInternalInfection) != 0) {
				fprintf(
      				stderr,
      				"SELECT internal infection execute failed: %s\n",
      				mysql_stmt_error(stmtSelectInternalInfection)
  				);
			}
			else {
				if (mysql_stmt_store_result(stmtSelectInternalInfection) != 0) {
	        		fprintf(
         				stderr,
		            	"SELECT store_result failed: %s\n",
		            	mysql_stmt_error(stmtSelectInternalInfection)
    				);
    			}
    			else {
   					int fetchStatus = mysql_stmt_fetch(stmtSelectInternalInfection);

					if (fetchStatus == 0 || fetchStatus == MYSQL_DATA_TRUNCATED) 
					{

		    	        if (!infectionResultIsNull[0]) {

							//printf("Infection found: %u\n", selectedInfectionId);

							//****** Update lastSeen */
							char cSQL[400];
							sprintf(cSQL, "update internalInfections set lastSeen = now() where infectionId = %u", selectedInfectionId);
							//printf("SQL: %s\n", cSQL);
							//asdfasdf

							if (!mysql_query(conn, cSQL)){
                                   //printf("******************************** ABLE TO UPDATE lastSeen ***********\n");
                        	}
                        	else
                         			printf("******** ERROR updateing internalInfections.lastSeen *****\n%s", cSQL);

       					    /*if (selectedTag == tag) {
	        		            nUpdateTrafficId = selectedTrafficId;
       					    }
	                		else {
       			        		printf(
                      				"Tag changed from %u to %lu; "
	                        		"creating a new traffic row\n",
	          			            selectedTag,
		                        tag
        	         			);
		    	            }*/
		        	    }
    				}
	    			else
					{ 
						if (fetchStatus == MYSQL_NO_DATA) {
    	   					/* No recent matching row. Insert a new one. */
    					}
	        			else {
	            		fprintf(
	                		stderr,
          			    	"SELECT fetch failed: %s\n",
	              			mysql_stmt_error(stmtSelectInternalInfection)
    	   					);
    					}
					}
					mysql_stmt_free_result(stmtSelectInternalInfection);
				}
			}

		}
	}
	printf("%d records inserted, %d updated in traffic table.\n", nInserts, nUpdates);

	mysql_stmt_close(stmtSelect);
	mysql_stmt_close(stmtInsert);
	mysql_stmt_close(stmtSelectInternalInfection);

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


