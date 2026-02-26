//module_request_assistance.c

#include <curl/curl.h>

/*  This function is called by the timer function (module_time.c), scans the table assistanceRequest and sends
    request to central database with request when the server load is too high. 
    Check the misc/checkload.pl script for putting the request record in the database */

size_t wget_write_callback(char *ptr, size_t size, size_t nmemb,
                      void *userdata)
{
        //Copy to temporary buffer because the data is not null terminated. 
        char *lpBuff = malloc(nmemb+1); 
        if (!lpBuff)
              return 0;
              
        strncpy(lpBuff, (char*)ptr, nmemb);
        lpBuff[nmemb] = 0;  //0 terminate the string. 
        
	printf("Webpage downloaded successfully: %s\n", lpBuff);//(char*)ptr);//userdata);
	free(lpBuff);
	return nmemb;  //This return value indicates that all data is taken care of..
}


char *wget(char *lpUrl, char *szBuff, int nBuffSize)
{
//        char *lpOutTempFile = "wget.html"; 
	curl_global_init(CURL_GLOBAL_ALL);

	CURL * myHandle;
	CURLcode setop_result;

/*        Better use call back than saving to file...
        FILE *file;
	if((file = fopen(lpOutTempFile, "wb")) == NULL)
	{
		perror("Error");
		exit(EXIT_FAILURE);
	}
	*/

	if((myHandle = curl_easy_init()) == NULL)
	{
		perror("****** Error curl_easy_init() - ABORTING\n");
		addWarningRecord("***** ERROR in wget - curl_easy_init(). Aborting");
		return "";
		//exit(EXIT_FAILURE);
	}

	if((setop_result = curl_easy_setopt(myHandle, CURLOPT_URL, lpUrl)) != CURLE_OK)
	{
		perror("****** Error curl_easy_setopt() - ABORTING\n");
		addWarningRecord("***** ERROR in wget - curl_easy_setopt(). Aborting");
		return "";
		//exit(EXIT_FAILURE);
	}

/*  ***** Don't download to file... callback function (wget_write_callback) works perfectly
        if((setop_result = curl_easy_setopt(myHandle, CURLOPT_WRITEDATA, file))     != CURLE_OK)
	{
		perror("Error");
		exit(EXIT_FAILURE);
	}
*/

	if((setop_result = curl_easy_setopt(myHandle, CURLOPT_WRITEFUNCTION, wget_write_callback))     != CURLE_OK)
	{
		perror("***** Error curl_easy_setopt CURLOPT_WRITEFUNCTION - ABORTING\n");
		addWarningRecord("**** ERROR in wget CURLOPT_WRITEFUNCTION");
		return "";
		//exit(EXIT_FAILURE);
	}

	if((setop_result = curl_easy_perform(myHandle)) != 0)
	{
	        char cMsg[256];
	        sprintf(cMsg, "**** Error **** curl_easy_perform (code %d) (still trying to resume)\n", setop_result);
		perror(cMsg);
		printf("\n-");
		printf("%s",lpUrl);
		printf("-\n");
		//exit(EXIT_FAILURE);
	}
	curl_easy_cleanup(myHandle);
	//fclose(file);
	//printf("Webpage downloaded successfully to %s\n", lpOutTempFile);

	return 0;
}

