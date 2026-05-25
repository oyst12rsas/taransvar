
/*
	tarakernel asks the user server for configuration on what how to handle various units
	in the network upon in- and outbound traffic. 
	For inbound traffic, it tells what ip/port only accepts clean data and what ip/ports
	also accept various kind of infected date. 
	For outbound traffic, it tags traffic according to thread information about the
	units in the network. 

	This function stores information received from userserver (to be used by other modules)
	See struct _Ip4AddrPortRange for how to store information..

	one way may be to save a pointer to an array of pointers to memory blocks, 
	each holding an array of _Ip4AddrPortRange elements. 
*/

char *interpretColoredList(char *lpBlockDescriptor, char *lpIpList)
{
	int nBlockDescriptor;
	char *lpFound;
	volatile uint32_t ipAddress=0;
	unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
	int nCountInstructions = 0;

	if (!strcmp(lpBlockDescriptor, "BLACK_LIST")) 
		nBlockDescriptor = BLOCK_DESCRIPTIOR_BLACK_LIST;
	else
		nBlockDescriptor = BLOCK_DESCRIPTIOR_WHITE_LIST;

	pr_info("tarakernel: About to handle %s: %s\n", lpBlockDescriptor, lpIpList);

	while (1)
	{
		lpFound = strchr(lpIpList, '^');

		if (lpFound == NULL)
		{
			pr_info("tarakernel: Error.. List of element doesn't end with ^: %s\n", lpIpList);
			return "ERROR (preventing NULL pointer)";
		}		

		*lpFound = 0;
		//lpPointer now points to next instruction to handle
		//Format: 192.168.1.20:8080-clean
		pr_info("tarakernel: Instruction found: %s\n", lpIpList);
		//asdf
		
                //Check if there's room for more elements in the setup.
		if (pSetup->nElementsInArray[nBlockDescriptor] >= pSetup->nConfigurationArraySize[nBlockDescriptor])
		{
			pr_info("tarakernel: ***** WARNING! Too many elements in array for %s. Aborting.. Please clean up or report the problem.\n", lpBlockDescriptor);
    		        lpFound = strchr(lpFound+1, '|');
			
       			return (lpFound?lpFound+1:"EOF");
		}
		else
			pr_info("tarakernel: Still room in the array.. Elements: %d, root: %d\n", pSetup->nElementsInArray[nBlockDescriptor], pSetup->nConfigurationArraySize[nBlockDescriptor]);
		
		if (nCountInstructions > 9)
		{
		  	pr_info("tarakernel: ************************** For some reasons can't have more than 10 blacklists. So aborting.\n");
		  	break;
		}

		if (sscanf(lpIpList, "%hhu.%hhu.%hhu.%hhu", ipAddressBytes+0, 	ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3) == 4) 
		{
		        pr_info("Interpretation(ot): %d.%d.%d.%d(%08X)\n", (int)ipAddressBytes[3], (int)ipAddressBytes[2], (int)ipAddressBytes[1], (int)ipAddressBytes[0], ipAddress);
			storeColoredListElement(nBlockDescriptor, ipAddress);	//NOTE! Defined in module_configuration.c
		}
		else
			pr_info("Interpretation failed\n");

		lpIpList = lpFound + 1;
		nCountInstructions++;

		if (*lpIpList == '|')
		{
			//Should always get here.......
			++lpIpList; 
			break;
		}		

	}	
	return lpIpList;
}


char *interpretInspection(char *lpBlockDescriptor, char *lpIpList)
{
	int nBlockDescriptor;
	char *lpFound;
	//volatile uint32_t ipAddress=0;
	//unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
	//volatile uint32_t nNettmask=0;
	//unsigned char* nettmaskBytes = (unsigned char*)&nNettmask;
	int nCountInstructions = 0;

	if (!strcmp(lpBlockDescriptor, "INSPECT"))  //asdf 
		nBlockDescriptor = BLOCK_DESCRIPTIOR_INSPECT;
	else
		nBlockDescriptor = BLOCK_DESCRIPTIOR_DROP;

	pr_info("tarakernel: About to handle %s: %s\n", lpBlockDescriptor, lpIpList);

	while (1)
	{
	        char *lpColon;
		lpFound = strchr(lpIpList, '^');

		if (lpFound == NULL)
		{
			pr_info("tarakernel: Error.. List of element doesn't end with ^: %s\n", lpIpList);
			return "ERROR (preventing NULL pointer)";
		}		

		*lpFound = 0;
		//lpPointer now points to next instruction to handle
		//Format: 192.168.1.20:8080-clean
		pr_info("tarakernel: Instruction found: %s\n", lpIpList);


                //asdf

//		if (sscanf(lpIpList, "%hhu.%hhu.%hhu.%hhu:%hhu.%hhu.%hhu.%hhu", ipAddressBytes+0, ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3, 
//			nettmaskBytes+0, nettmaskBytes+1, nettmaskBytes+2, nettmaskBytes+3) == 8)
                lpColon = strchr(lpIpList, ':');
		if (lpColon)//sscanf(lpIpList, "%s:%s", cIp, cNettmask) == 2) 
		{
		        *lpColon = 0;
		        pr_info("Inspection interpretation: %s:%s\n", lpIpList, lpColon+1);	//NOTE! Defined in module_configuration.c
                        storeInspectionDirective(nBlockDescriptor, lpIpList, lpColon+1);
		}
		else
			pr_info("Inspection interpretation failed\n");

		lpIpList = lpFound + 1;
		nCountInstructions++;

		if (*lpIpList == '|')
		{
			//Should always get here.......
			++lpIpList; 
			break;
		}		

	}	
	return lpIpList;
}//interpretInspection()


