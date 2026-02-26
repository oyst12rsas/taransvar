//module_functions.c


void insertWarningMessage(MYSQL *conn, char *szWarning, MYSQL_BIND *lpRec); 
void insertWarningMessage(MYSQL *conn, char *szWarning, MYSQL_BIND *lpRec) 
{
	char *lpSQL = "insert into warning (warning) values (?)";
    	MYSQL_STMT *stmt = mysql_stmt_init(conn);
	if (stmt == NULL) {
		printf("************ ERROR ********** Could not initialize statement\n");
              	exit(1);
        }
        int status = mysql_stmt_prepare(stmt, lpSQL, strlen(lpSQL));
	test_stmt_error(stmt, status); //line which gives me the syntax error 

	status = mysql_stmt_bind_param(stmt, lpRec); //muore qui
	test_stmt_error(stmt, status);

	// Run the stored procedure
        //printf("************ Debug ********** About to execute..(status: %d)\n", status);
	status = mysql_stmt_execute(stmt);
        printf("************ Debug ********** After inserting..(status: %d)\n", status);
}
			
void addWarningRecord_PREPARED_STATEMENT_NOT_WORKING(char *szWarning) //Now works for inserting new record but fails when fetching warningId if alread exists..
//Se addWarningRecord() below.....
{
	//First check if recently inserted..
	int status;
	MYSQL_RES *res;
	MYSQL_ROW row;
	MYSQL_RES *rs_metadata;
	MYSQL_STMT *stmt;
	MYSQL_BIND ps_params[1];
	bool bConnectedHere = false;
	MYSQL *conn = getConnection();
	
	char *lpSQL = "select warningId from warning where handled is null and lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) and warning = ?";
    	stmt = mysql_stmt_init(conn);
	if (stmt == NULL) {
		printf("************ ERROR ********** Could not initialize statement\n");
              	exit(1);
        }

	//printf("************ Debug ********** About to prepare..\n");

        status = mysql_stmt_prepare(stmt, lpSQL, strlen(lpSQL));
	test_stmt_error(stmt, status); //line which gives me the syntax error 

	memset(ps_params, 0, sizeof(ps_params));
	long unsigned int length = strlen(szWarning);

	ps_params[0].buffer_type = MYSQL_TYPE_VAR_STRING;
	ps_params[0].buffer = szWarning;   
	ps_params[0].buffer_length = strlen(szWarning);
	ps_params[0].length = &length;//strlen(szWarning); 
	ps_params[0].is_unsigned = 0;
	ps_params[0].is_null = 0;

	// bind parameters
        //printf("************ Debug ********** About to bind params.. (status: %d)\n", status);
	status = mysql_stmt_bind_param(stmt, ps_params); //muore qui
	test_stmt_error(stmt, status);

	// Run the stored procedure
        //printf("************ Debug ********** About to execute..(status: %d)\n", status);
	status = mysql_stmt_execute(stmt);
        printf("************ Debug ********** After execute..(status: %d)\n", status);
	test_stmt_error(stmt, status);
	MYSQL_BIND rec;
	unsigned int nWarningId;
	
        char cBuf[200];
	rec.buffer_type = MYSQL_TYPE_LONG;//MYSQL_TYPE_VAR_STRING;
        rec.buffer = (char*) &nWarningId;   
        rec.buffer_length = sizeof(unsigned int);
        rec.length = 0; //int-field;
        rec.is_unsigned = 1;
        rec.is_null = 0;

        printf("************ Debug ********** About to bind result..(status: %d)\n", status);
	
	if (!mysql_stmt_bind_result(stmt, &rec))
	{
                printf("************ Debug ********** About to fetch..\n");
		status = mysql_stmt_fetch(stmt);  //NOTE ! This succeeds if there's no hit but makes the program abort if there's record...
                printf("************ Debug ********** Fetched..(status: %d)\n", status);
		if (status == 1 || status == MYSQL_NO_DATA)
		{
  		        printf("\n***** Message not yet registered... \n\n");
  		        
  		        insertWarningMessage(conn, szWarning, &ps_params[0]); 
  		        
  		        
  		        
		}
		else
		{
		
			printf("\n***** Message already exists with id: %u\n\n", nWarningId);
		}
	        
	} 
	else
	{
		printf("\n***** Message not yet registered (bind_result failed)... \n\n");
	}
	
	//test_stmt_error(stmt, status);
	
	
      mysql_close(conn);
	
/*
	res = conn->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
				status = mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));
			test_stmt_error(stmt, status); //line which gives me the syntax error 
	res->execute($szWarning) or die "execution failed: $sth->errstr()";
	if (my $row = $sth->fetchrow_hashref()) { 
		$szSQL = "update warning set lastWarned = now(), count = count + 1 where warningId = ?";
		$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute($row->{"warningId"}) or die "execution failed: $sth->errstr()";
	} else {
		$szSQL = "insert into warning (warning) values (?)";
		$sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute($szWarning) or die "execution failed: $sth->errstr()";
	}
	*/
}

