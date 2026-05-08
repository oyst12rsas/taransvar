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
	mysql_stmt_close(stmt);	
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
			printf("\n***** Message already exists with id: %u\n\n", nWarningId);
	} 
	else
		printf("\n***** Message not yet registered (bind_result failed)... \n\n");
	
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

unsigned int inet__aton(char *lpIp)
{
	unsigned char ip1,ip2,ip3,ip4;
	
	int nRes = sscanf(lpIp, "%hhu.%hhu.%hhu.%hhu", &ip1,&ip2,&ip3,&ip4);

	unsigned int nIp;

	nIp = 	((unsigned int)ip1 << 24) |
            ((unsigned int)ip2 << 16) |
            ((unsigned int)ip3 <<  8) |
            (unsigned int)ip4;

	return nIp;
}

#include <stdio.h>
#include <string.h>
#include <stdlib.h>
//#include <mysql/mysql.h>

void addWarningRecord(char *szWarning)
{
    MYSQL *conn = NULL;
    MYSQL_STMT *stmt_select = NULL;
    MYSQL_STMT *stmt_update = NULL;
    MYSQL_STMT *stmt_insert = NULL;

    MYSQL_BIND bind_param[1];
    MYSQL_BIND bind_result[1];

    char szTempMsg[1900];
    unsigned long warning_len;
    int nWarningId = 0;
    int rc;

    conn = getConnection();
    if (!conn) {
        fprintf(stderr, "**** ERROR ******* getConnection() failed\n");
        return;
    }

    /* Truncate warning if needed */
    if (!szWarning) {
        szWarning = "";
    }

    if (strlen(szWarning) >= sizeof(szTempMsg)) {
        strncpy(szTempMsg, szWarning, sizeof(szTempMsg) - 1);
        szTempMsg[sizeof(szTempMsg) - 1] = '\0';
        szWarning = szTempMsg;
    }

    warning_len = (unsigned long)strlen(szWarning);

    /*
     * 1. Find an existing matching unhandled warning from the last day
     */
    stmt_select = mysql_stmt_init(conn);
    if (!stmt_select) {
        fprintf(stderr, "**** ERROR ******* mysql_stmt_init(select) failed\n");
        goto cleanup;
    }

    {
        const char *sql_select =
            "SELECT warningId "
            "FROM warning "
            "WHERE handled IS NULL "
            "  AND lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) "
            "  AND warning = ?";

        rc = mysql_stmt_prepare(stmt_select, sql_select, strlen(sql_select));
        if (rc != 0) {
            fprintf(stderr, "**** ERROR ******* While preparing select: %s\n",
                    mysql_stmt_error(stmt_select));
            goto cleanup;
        }
    }

    memset(bind_param, 0, sizeof(bind_param));
    bind_param[0].buffer_type = MYSQL_TYPE_STRING;
    bind_param[0].buffer = szWarning;
    bind_param[0].buffer_length = warning_len;
    bind_param[0].length = &warning_len;

    rc = mysql_stmt_bind_param(stmt_select, bind_param);
    if (rc != 0) {
        fprintf(stderr, "**** ERROR ******* While binding select params: %s\n",
                mysql_stmt_error(stmt_select));
        goto cleanup;
    }

    rc = mysql_stmt_execute(stmt_select);
    if (rc != 0) {
        fprintf(stderr, "**** ERROR ******* While executing select: %s\n",
                mysql_stmt_error(stmt_select));
        goto cleanup;
    }

    memset(bind_result, 0, sizeof(bind_result));
    bind_result[0].buffer_type = MYSQL_TYPE_LONG;
    bind_result[0].buffer = &nWarningId;

    rc = mysql_stmt_bind_result(stmt_select, bind_result);
    if (rc != 0) {
        fprintf(stderr, "**** ERROR ******* While binding select result: %s\n",
                mysql_stmt_error(stmt_select));
        goto cleanup;
    }

    rc = mysql_stmt_fetch(stmt_select);

    if (rc == 0) {
        /*
         * 2a. Existing warning found -> update lastWarned and increment count
         */
        stmt_update = mysql_stmt_init(conn);
        if (!stmt_update) {
            fprintf(stderr, "**** ERROR ******* mysql_stmt_init(update) failed\n");
            goto cleanup;
        }

        {
            const char *sql_update =
                "UPDATE warning "
                "SET lastWarned = NOW(), count = count + 1 "
                "WHERE warningId = ?";

            rc = mysql_stmt_prepare(stmt_update, sql_update, strlen(sql_update));
            if (rc != 0) {
                fprintf(stderr, "**** ERROR ******* While preparing update: %s\n..while running: %s\n", mysql_stmt_error(stmt_update), sql_update);
                goto cleanup;
            }
        }

        MYSQL_BIND update_params[1];
        memset(update_params, 0, sizeof(update_params));
        update_params[0].buffer_type = MYSQL_TYPE_LONG;
        update_params[0].buffer = &nWarningId;

        rc = mysql_stmt_bind_param(stmt_update, update_params);
        if (rc != 0) {
            fprintf(stderr, "**** ERROR ******* While binding update params: %s\n",
            mysql_stmt_error(stmt_update));
            goto cleanup;
        }

        rc = mysql_stmt_execute(stmt_update);
        if (rc != 0) {
            fprintf(stderr, "**** ERROR ******* While updating warning: %s\n",
                    mysql_stmt_error(stmt_update));
            goto cleanup;
        }
    }
    else if (rc == MYSQL_NO_DATA) {
        /*
         * 2b. No existing warning found -> insert new row
         */
        stmt_insert = mysql_stmt_init(conn);
        if (!stmt_insert) {
            fprintf(stderr, "**** ERROR ******* mysql_stmt_init(insert) failed\n");
            goto cleanup;
        }

        {
            const char *sql_insert =
                "INSERT INTO warning (warning) VALUES (?)";

            rc = mysql_stmt_prepare(stmt_insert, sql_insert, strlen(sql_insert));
            if (rc != 0) {
                fprintf(stderr, "**** ERROR ******* While preparing insert: %s\n",
                        mysql_stmt_error(stmt_insert));
                goto cleanup;
            }
        }

        MYSQL_BIND insert_params[1];
        memset(insert_params, 0, sizeof(insert_params));
        insert_params[0].buffer_type = MYSQL_TYPE_STRING;
        insert_params[0].buffer = szWarning;
        insert_params[0].buffer_length = warning_len;
        insert_params[0].length = &warning_len;

        rc = mysql_stmt_bind_param(stmt_insert, insert_params);
        if (rc != 0) {
            fprintf(stderr, "**** ERROR ******* While binding insert params: %s\n",
                    mysql_stmt_error(stmt_insert));
            goto cleanup;
        }

        rc = mysql_stmt_execute(stmt_insert);
        if (rc != 0) {
            fprintf(stderr, "**** ERROR ******* While inserting warning: %s\n",
                    mysql_stmt_error(stmt_insert));
            goto cleanup;
        }
    }
    else {
        fprintf(stderr, "**** ERROR ******* While fetching warning: %s\n",
                mysql_stmt_error(stmt_select));
        goto cleanup;
    }

cleanup:
    if (stmt_select)
        mysql_stmt_close(stmt_select);
    if (stmt_update)
        mysql_stmt_close(stmt_update);
    if (stmt_insert)
        mysql_stmt_close(stmt_insert);
    if (conn)
        mysql_close(conn);
}

/*
void addWarningRecord_before_prepared_statements(char *szWarning)
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
		mysql_close(conn);
		return;
	}
    
	MYSQL_RES *res = mysql_use_result(conn);

	if (row = mysql_fetch_row(res)) 
	{
        int nWarningId = atoi(row[0]);
	    char szSQL[200];
		sprintf(szSQL, "update warning set lastWarned = now(), count = count + 1 where warningId = %d", nWarningId);
    	if (mysql_query(conn, szSQL))
		    fprintf(stderr, "**** ERROR ******* While updating warning: %s\n", mysql_error(conn));
	}
	else 
	{
		snprintf(szSQL, sizeof(szSQL), "insert into warning (warning) values ('%s')", szSafeString);
    	if (mysql_query(conn, szSQL))
		    fprintf(stderr, "**** ERROR ******* While inserting warning: %s\n", mysql_error(conn));
	}
	mysql_close(conn);
}
*/

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

		int nRetval = 1;
		if (mysql_query(conn, szSQL))
		{
			fprintf(stderr, "**** ERROR ******* While finding warning: %s\n%s\n", szSQL, mysql_error(conn));
			nRetval = 0;
		}
		mysql_close(conn);
  	    return nRetval;
	}
}