char *interpretSetup(char *lpBlockDescriptor, char *lpIpList)
{
    char *lpFound = strchr(lpIpList, '|'); 
    int nError;
    long unsigned int nMyIp;
      
    if (lpFound)
    {
        char *lpSep;

		*lpFound = 0;
		//pr_info("I think this is SETUP: %s... What is this: %s\n", lpBlockDescriptor, lpIpList);
    	//lpIpList now: C0A86413^C0A83201^FFFFFF00
        lpSep = strchr(lpIpList, '^');

    	if (!lpSep) {
			lpIpList = lpFound + 1;
			pr_info("tarakernel: ***** ERROR in setup (external IP)\n");
			return lpIpList;
		}

		*lpSep = 0; 

        if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
        {
			pr_info("tarakernel: kstrtoul returned %d for ip (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
        }
        pSetup->nMyIp  = swappedEndian((u32) nMyIp);

            //****** Get internal ip
            lpIpList = lpSep+1; 
            lpSep = strchr(lpIpList, '^');
            if (!lpSep) {
    	  	  pr_info("tarakernel: ***** ERROR in setup (internal IP)\n");
                  return lpFound + 1;
            }

            *lpSep = 0; 
  			//pr_info("tarakernel: ***** Internal IP: %s\n", lpIpList);

			if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
			{
				pr_info("tarakernel: kstrtoul returned %d for ip (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
            }
            pSetup->nInternalIp  = swappedEndian((u32) nMyIp);

        //****** Get nettmask
        lpIpList = lpSep+1; 
        lpSep = strchr(lpIpList, '^');

        if (!lpSep) {
    	  	pr_info("tarakernel: ***** ERROR in setup (nettmask)\n");
            return lpFound + 1;
        }
            
        *lpSep = 0; 
		//pr_info("tarakernel: ***** Nettmask: %s\n", lpIpList);

			if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
			{
				pr_info("tarakernel: kstrtoul returned %d for nettmask (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
			}
            pSetup->nNettmask  = swappedEndian((u32) nMyIp);
            
            //pr_info("tarakernel: Setup saved: %u\n",pSetup->nMyIp);  //OT1111

            //*********** Get blockIncomingTaggedTrafficThreshold
            lpIpList = lpSep+1; 
            lpSep = strchr(lpIpList, '^');
            *lpSep = 0; 
            if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
			{
				pr_info("tarakernel: kstrtoul returned %d for blocking threshold (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
            }
            pSetup->nBlockIncomingTaggedTrafficLevel = (unsigned char)nMyIp;
            pr_info("tarakernel: ****** blocking threshold found ****: %d\n", pSetup->nBlockIncomingTaggedTrafficLevel);

        //****** Get show info instructions
        lpIpList = lpSep+1; 
        lpSep = strchr(lpIpList, '^');
		// pr_info("tarakernel: ***** Show instructions: %s\n", lpIpList);

        if (!lpSep) {
			pr_info("tarakernel: ***** ERROR in setup (show instructions)\n");
			return lpFound + 1;
        }

            *lpSep = 0; 

            if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
			{
				pr_info("tarakernel: kstrtoul returned %d for show instructions (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
            }
            //pSetup->nShowInstructions  = nMyIp;
        pSetup->cShowInstructions.nValues  = nMyIp;

        pr_info("tarakernel: Setup saved: %08X, %08X, %08X, %02X\n",pSetup->nMyIp, pSetup->nInternalIp, pSetup->nNettmask, pSetup->cShowInstructions.nValues);
            
        pr_info("tarakernel: Show: SS:%d, SPRP:%d, SHRNP:%d, SFP:%d, SFNP:%d, SUPTR:%d, orpn:%d, other:%d, tag:%d, inspect:%d, block:%d\n",
              pSetup->cShowInstructions.bits.showStatus,
              pSetup->cShowInstructions.bits.showPreRoutePartner,
              pSetup->cShowInstructions.bits.showPreRouteNonPartner,
              pSetup->cShowInstructions.bits.showForwardPartner,
              pSetup->cShowInstructions.bits.showForwardNonPartner,
              pSetup->cShowInstructions.bits.showUrgentPtrUsage,
              pSetup->cShowInstructions.bits.showOwnerless,
              pSetup->cShowInstructions.bits.showOther,
              //pSetup->cShowInstructions.bits.showNew1,
              //pSetup->cShowInstructions.bits.showNew2
        
              pSetup->cShowInstructions.bits.doTagging, 
              pSetup->cShowInstructions.bits.doInspection,
              pSetup->cShowInstructions.bits.doBlocking
              //pSetup->cShowInstructions.bits.doOther
		        );

		//Get list of IP addresses not to log to dmesg (setup->dontDmesgIPs)
        lpIpList = lpSep+1; 
        lpSep = strchr(lpIpList, '^');

        if (!lpSep) {
		//Get list of IP addresses not to log to dmesg (setup->dontDmesgIPs)
			pr_info("tarakernel: ***** (dontDmesgIPs) ERROR in setup (List of IPs not to log to dmsg is lacking)\n");
			pSetup->dontDmesgIPs[0] = 0;
			return lpFound + 1;
        }

		int nNdx = 0;
        *lpSep = 0; 

		while (lpIpList)
		{
			char *lpComma = strchr(lpIpList, ',');
			if (lpComma)
				*lpComma = 0;

			pr_info("tarakernel: (dontDmesgIPs) Received IPs not to log to dmesg: %s\n", lpIpList);
			
			/*Use if hexadecimal: 
			if ((nError = kstrtoul(lpIpList, 16, &nMyIp)))
			{
				pr_info("tarakernel: kstrtoul returned %d for IP not to send to dmesg (dontDmesgIPs) (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIpList);
				return lpFound + 1;
			}*/

			__be32 ip_be;   // network byte order
			//u32 ip_u32;     // host byte order

			#include <linux/inet.h>
			#include <linux/types.h>			
			
			if (!in4_pton(lpIpList, -1, (u8 *)&ip_be, '\0', NULL)) {
    			pr_info("tarakernel: Invalid IPv4 string when interpreting IP not to log to dmesg (dontDmesgIPs) NOTE! No space, just comma separated IPs!\n");
    			return lpFound + 1;//-EINVAL;
			}
			//ip_u32 = ntohl(ip_be);

			pSetup->dontDmesgIPs[nNdx] = ip_be;//swappedEndian((u32) nMyIp);
			pr_info("tarakernel: **************** Set IP to skip messages for: %pI4 (dontDmesgIPs)\n", &pSetup->dontDmesgIPs[nNdx]);
			if (nNdx++ >= N_MAX_IPs_NOT_TO_LOG_TO_DMESG)
			{
				pr_info("tarakernel: ****** WARNING! ***** Too many IPs not to log. Max number is %d. Remove from setup or increase max (dontDmesgIPs)\n", N_MAX_IPs_NOT_TO_LOG_TO_DMESG);
				break;
			}

			if (lpComma)
				lpIpList = lpComma + 1;
			else
				lpIpList = NULL; 	//Quit the loop
		}
    }    
    else
    {
		pr_info("tarakernel: *************** ERROR: Never supposed to get here...\n");
    	return lpIpList + strlen(lpIpList);
    }
      
    lpIpList = lpFound + 1;
    return lpIpList;
}


char *interpretNextBatch(int nBlockDescriptor, char *lpConfiguration)
{
	char *lpFound, *lpPointer;//, *lpDummy;
	int nCountInstructions = 0;
	//Interpret contents using regular expression 

	pr_info("tarakernel: Got a job: %s\n", lpConfiguration);

	lpPointer = lpConfiguration;	
	while (1)
	{
		char quality[100];
		int port;
		
		//uint32_t ipAddress=0;
//		unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
		uint32_t ipNettmask=0;
//		unsigned char* ipNettmaskBytes = (unsigned char*)&ipNettmask;

		//__be32 nIpAddress = 0;
		//__be32 nIpNettmask = 0;

		lpFound = strchr(lpPointer, '^');

		if (lpFound == NULL)
		{
			pr_info("tarakernel: Error.. List of element doesn't end with ^: %s\n", lpPointer);
			return "ERROR (preventing NULL pointer)";
		}		

		*lpFound = 0;
		//lpPointer now points to next instruction to handle
		//Format: 192.168.1.20:8080-clean
		pr_info("tarakernel: Instruction found: %s\n", lpPointer);

		//Note! Make it little endian (least significant byte first because that's how we get if from NetLink module

        //Check if there's room for more elements in the setup.
		if (pSetup->nConfigurationArraySize[nBlockDescriptor] > 0)  //asdf
  			if (pSetup->nElementsInArray[nBlockDescriptor] >= pSetup->nConfigurationArraySize[nBlockDescriptor])
			{
                pr_info("tarakernel: Too many elements in the array (%d/%d). Aborting.. Please clean up or report the problem.\n", 
				pSetup->nElementsInArray[nBlockDescriptor], pSetup->nConfigurationArraySize[nBlockDescriptor]);
    			lpFound = strchr(lpFound+1, '|');
       			return (lpFound?lpFound+1:"EOF");
			}

		switch (nBlockDescriptor)
		{
			case BLOCK_DESCRIPTIOR_SERVERS:
				/*if (sscanf(lpPointer, "%hhu.%hhu.%hhu.%hhu:%d-%s", ipAddressBytes+0, ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3, &port, quality) == 6) 
				{
				        pr_info("Interpretation: %d.%d.%d.%d:%d-%s(%08X)\n", (int)ipAddressBytes[3], (int)ipAddressBytes[2], (int)ipAddressBytes[1], (int)ipAddressBytes[0], port, quality, ipAddress);
					storeInstruction(nBlockDescriptor, ipAddress, 0, port, quality);	//NOTE! Defined in module_configuration.c
				}
				else
					pr_info("tarakernel: Servers: %s *************** ERROR! Interpretation failed\n", lpPointer);*/

				char *lpSearch;
				while ((lpSearch = strchr(lpPointer, '-')))
				    *lpSearch = '~';   //Replace - with ~ because sscanf has problem with - and unsigned variables (and maybe with strings...)

				if (sscanf(lpPointer, "%d~%s", &port, quality) == 2) 
				{
				    pr_info("tarakernel: Interpretation: %d-%s\n", port, quality);
					storeInstruction(nBlockDescriptor, 0, 0, port, quality);	//NOTE! Defined in module_configuration.c
					pr_info("After storeInstruction()");
				}
				else
					pr_info("tarakernel: Servers: %s *************** ERROR! Interpretation failed\n", lpPointer);
				break;
			case BLOCK_DESCRIPTIOR_INFECTIONS:
				{
				    if (pSetup->nElementsInArray[nBlockDescriptor] > 9)
					{
						pr_info("tarakernel: ************************** For some reasons can't have more than 10 infection (memory handling error). So aborting.\n");
						break;
					}
				
					char cInfo[256];
					char *lpFound;

					while ((lpFound = strchr(lpPointer, '-')))
					      *lpFound = '~';   //Replace - with ~ because sscanf has problem with - and unsigned variables (and maybe with strings...)
					      
					//											ip		 	nett	   active status infID severity botnetId info

			//										100.100.100.100:255.255.255.255~1~NULL~5~0~0~NO INFO SET

					unsigned char ip1,ip2,ip3,ip4;
					unsigned char nm1,nm2,nm3,nm4;
					int nActive, nInfectionId, nSeverity, nBotnetId;
					char status[32];

					int nRes = sscanf(lpPointer, "%hhu.%hhu.%hhu.%hhu:%hhu.%hhu.%hhu.%hhu~%d~%31[^~]~%d~%d~%d~%255[^\n]",
    						&ip1,&ip2,&ip3,&ip4,
    						&nm1,&nm2,&n











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

bool getSetupStringNewOk(MYSQL *conn, MYSQL *updateConn, char *cSetupString, int nBuffSize, bool bReadChangesOnly)
{
	MYSQL_RES *res;
	MYSQL_ROW row;
	*cSetupString = 0;

	char *lpSQL = "select adminIp, \
			internalIP, \
			nettmask, \
			handled, \
			blockIncomingTaggedTrafficThreshold, \
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
		return 0;
	}

	res = mysql_use_result(conn);
	//res = mysql_store_result(conn);		
	if (!res) {
	   	fprintf(stderr, "mysql_store_result failed: %s\n", mysql_error(conn));
		return 0;
	}		

	if ((row = mysql_fetch_row(res)) != NULL)
	{
		//printf("Found setup row...\n");
		if (!bReadChangesOnly || !atoi(row[3]))
		{
			//printf("processing it...\n");
			union _showStatusBitsUnion cShowStatusBits;
			cShowStatusBits.nValues = 0; //Initialize the whole union / structure
			//cShowStatusBits.bits.nDummy = 0;
			int nField = 5;
			//printf("reading bit fields...\n");
			cShowStatusBits.bits.showStatus  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRoutePartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRouteNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showUrgentPtrUsage  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOwnerless  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOther  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew1  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew2  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doTagging  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doReportTraffic = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doInspection  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doBlocking  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doOther  = (row[nField]?*row[nField]:0);	nField++;

			//printf("after reading bit fields...\n");

			#define N_MAX_DONT_DMSG_IPs 150
			int nDontMsgFldNo = nField++;	
			char szDontDmesgIPs[N_MAX_DONT_DMSG_IPs];
			szDontDmesgIPs[0] = 0;
//			uint32_t ip_numeric = 0;

			//if (row[nDontMsgFldNo] && *row[nDontMsgFldNo])	//260406 asdf
			if (row[nDontMsgFldNo] != NULL && *row[nDontMsgFldNo])
			{
				//printf("DontSendTo: %s\n", row[nDontMsgFldNo]);
				//strcpy(szDontDmesgIPs, row[nDontMsgFldNo]);

				snprintf(szDontDmesgIPs, sizeof(szDontDmesgIPs), "%s", row[nDontMsgFldNo]);					
				if (strlen(szDontDmesgIPs) > N_MAX_DONT_DMSG_IPs - 50)
					printf("************ WARNING **** Consider increasing buffer for IPs not to log to dmesg from %u (currently in use: %zu)\n", N_MAX_DONT_DMSG_IPs, strlen(szDontDmesgIPs));

				//NOTE! For now only handles one IP address
//				if (strlen(szDontDmesgIPs))
//					ip_numeric = inet_addr(szDontDmesgIPs);

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
			uint32_t adminIP = (uint32_t)strtoul(row[0]?row[0]:"0", NULL, 10);
			uint32_t internalIP = (uint32_t)strtoul(row[1]?row[1]:"0", NULL, 10);
			uint32_t nettmask = (uint32_t)strtoul(row[2]?row[2]:"0", NULL, 10);

			unsigned int  nBlockingThreshold = atoi(row[4]);

			snprintf(cSetupString, nBuffSize, "SETUP|%08X^%08X^%08X^%01X^%02X^%s^|", adminIP, internalIP, nettmask, nBlockingThreshold, cShowStatusBits.nValues, szDontDmesgIPs);
				//strcpy(cReply+strlen(cReply), "SETUP|");
				//strcpy(cReply+strlen(cReply), row[0]);
				//strcpy(cReply+strlen(cReply), "|");

			printf("Setup added now : %s^%s^%s\n", (row[0]?row[0]:"N/A"), (row[1]?row[1]:"N/A"), (row[2]?row[2]:"N/A"));
			if (!atoi(row[3]?row[3]:"0")) {
				//printf("Setting setup as handled..\n");
				if (mysql_query(updateConn, "update setup set handled = b'1'")) {
					fprintf(stderr, "%s\n", mysql_error(updateConn));
					addWarningRecord("****** ERROR Error updating setup handled field (meaning it will read again)");
			    	mysql_free_result(res);
					return 0;
				}
		  	}
			else
				printf("setup was handled.. not setting\n");
					//printf("Finished processing it...\n");
		}  
		else
			printf("Not adding setup.. handled was: %s\n", row[3]);
	}
	else
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
		//printf("************ ERROR! Unable to read the setup\n");
	}	
   	mysql_free_result(res);
	return 1;
}//getSetupStringNewOk()



//int sentConfiguration(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
int sentConfiguration(int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
{
	//This is a request for configuration setup...
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST|INSPECT|DROP]

	//Below, the setup is read from database, but configuration sent to kernel is hard coded

        //printf("About to check setup\n");

	/*if (!bReadChangesOnly)
		if (fileConfigurationSent(nSequenceNumber, bIsInbound))
			return 0;*/

	MYSQL *conn, *updateConn;
	MYSQL_RES *res;
	MYSQL_ROW row;
	char cReply[C_BUFF_SIZE];	
	*cReply = 0;
	int bFoundData = 0;
	int nFound = 0;
	int nCharsTruncated = 0;

	if (nSequenceNumber == 0)	//This is the first batch (for now there's only 1 batch)
	{
	    char szSQL[400];	//NOTE! 256 is now too small for internalInfections SQL
	    char *lpHandledWhere;
		conn = getConnection();
		updateConn = getConnection();
		//printf("Reading configuration.....\n");
		sprintf(cReply, "CONFIG %d|", nSequenceNumber);
		
#ifdef SETUP_INTERNAL_SERVERS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s-%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
				
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

#endif //#ifdef SETUP_INTERNAL_SERVERS


		//printf("Setup after servers: %s\n", cReply);
		//}

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();


#ifdef SETUP_BLACK_AND_WHITELISTS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s^", row[0]);
			else
				nCharsTruncated += 12;

			nFound++;
			updateHandled(updateConn, "colorListings", "ip", row[2]);
			updateHandled(updateConn, "domainIp", "ip", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_BLACK_AND_WHITELISTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INTERNAL_INFECTIONS

		//*************************Send info on internal infections (in the network) ****************
		//printf("Reading internal unit infections...\n");
		
		if (bReadChangesOnly)
			lpHandledWhere = "handled is null or handled = b'0'";
	    else
	        lpHandledWhere = "active = b'1'";

		sprintf(szSQL, "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, coalesce(status,'NULL'), \
			infectionId, handled, coalesce(CAST(active AS UNSIGNED),0) as active, coalesce(infoSharePartners,'NULL'), \
			coalesce(unitId,0), coalesce(severity,0), coalesce(botnetId,0), ip, nettmask from internalInfections where %s", lpHandledWhere);
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

			char *lpSendInfectionInfo = row[6];
			char *lpSendSeverity = row[8];

			int nActive = atoi(row[5]);
			if (!nActive)
			{
				lpSendInfectionInfo = "N/A";
				lpSendSeverity = "0";
			}

			printf("Active: %d (%s), info: %s, severity: %s. After: %s/%s\n", nActive, row[5], row[6], row[8], lpSendInfectionInfo, lpSendSeverity);

			//printf("taralink: Infection found : %s-%s-%s-%s\n", row[0], row[1], row[5], row[2]);
			//															ip		nett	active status  infID   severity botnetId info
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%s-%s-%s-%s^", 
							row[0], row[1], row[5], row[2], row[3], lpSendSeverity, row[9], lpSendInfectionInfo);
			else
				nCharsTruncated += 70;

			//	ip				nett	active status  infID   severity botnetId info
/*INFECTION|	100.100.100.100:255.255.255.255-1-(null)-       -1503633950-        -1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
*/
			if (!row[4] || !atoi(row[4])) 
				updateHandled(updateConn, "internalInfections", "infectionId", row[3]);

			if (bReadChangesOnly)
				init_background_infecton_change_partner_notification(atol(row[10]), atol(row[11]), row[5], atol(row[2]), atol(row[3]), atol(row[8]), atol(row[9]), row[6]);	//ip		nett	active status  infID   severity botnetId info

			nFound++;
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_INTERNAL_INFECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_PARTNERS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (!nFound)
			{
				if (nPosLeft < 15)
					break;
				sprintf(cReply+strlen(cReply), "PARTNER|");
			}

			printf("Partner found : %s-%s\n", row[0], row[1]);

			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;

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
		//else
		//	printf("No routers updated\n", nFound);

#endif //#define SETUP_PARTNERS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INSPECTIONS

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
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
			nFound++;
			updateHandled(updateConn, "inspection", "ip", row[3]);
		}
		mysql_free_result(res);

		if (nFound) {
			strcpy(cReply+strlen(cReply), "|");
		    bFoundData = 1;
        }
#endif //#ifdef SETUP_INSPECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_HONEYPOTS

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
		
		if (bReadChangesOnly)
		    lpHandledWhere = " and handled is null";
		else
		    lpHandledWhere = " and active = b'1'";
		        
		snprintf(szSQL, sizeof(szSQL), "select requestId, hex(ip), port, requestQuality, CAST(wantSpoofed AS UNSIGNED) as wantSpoofed, handled, CAST(active AS UNSIGNED) as active from assistanceRequest where purpose = 'fromPartner' %s order by ip", lpHandledWhere);
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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%d^", row[1], (row[2]?row[2]:"0"), row[3], row[4], nActive);
			else
				nCharsTruncated += 10;
			nFound++;
			updateHandled(updateConn, "assistanceRequest", "requestId", row[0]);
		}
		mysql_free_result(res);

		if (nFound) {
		    bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
        }

#endif //#ifdef SETUP_HONEYPOTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_SETUP
		//************** Add setup *****************
		//printf("Reading setup...\n");
		bool bReadSetup = 1;

		if (bReadChangesOnly)
		{
			//Thought there was problem reading lots of fields (but the problem was memory leak elsewhere).. so implemented this check to see if handled is true or false
			char *lpSQL = "select dmesgUpdated from setup where coalesce(handled, b'0') = b'1' limit 1";

			if (mysql_query(conn, lpSQL)) {
				fprintf(stderr, "taralink: %s\n", mysql_error(conn));
				reportErrorReadin("setup");
				return 0;
			}
				
			//res = mysql_use_result(conn);
			res = mysql_store_result(conn);		
			if (!res) {
			    fprintf(stderr, "mysql_use_result failed: %s\n", mysql_error(conn));
    			return 0;
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

		if (bReadSetup)
		{
			//char cSetupString[1000];
			//20k memory leak per minute before due to long mysql query.. Old method saved in getSetupStringOk() function...
			char cSetupStringNew[1000];
			if (//!getSetupStringOk(conn, updateConn, cSetupString, sizeof(cSetupString), bReadChangesOnly) ||
				!getSetupStringNewOk(conn, updateConn, cSetupStringNew, sizeof(cSetupStringNew), bReadChangesOnly))
				return 0;

			/*if (strcmp(cSetupString, cSetupStringNew))
				printf("********** WARNING ********* Setting strings differ: (old/new)\n%s\n%s\n", cSetupString, cSetupStringNew);
			else
				printf("New and old setup routines agree: %s\n", cSetupString);
				*/
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s", cSetupStringNew);
			else
				nCharsTruncated += strlen(cSetupStringNew);

		    bFoundData = 1;
		}
		//else	
		//	printf("Skipping reading (already handled)\n");
			
		//printf("Freeing up connections\n");
#endif //#ifdef SETUP_SETUP

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

bool getSetupStringNewOk(MYSQL *conn, MYSQL *updateConn, char *cSetupString, int nBuffSize, bool bReadChangesOnly)
{
	MYSQL_RES *res;
	MYSQL_ROW row;
	*cSetupString = 0;

	char *lpSQL = "select adminIp, \
			internalIP, \
			nettmask, \
			handled, \
			blockIncomingTaggedTrafficThreshold, \
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
		return 0;
	}

	res = mysql_use_result(conn);
	//res = mysql_store_result(conn);		
	if (!res) {
	   	fprintf(stderr, "mysql_store_result failed: %s\n", mysql_error(conn));
		return 0;
	}		

	if ((row = mysql_fetch_row(res)) != NULL)
	{
		//printf("Found setup row...\n");
		if (!bReadChangesOnly || !atoi(row[3]))
		{
			//printf("processing it...\n");
			union _showStatusBitsUnion cShowStatusBits;
			cShowStatusBits.nValues = 0; //Initialize the whole union / structure
			//cShowStatusBits.bits.nDummy = 0;
			int nField = 5;
			//printf("reading bit fields...\n");
			cShowStatusBits.bits.showStatus  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRoutePartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showPreRouteNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showForwardNonPartner  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showUrgentPtrUsage  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOwnerless  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showOther  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew1  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.showNew2  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doTagging  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doReportTraffic = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doInspection  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doBlocking  = (row[nField]?*row[nField]:0);	nField++;
			cShowStatusBits.bits.doOther  = (row[nField]?*row[nField]:0);	nField++;

			//printf("after reading bit fields...\n");

			#define N_MAX_DONT_DMSG_IPs 150
			int nDontMsgFldNo = nField++;	
			char szDontDmesgIPs[N_MAX_DONT_DMSG_IPs];
			szDontDmesgIPs[0] = 0;
//			uint32_t ip_numeric = 0;

			//if (row[nDontMsgFldNo] && *row[nDontMsgFldNo])	//260406 asdf
			if (row[nDontMsgFldNo] != NULL && *row[nDontMsgFldNo])
			{
				//printf("DontSendTo: %s\n", row[nDontMsgFldNo]);
				//strcpy(szDontDmesgIPs, row[nDontMsgFldNo]);

				snprintf(szDontDmesgIPs, sizeof(szDontDmesgIPs), "%s", row[nDontMsgFldNo]);					
				if (strlen(szDontDmesgIPs) > N_MAX_DONT_DMSG_IPs - 50)
					printf("************ WARNING **** Consider increasing buffer for IPs not to log to dmesg from %u (currently in use: %zu)\n", N_MAX_DONT_DMSG_IPs, strlen(szDontDmesgIPs));

				//NOTE! For now only handles one IP address
//				if (strlen(szDontDmesgIPs))
//					ip_numeric = inet_addr(szDontDmesgIPs);

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
			uint32_t adminIP = (uint32_t)strtoul(row[0]?row[0]:"0", NULL, 10);
			uint32_t internalIP = (uint32_t)strtoul(row[1]?row[1]:"0", NULL, 10);
			uint32_t nettmask = (uint32_t)strtoul(row[2]?row[2]:"0", NULL, 10);

			unsigned int  nBlockingThreshold = atoi(row[4]);

			snprintf(cSetupString, nBuffSize, "SETUP|%08X^%08X^%08X^%01X^%02X^%s^|", adminIP, internalIP, nettmask, nBlockingThreshold, cShowStatusBits.nValues, szDontDmesgIPs);
				//strcpy(cReply+strlen(cReply), "SETUP|");
				//strcpy(cReply+strlen(cReply), row[0]);
				//strcpy(cReply+strlen(cReply), "|");

			printf("Setup added now : %s^%s^%s\n", (row[0]?row[0]:"N/A"), (row[1]?row[1]:"N/A"), (row[2]?row[2]:"N/A"));
			if (!atoi(row[3]?row[3]:"0")) {
				//printf("Setting setup as handled..\n");
				if (mysql_query(updateConn, "update setup set handled = b'1'")) {
					fprintf(stderr, "%s\n", mysql_error(updateConn));
					addWarningRecord("****** ERROR Error updating setup handled field (meaning it will read again)");
			    	mysql_free_result(res);
					return 0;
				}
		  	}
			else
				printf("setup was handled.. not setting\n");
					//printf("Finished processing it...\n");
		}  
		else
			printf("Not adding setup.. handled was: %s\n", row[3]);
	}
	else
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
		//printf("************ ERROR! Unable to read the setup\n");
	}	
   	mysql_free_result(res);
	return 1;
}//getSetupStringNewOk()



//int sentConfiguration(struct _SocketData *pSockData, int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
int sentConfiguration(int nSequenceNumber, int bIsInbound, int bReadChangesOnly)
{
	//This is a request for configuration setup...
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST|INSPECT|DROP]

	//Below, the setup is read from database, but configuration sent to kernel is hard coded

        //printf("About to check setup\n");

	/*if (!bReadChangesOnly)
		if (fileConfigurationSent(nSequenceNumber, bIsInbound))
			return 0;*/

	MYSQL *conn, *updateConn;
	MYSQL_RES *res;
	MYSQL_ROW row;
	char cReply[C_BUFF_SIZE];	
	*cReply = 0;
	int bFoundData = 0;
	int nFound = 0;
	int nCharsTruncated = 0;

	if (nSequenceNumber == 0)	//This is the first batch (for now there's only 1 batch)
	{
	    char szSQL[400];	//NOTE! 256 is now too small for internalInfections SQL
	    char *lpHandledWhere;
		conn = getConnection();
		updateConn = getConnection();
		//printf("Reading configuration.....\n");
		sprintf(cReply, "CONFIG %d|", nSequenceNumber);
		
#ifdef SETUP_INTERNAL_SERVERS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s-%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
				
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

#endif //#ifdef SETUP_INTERNAL_SERVERS


		//printf("Setup after servers: %s\n", cReply);
		//}

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();


#ifdef SETUP_BLACK_AND_WHITELISTS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s^", row[0]);
			else
				nCharsTruncated += 12;

			nFound++;
			updateHandled(updateConn, "colorListings", "ip", row[2]);
			updateHandled(updateConn, "domainIp", "ip", row[2]);
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_BLACK_AND_WHITELISTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INTERNAL_INFECTIONS

		//*************************Send info on internal infections (in the network) ****************
		//printf("Reading internal unit infections...\n");
		
		if (bReadChangesOnly)
			lpHandledWhere = "handled is null or handled = b'0'";
	    else
	        lpHandledWhere = "active = b'1'";

		sprintf(szSQL, "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, coalesce(status,'NULL'), \
			infectionId, handled, coalesce(CAST(active AS UNSIGNED),0) as active, coalesce(infoSharePartners,'NULL'), \
			coalesce(unitId,0), coalesce(severity,0), coalesce(botnetId,0), ip, nettmask from internalInfections where %s", lpHandledWhere);
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

			char *lpSendInfectionInfo = row[6];
			char *lpSendSeverity = row[8];

			int nActive = atoi(row[5]);
			if (!nActive)
			{
				lpSendInfectionInfo = "N/A";
				lpSendSeverity = "0";
			}

			printf("******* Active: %d (%s), info: %s, severity: %s. After: %s/%s\n", nActive, row[5], row[6], row[8], lpSendInfectionInfo, lpSendSeverity);

			//printf("taralink: Infection found : %s-%s-%s-%s\n", row[0], row[1], row[5], row[2]);
			//															ip		nett	active status  infID   severity botnetId info
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%s-%s-%s-%s^", 
							row[0], row[1], row[5], row[2], row[3], lpSendSeverity, row[9], lpSendInfectionInfo);
			else
				nCharsTruncated += 70;

			//	ip				nett	active status  infID   severity botnetId info
/*INFECTION|	100.100.100.100:255.255.255.255-1-(null)-       -1503633950-        -1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
			100.100.100.100:255.255.255.255-1-(null)--1503633950--1503633942-0-(null)^
*/
			if (!row[4] || !atoi(row[4])) 
				updateHandled(updateConn, "internalInfections", "infectionId", row[3]);

			if (bReadChangesOnly)
				init_background_infecton_change_partner_notification(atol(row[10]), atol(row[11]), row[5], atol(row[2]), atol(row[3]), atol(row[8]), atol(row[9]), row[6]);	//ip		nett	active status  infID   severity botnetId info

			nFound++;
		}

		mysql_free_result(res);

		if (nFound)
			strcpy(cReply+strlen(cReply), "|");

#endif //#ifdef SETUP_INTERNAL_INFECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_PARTNERS

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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (!nFound)
			{
				if (nPosLeft < 15)
					break;
				sprintf(cReply+strlen(cReply), "PARTNER|");
			}

			printf("Partner found : %s-%s\n", row[0], row[1]);

			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;

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
		//else
		//	printf("No routers updated\n", nFound);

#endif //#define SETUP_PARTNERS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_INSPECTIONS

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
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s^", row[0], row[1]);
			else
				nCharsTruncated += 25;
			nFound++;
			updateHandled(updateConn, "inspection", "ip", row[3]);
		}
		mysql_free_result(res);

		if (nFound) {
			strcpy(cReply+strlen(cReply), "|");
		    bFoundData = 1;
        }
#endif //#ifdef SETUP_INSPECTIONS
		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_HONEYPOTS

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
		
		if (bReadChangesOnly)
		    lpHandledWhere = " and handled is null";
		else
		    lpHandledWhere = " and active = b'1'";
		        
		snprintf(szSQL, sizeof(szSQL), "select requestId, hex(ip), port, requestQuality, CAST(wantSpoofed AS UNSIGNED) as wantSpoofed, handled, CAST(active AS UNSIGNED) as active from assistanceRequest where purpose = 'fromPartner' %s order by ip", lpHandledWhere);
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
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s:%s-%s-%s-%d^", row[1], (row[2]?row[2]:"0"), row[3], row[4], nActive);
			else
				nCharsTruncated += 10;
			nFound++;
			updateHandled(updateConn, "assistanceRequest", "requestId", row[0]);
		}
		mysql_free_result(res);

		if (nFound) {
		    bFoundData = 1;
			strcpy(cReply+strlen(cReply), "|");
        }

#endif //#ifdef SETUP_HONEYPOTS

		//asdf - 260405 - testing...
		//mysql_close(conn);
		//conn = getConnection();

#ifdef SETUP_SETUP
		//************** Add setup *****************
		//printf("Reading setup...\n");
		bool bReadSetup = 1;

		if (bReadChangesOnly)
		{
			//Thought there was problem reading lots of fields (but the problem was memory leak elsewhere).. so implemented this check to see if handled is true or false
			char *lpSQL = "select dmesgUpdated from setup where coalesce(handled, b'0') = b'1' limit 1";

			if (mysql_query(conn, lpSQL)) {
				fprintf(stderr, "taralink: %s\n", mysql_error(conn));
				reportErrorReadin("setup");
				return 0;
			}
				
			//res = mysql_use_result(conn);
			res = mysql_store_result(conn);		
			if (!res) {
			    fprintf(stderr, "mysql_use_result failed: %s\n", mysql_error(conn));
    			return 0;
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

		if (bReadSetup)
		{
			//char cSetupString[1000];
			//20k memory leak per minute before due to long mysql query.. Old method saved in getSetupStringOk() function...
			char cSetupStringNew[1000];
			if (//!getSetupStringOk(conn, updateConn, cSetupString, sizeof(cSetupString), bReadChangesOnly) ||
				!getSetupStringNewOk(conn, updateConn, cSetupStringNew, sizeof(cSetupStringNew), bReadChangesOnly))
				return 0;

			/*if (strcmp(cSetupString, cSetupStringNew))
				printf("********** WARNING ********* Setting strings differ: (old/new)\n%s\n%s\n", cSetupString, cSetupStringNew);
			else
				printf("New and old setup routines agree: %s\n", cSetupString);
				*/
			int nPosLeft = sizeof(cReply)-strlen(cReply)-1;
			if (nPosLeft > 0)
				snprintf(cReply+strlen(cReply), nPosLeft, "%s", cSetupStringNew);
			else
				nCharsTruncated += strlen(cSetupStringNew);

		    bFoundData = 1;
		}
		//else	
		//	printf("Skipping reading (already handled)\n");
			
		//printf("Freeing up connections\n");
#endif //#ifdef SETUP_SETUP

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
m3,&nm4,
//							ipAddressBytes+0, ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3, ipNettmaskBytes+0, ipNettmaskBytes+1,ipNettmaskBytes+2,ipNettmaskBytes+3,
    						&nActive,
    						status,
    						&nInfectionId,
    						&nSeverity,
    						&nBotnetId,
    						cInfo);

					__be32 ip_be, nett_be;

					ip_be = cpu_to_be32(((u32)ip1 << 24) |
                    					((u32)ip2 << 16) |
                    					((u32)ip3 <<  8) |
                    					(u32)ip4);

					nett_be = cpu_to_be32(((u32)nm1 << 24) |
                    					((u32)nm2 << 16) |
                    					((u32)nm3 <<  8) |
                    					(u32)nm4);

					//if ((nRes = sscanf(lpPointer, "%hhu.%hhu.%hhu.%hhu:%hhu.%hhu.%hhu.%hhu~%d~%s~%d~%d~%d~%s", 
//					if ((nRes = sscanf(lpPointer, "%hhu.%hhu.%hhu.%hhu:%hhu.%hhu.%hhu.%hhu~%d~%[^~]~%d~%d~%d~%[^\n]",
//						ipAddressBytes+0, ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3, ipNettmaskBytes+0, ipNettmaskBytes+1,ipNettmaskBytes+2,ipNettmaskBytes+3, &nActive, quality, &nInfectionId, &nSeverity, &nBotnetId, cInfo)) == 14) 
					//if ((nRes = sscanf(lpPointer, "%hhu.%hhu.%hhu.%hhu:%hhu.%hhu.%hhu.%hhu~%u~%s", ipAddressBytes+0, ipAddressBytes+1,ipAddressBytes+2,ipAddressBytes+3, ipNettmaskBytes+0, ipNettmaskBytes+1,ipNettmaskBytes+2,ipNettmaskBytes+3, &nActive, quality)) == 10) 
					if (nRes == 14)
					{
				       /* pr_info("tarakernel: Interpretation %s: %d.%d.%d.%d:%d.%d.%d.%d-%s(%08X/%08X), InfID: %d, Severity: %d: Botnet: %d, Info: %s\n", (nActive?"":"(NOTE! INACTIVE infection)"), 
								ip1,ip2,ip3,ip4, nm1,nm2,nm3,nm4,
						//				(int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], (int)ipNettmaskBytes[0], (int)ipNettmaskBytes[1], (int)ipNettmaskBytes[21], (int)ipNettmaskBytes[3],
												quality, ipAddress, ipNettmask,
												nInfectionId, nSeverity, nBotnetId, cInfo);
										*/

						pr_info("tarakernel: Interpretation %s: %pI4:%pI4, InfID: %d, Severity: %d: Botnet: %d, Info: %s\n", (nActive?"":"(NOTE! INACTIVE infection)"), 
										&ip_be, &nett_be,
						//				(int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], (int)ipNettmaskBytes[0], (int)ipNettmaskBytes[1], (int)ipNettmaskBytes[21], (int)ipNettmaskBytes[3],
						    						nInfectionId, nSeverity, nBotnetId,cInfo);

				    	if (nActive)
						{
						    //struct _InfectionSpecification *pInfection = (struct _InfectionSpecification *)storeInstruction(nBlockDescriptor, ipAddress, ipNettmask, port, quality);	//NOTE! Defined in module_configuration.c
						    //
							struct _Node *pNode = storeInstruction(nBlockDescriptor, ip_be, nett_be, port=0, quality);	//NOTE! Defined in module_configuration.c
							if (pNode)
							{
								struct _InfectionSpecification *pInfection = &pNode->cInfection;
								if (pInfection)
								{
									//Store additional data.. 
									pInfection->nInfectionId = nInfectionId;
									pInfection->nSeverity = nSeverity;
									pInfection->nBotnetId = nBotnetId;
									pInfection->lpInfo = memAlloc(strlen(cInfo)+1);
									if (pInfection->lpInfo)
										strcpy(pInfection->lpInfo, cInfo);
								}
							}
						}
						else
							removeInfection(ip_be, ipNettmask, port);
					}
					else
						pr_info("tarakernel: *************** ERROR! (res: %d) Infection: %s Interpretation failed\n", nRes, lpPointer);
				}

				//pr_info("tarakernel: Checking list after insertion...\n");
				//listInfectionsPointerList();
				break;
  
			case BLOCK_DESCRIPTIOR_PARTNERS:
				{
				pr_info("About to read partners: %s\n", lpPointer); 
					char *lpColon = strchr(lpPointer, ':');
				//No idea why sscanf is returning 1 instead of 2 and cNettmask is undefine: if ((nRes = sscanf(lpPointer, "%s:%s", cIP, cNettmask)) >0) 
					if (lpColon)
					{
						*lpColon = 0;
						pr_info("Partner:%s/%s\n", lpPointer, lpColon + 1);
						storePartner(lpPointer, lpColon + 1);
					}
					else
						pr_info("tarakernel: Interpretation of partner failed: %s\n", lpPointer);
				}
				break;

            case BLOCK_DESCRIPTIOR_INSPECT:
            case BLOCK_DESCRIPTIOR_DROP:
				{
					//Format:  IP:Nettmask  e.g:  E4442EF5:FFFFFFFF
					//char cIP[10];//, cNettmask[10];
					int nRes;
					char *lpColon = strchr(lpPointer, ':');
					//char lpIp, lpNettmask;
					//No idea why sscanf is returning 1 instead of 2 and cNettmask is undefine: if ((nRes = sscanf(lpPointer, "%s:%s", cIP, cNettmask)) >0) 
					if (lpColon)
					{
						*lpColon = 0;
						pr_info("tarakernel: Inspection directive:%s/%s\n", lpPointer, lpColon + 1);
						storeInspectionDirective(nBlockDescriptor, lpPointer, lpColon + 1);
					}
					else
						pr_info("tarakernel: Interpretation of inspection directive failed (res=%d): %s\n", nRes, lpPointer);
				}
				break;
				
			case BLOCK_DESCRIPTIOR_HONEYPORT:
				{
					//Format:  22:block^
					char *lpColon = strchr(lpPointer, ':');

					if (lpColon)
					{
						*lpColon = 0;
						pr_info("tarakernel: Honeyport directive:%s/%s\n", lpPointer, lpColon + 1);
						storeHoneyport(lpPointer, lpColon + 1);
					}
					else
						pr_info("tarakernel: Interpretation of honeyport directive failed: %s\n", lpPointer);
				}
				break;
				
			case BLOCK_DESCRIPTIOR_ASSIST:
		        {
		            //Format: <ip>:<port>-<quality>-<want spoofed>-<active>^  e.g: 7F000001:0-0-0-1^
					storeAssistanceRequest(lpPointer);    //See module_configuration.c
		        }
				break;
        }

		if (!lpFound)
    	{
           	pr_info("tarakernel: ****** ERROR ****** Pointer was NULL... Aborting\n");
           	return NULL;
		}

		lpPointer = lpFound + 1;
		nCountInstructions++;

		if (*lpPointer == '|')
		{
			//Should always get here.......
			++lpPointer; 
			break;
		}		

	}
	pr_info("%d instruction(s) handled.\n", nCountInstructions);

	return lpPointer;// + strlen(lpPointer) +1;
}

void module_storeConfiguration(char *lpConfiguration)
{
	char *lpBlockSeparator;
	long int bBatchNumber = -1;
        //bReceivedConfiguration = 0; //So that nobody should check while processing.... NOTE! Means that forwarded traffic will be blocked... So don't do this
        int bReceived = 0;
        

	//Should check if it's such handling instructions....
	//Format:	<batch number>|<what's next>|<ip-address>:<port>-<action>^<next.....>|<what's next>
	//Where where <what's next> is [MORE|EOF|SERVERS|INFECTIONS|BLACKLIST|WHITELIST]

	//Ex format:	<batch number>|<ip-address>:<port>-<action>^<next.....>[there is more|end of list]
	//E.g: "1|SERVERS|192.168.1.20:8080-clean^192.168.1.20:64-nobot|EOF"; 

	while (1)
	{
		pr_info("tarakernel: In the loop with configuration: %s\n", lpConfiguration);

		lpBlockSeparator = strchr(lpConfiguration, '|');

		if (!lpBlockSeparator)
		{
			//Nothing after this. Last word should be MORE or EOF

			if (strstr(lpConfiguration, "EOF") == lpConfiguration)
			{
				pr_info("tarakernel: EOF found. Quitting\n");
				break;
			}
			if (!strcmp(lpConfiguration, "MORE"))
			{
				pr_info("tarakernel: End of list found, but should request more.. (not yet implemented). Quitting\n");
				break;
			}
			
			pr_info("tarakernel: ERROR! End of list found, but no proper postfix.. Quitting\n");

			break;
		}

		*lpBlockSeparator = 0;

		if (bBatchNumber == -1)
		{
			//First batch is supposed to be the batch number
			if (kstrtol(lpConfiguration, 0, &bBatchNumber) != 0)
        			pr_info("tarakernel: Error running kstrtol()\n");
			
			pr_info("tarakernel: Batch number found: %d\n", (int)bBatchNumber);
			lpConfiguration = lpBlockSeparator + 1;
			continue;
		}

		//Now there's supposed to be instructions on what's next  [SERVERS|INFECTIONS|BLACKLIST|WHITELIST] 	
		//lpBlockSeparator = strchr(lpConfiguration, '|');
		/*if (!lpBlockSeparator)
		{
			pr_info("tarakernel: ERROR No block descriptor [SERVERS|INFECTIONS...] found. Quitting\n");
			break;
		}
		*lpBlockSeparator = 0;
		*/

		pr_info("tarakernel: Block descriptor found: %s\n", lpConfiguration);

		if (!strcmp(lpConfiguration, "SERVERS"))
		{
			bReceived = 1;
//			pr_info("tarakernel: Skipping saving servers..\n");
			lpConfiguration = interpretNextBatch(BLOCK_DESCRIPTIOR_SERVERS, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "BLACK_LIST") || !strcmp(lpConfiguration, "WHITE_LIST"))
		{
			bReceived = 1;
//			pr_info("tarakernel: Skipping saving colored list..\n");
			lpConfiguration = interpretColoredList(lpConfiguration, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "INFECTION"))
		{
			bReceived = 1;
//			pr_info("tarakernel: Skipping saving colored list..\n");
//			lpConfiguration = interpretInfectons(lpConfiguration, lpBlockSeparator+1);
			lpConfiguration = interpretNextBatch(BLOCK_DESCRIPTIOR_INFECTIONS, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "PARTNER"))
		{
			bReceived = 1;
//			pr_info("tarakernel: Skipping saving colored list..\n");
//			lpConfiguration = interpretInfectons(lpConfiguration, lpBlockSeparator+1);
			lpConfiguration = interpretNextBatch(BLOCK_DESCRIPTIOR_PARTNERS, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "INSPECT") || !strcmp(lpConfiguration, "DROP"))
		{
			bReceived = 1;
			pr_info("tarakernel: About to save inspection directive..\n");
			lpConfiguration = interpretInspection(lpConfiguration, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "SETUP"))
		{
			bReceived = 1;
			pr_info("tarakernel: About to save setup..\n");
			lpConfiguration = interpretSetup(lpConfiguration, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "HONEY"))
		{
			bReceived = 1;
			pr_info("tarakernel: About to save honeyport..\n");
			lpConfiguration = interpretNextBatch(BLOCK_DESCRIPTIOR_HONEYPORT, lpBlockSeparator+1);
		}
		else if (!strcmp(lpConfiguration, "ASSIST"))
		{
			bReceived = 1;
			pr_info("tarakernel: About to save assist request..\n");
			lpConfiguration = interpretNextBatch(BLOCK_DESCRIPTIOR_ASSIST, lpBlockSeparator+1);
		}
		else
		{
			pr_info("tarakernel: ERROR! Unknown block descriptor found: %s\n", lpConfiguration);
			lpConfiguration = lpBlockSeparator+1;
		}

		pr_info("tarakernel: After interpreting, this is next: %s\n", lpConfiguration);
	}
        bReceivedConfiguration |= bReceived; //So that nobody should check while processing....	
	pr_info("tarakernel: Configuration received set to: %s\n", (bReceivedConfiguration?"Received.":"******** NOT RECEIVED ******** (no changes?)"));
        
}