void addWarningRecord(char *szWarning)
{
        MYSQL *conn;
	int status;
	//MYSQL_RES *res;
	MYSQL_ROW row;
	MYSQL_RES *rs_metadata;
	MYSQL_STMT *stmt;
	MYSQL_BIND ps_params[1];
	char szSQL[3000];
	
	conn = getConnection();

	char szTempMsg[1900];
	char szSafeString[2050];
	if (strlen(szWarning)>=1900) 
	{
	        strncpy(szTempMsg, szWarning, 1898);
	        *(szTempMsg+1898) = 0;
      	        szWarning = szTempMsg;  //Make szWarning point to the truncated buffer instead.. 
	} 
		    
	mysql_real_escape_string(conn, szSafeString, szWarning, strlen(szWarning));
	
	snprintf(szSQL, sizeof(szSQL), "select warningId from warning where handled is null and lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) and warning = '%s'", szSafeString);
	if (mysql_query(conn, szSQL)) {
		fprintf(stderr, "**** ERROR ******* While finding warning: %s\n%s\n", szSQL, mysql_error(conn));
		return;
	}
        MYSQL_RES *res = mysql_use_result(conn);
        mysql_free_result(res);

	if (row = mysql_fetch_row(res)) {
                int nWarningId = atoi(row[0]);
	        char szSQL[200];
		sprintf(szSQL, "update warning set lastWarned = now(), count = count + 1 where warningId = %d", nWarningId);
    	        if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "**** ERROR ******* While updating warning: %s\n", mysql_error(conn));
		    return;
	        }
	} else {
		snprintf(szSQL, sizeof(szSQL), "insert into warning (warning) values ('%s')", szSafeString);
    	        if (mysql_query(conn, szSQL)) {
		    fprintf(stderr, "**** ERROR ******* While inserting warning: %s\n", mysql_error(conn));
		    return;
	        }
	}

        mysql_close(conn);
}

int addPendingWgetOk(et_wgetCategories eCategory, char *lpUrl, int nRegardingId)  //et_wgetCategories are defined in tarallink.c
{
        char szSafeString[2000];
        if (strlen(lpUrl) >= sizeof(szSafeString) -100)
        {
                char *lpMsg = "********* ERROR ******** Url is too long in taralink addPendingWget()"; 
	        fprintf(stderr, "%s\n", lpMsg);
	        return 0;
        } 
        else
        {
		char szSQL[2500];
		MYSQL *conn = getConnection();
		mysql_real_escape_string(conn, szSafeString, lpUrl, strlen(lpUrl));
		char *lpCategory;
		switch (eCategory) 
		{
		      case e_wget_assistanceRequest:
		            lpCategory = "'AssistanceRequest'";
		            break;
		      case e_wget_other:
		            lpCategory = "'Other'";
		            break;
		      default: 
		            lpCategory = "NULL";
		}
		sprintf(szSQL, "insert into pendingWget(url, category, regardingId) values('%s', %s, %d)", szSafeString, lpCategory, nRegardingId);
		if (mysql_query(conn, szSQL)) {
			fprintf(stderr, "**** ERROR ******* While finding warning: %s\n%s\n", szSQL, mysql_error(conn));
		        return 0;
		}
  	        return 1;
	}
}