void checkRequestAssistance()
{
        /* Handling requests for assistance on tackling brute force/DOS attack. This table serves 3 purposes:
        - 1: Request from a unit in our network for assistance... For now this means this router/IP address.
        - 2: ABMonitor will send such request from us to global DB Servers as listed in setup table (that's what this function is doing)
        - 3: Global DB server(s) (may be up to 3..) Forwards this message to all partners. 
        - 4: ABMonitor sends information from the table to taransvar kernel module (tarakernel) that will block presumed infected traffic to routers who has requested such blocking. 
        
        This means that records in the database must be tagged properly (using the purpose field):
        - 1: purpose = "internalRequest"
        - 2: purpose = "forDistribution"
        - 3: purpose = "fromPartner"
        */
        
        //printf("Dropping requestassistance.. \n");
        //return;
        
	MYSQL *conn, *setupConn, *updateConn;
	MYSQL_RES *res, *setupRes;
	MYSQL_ROW row, setupRow;
	conn = getConnection();
	updateConn = setupConn = NULL;
	//printf("Checking requests for assistance.....\n");

        //Select unhandled (handled is null) assistance requests  
	char *szSQL = "select hex(ip) as ip, port, category, comment, requestQuality, wantSpoofed, requestId, senderIp, hex(senderIp) as senderIpHex, purpose from assistanceRequest where handled is null";

	//printf("About to loop..... %s\n", szSQL);

	if (mysql_query(conn, szSQL)) {
		fprintf(stderr, "%s\n", mysql_error(conn));
        	printf("Exiting (mysql_query error)...\n");
        	addWarningRecord("***** ERROR ***** selecting requests for assistance..");
        	return;
	}
	res = mysql_use_result(conn);

	while ((row = mysql_fetch_row(res)) != NULL)
	{
	        char *lpRequestId = row[6];
	        char *lpPurpose = row[9];
		char szUrl[255];
		char cSQL[255];
		int bHandled = 0;
		
		//If originating from this router (senderIp is null), send to globalDB servers (up to 3) listed in the setup. 
		//if (!row[7])
		if (lpPurpose && !strcmp(lpPurpose, "internalRequest"))
		{
		
                        printf ("Handling internal request.. %s\n", lpRequestId);
		
        		if (!setupConn) 
			{
				//char cSQL;
				setupConn = getConnection();
				
				if (!updateConn)
				      updateConn = getConnection();
				      
				if (mysql_query(setupConn, "select inet_ntoa(globalDb1ip) as ip1, inet_ntoa(globalDb2ip) as ip2, inet_ntoa(globalDb3ip) as ip3, inet_ntoa(adminIP) as adminIP from setup")) {
					fprintf(stderr, "%s\n", mysql_error(conn));
					printf("Exiting (setup, mysql_query error)...\n");
              	                        addWarningRecord("***** ERROR ***** selecting internal requests for assistance..");
					return;
				}
        	          	setupRes = mysql_use_result(setupConn);
        	          	if(!setupRes){
        	          	        printf("Error reading setup... Aborting\n");
              	                        addWarningRecord("***** ERROR ***** reading setup..");
        	          	        return;
        	          	}
				setupRow = mysql_fetch_row(setupRes);
        	          	if (!setupRow)
        	          	{
					fprintf(stderr, "%s\n", mysql_error(setupConn));
        	      	                printf("Exiting (setup, mysql_fetch_row error)...\n");
              	                        addWarningRecord("***** ERROR ***** reading setup row..");
					return;
        	          	}
			}

                        //This record originatet on this computer.. Probably from misc/checkload.pl
  			//Send request for assistance to global DB servers (listed in setup).. 
			bHandled = 1; //Maybe be set to 0 if add pendingWget fails
			for (int n = 0; n < 3; n++) {
		        	if (setupRow[n] != NULL) {
					char szUrl[255];
					char *lpMyIp = (row[0]?row[0]:setupRow[3]); //Use assistanceRequest->ip if set, otherwise setup->adminIP (my public IP)
                        	        int nPort = (row[1]?atoi(row[1]):0);
                        	        short nWantSpoofed = (row[5]?atoi(row[5]):0);
					sprintf(szUrl, "http://%s/script/requestAssistance.php?f=request&ip=%s&port=%d&cat=%s&qual=%s&sp=%d",
						setupRow[n], lpMyIp, nPort, row[2], row[4], nWantSpoofed);

					printf("Sending request for assistance (changed): %s\n", szUrl);
                                  	//MYSQL *handleConn = getConnection();
			                //updateHandled(handleConn, "assistanceRequest", "requestId", lpRequestId);
  	  			        //mysql_close(handleConn);
  	  			        
	                  	        bHandled = addPendingWgetOk(e_wget_assistanceRequest, szUrl, atoi(lpRequestId));
					
                        	        //wget(szUrl, szWgetBuff, sizeof(szWgetBuff));	//Reply may not come immediately(??) so shouldn't use buffers on stack that may no longer be there at the time of reply... (so using static variable defined in abmonitor.c)
                        	        
                        	        //Gets here now because no longer doing wget here.....
                        	        //printf("******** NEVER GETS HERE\n");
              	                        //addWarningRecord("***** ERROR ***** ******** NEVER GETS HERE");
				}
				else
				{
				        printf("Global server %d not specified. Skipping.\n", n+1);
				}
			}
		}
		else
  			if (lpPurpose && !strcmp(lpPurpose, "forDistribution"))
			{
				MYSQL *partnerConn;
				MYSQL_RES *partnerRes;
				MYSQL_ROW partnerRow;
				//This is a request from partner to relieve ddos or brute force attack. Distribute to all partners...
				partnerConn = getConnection();
				if (mysql_query(partnerConn, "select inet_ntoa(ip) as ip from partnerRouter")) {	
					fprintf(stderr, "%s\n", mysql_error(partnerConn));
	       	      	                printf("Exiting (error fetching partners, mysql_query error)...\n");
              	                        addWarningRecord( "***** ERROR ***** Exiting (error fetching partners, mysql_query error)");
              	                        return;
				}
	        	        partnerRes = mysql_use_result(partnerConn);
    			        bHandled = 1; //Maybe be set to 0 if add pendingWget fails
	        	        
	        	        while (partnerRow = mysql_fetch_row(partnerRes))
	        	        {
		        	        //select hex(ip) as ip, port, category, comment, requestQuality, wantSpoofed, requestId, senderIp, hex(senderIp) as senderIpHex from assistanceRequest where handled is null
	        	                char cUrl[256];
					char *lpRequesterIp = (row[0]?row[0]:row[8]); //Use assistanceRequest->ip if set, otherwise senderIpHex 
	                       	        int nPort = (row[1]?atoi(row[1]):0);
	                       	        char *lpCategory = (row[2]?row[2]:"other");
	                       	        short nQuality = (row[4]?atoi(row[4]):0);
	                       	        short nWantSpoofed = (row[5]?atoi(row[5]):0);
					sprintf(cUrl, "http://%s/script/partnerRequest.php?f=assistance&ip=%s&port=%d&cat=%s&qual=%d&sp=%d", 
						    partnerRow[0], lpRequesterIp, nPort, lpCategory, nQuality, nWantSpoofed);

                                        //NOTE! after calling wget never gets back here... So do whatever we need to do before calling.
                                  	//MYSQL *handleConn = getConnection();
			                //updateHandled(handleConn, "assistanceRequest", "requestId", lpRequestId);
  	  			        //mysql_close(handleConn);
			                
			                //No longer calling wget here so no need to clean up
	                  	        //mysql_free_result(partnerRes);
	  			        //mysql_close(partnerConn);

	                  	        //printf("******** WARNING ********* Can't do wget in while loop because never returns...: %s\n", cUrl);
	                  	        bHandled = addPendingWgetOk(e_wget_assistanceRequest, cUrl, atoi(lpRequestId));
	                                //wget(cUrl, szWgetBuff, sizeof(szWgetBuff));
              		                //printf("***** ERROR ********* NEVER GETS HERE... wget() never returns...\n");
	                                //return; 
	        	        }
			
	                  	mysql_free_result(partnerRes);
	  			mysql_close(partnerConn);
			}
			else
				if (!lpPurpose)
				{
					printf("************* ERROR - assistanceRequest.purpose was NULL... Not supposed to happen.\n");
					bHandled = 1; //Set it to handle.. Don't want them to accumulate.
				}
				else
				{
					printf("************* ERROR - unknown assistanceRequest.purpose: %s... Not supposed to happen.\n", lpPurpose);
					bHandled = 1; //Set it to handle.. Don't want them to accumulate.
				}
                              //else: What's not handled here is purpose == 'fromPartner'.. Determins if outbound presumed infected traffic is dropped 
                if (bHandled)
                {
			//This is a request from partner to relieve ddos or brute force attack. Distribute to all partners...
			MYSQL *conn = getConnection();
			updateHandled(conn, "assistanceRequest", "requestId", lpRequestId);
			mysql_close(conn);
		} 
		else
		{
			addWarningRecord( "***** ERROR ***** Unhandled record in checkRequestAssistance()");
		}
        }

	//printf("Finished checking requests for assistance.....\n");

	mysql_free_result(res);
	mysql_close(conn);
	
	if (setupConn) {
        	if (setupRes)
          	      mysql_free_result(setupRes);
		mysql_close(setupConn);
	}
	
	if (updateConn)
		mysql_close(updateConn);
}
