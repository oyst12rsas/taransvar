//module_timed_operations.h

void reportInfectionsList(void);
void reportInfectionsList(void)
{
      //pr_info("tarakernel: Should report list of infections...\n");
	struct _Node *pNode = (struct _Node *)(pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS]);
      pr_info("tarakernel: Registered infections: ");
      int nFound = 0;

	while (pNode)
	{
            nFound++;
		volatile uint32_t ipAddress = swappedEndian((u32) pNode->cInfection.ipAddress);
		unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
            
        	pr_info("tarakernel: %d.%d.%d.%d(%08X), ", (int)ipAddressBytes[3], (int)ipAddressBytes[2], (int)ipAddressBytes[1], (int)ipAddressBytes[0], ipAddress);
            //*** NOTE Should convert all IP adresses to __be32 and just print like  */
            //pr_info("tarakernel: %pI4(%08X), ",pNode->cInfection.ipAddress, pNode->cInfection.ipAddress);

            pNode = pNode->pNext;
      }

      if (nFound)
            pr_info("\n");

      pr_info("tarakernel: %d infections found\n", nFound);
}

void debugRoutine(void)
{
      //You may do debugging here and pr_info("tarakernel: This ends in the log..."); to print.
      //  doPointerTest();
      //  doInfectionsPointerListTest();

      //reportInfectionsList();
}

char *bufferToHex(char *lpBuffer, int len, char* lpTarget, int nBufSize); //To avoid compiler warning...
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

void doPointerTest(void);

bool trafficReportToTaralinkFound(int nProcessId)
{

    if (!pSetup->cShowInstructions.bits.doReportTraffic)  //No need to do anything further if not supposed to report traffic...
    {
        pSetup->cPendingIncomingReportArr[0].sIp = 0;   //To avoid that one with wrong time is being inserte in table if reporting is being turend on later. 
        pSetup->cPendingIncomingReportArr[0].dIp = 0;
        return false;
    }

    #define N_SENDBUF_SIZE 2000
//    #define N_SENDBUF_SIZE 200
    int n;
    char *lpSendBuf = kmalloc(N_SENDBUF_SIZE, GFP_KERNEL);
        
    if (!lpSendBuf)
    {
        pr_info("tarakernel: **** ERROR ****** Couldn't allocate buffer in trafficReportTotaralinkFound()\n");
        return 0;
    }
        
	//memset(lpSendBuf, 0, N_SENDBUF_SIZE);
	strcpy(lpSendBuf, C_TRAFFIC_REPORT_PREFIX);

    for (n = 0; n < C_TRAFFIC_REPORT_ARRAY_SIZE; n++)
    {
    	if (!pSetup->cPendingIncomingReportArr[n].sIp)
            break;
                
        struct _ipPort2 *pRec = &pSetup->cPendingIncomingReportArr[n];
        //OT_Changed:260225 - now also sending the tag...
        //sprintf(lpSendBuf+strlen(lpSendBuf), "%08X-%X-%X-%X^", swappedEndian(pRec->ip), pRec->sPort, pRec->dPort, pRec->nCount);
        //ØT 260305 - Here's where sending...
        char cThisNode[150];

        snprintf(cThisNode, sizeof(cThisNode), "%08X-%X-%08X-%X-%X-%X^", swappedEndian(pRec->sIp), pRec->sPort, swappedEndian(pRec->dIp), pRec->dPort, pRec->nCount, pRec->nTag);

        int nMaxWrite = N_SENDBUF_SIZE - strlen(lpSendBuf);

        if (nMaxWrite <= strlen(cThisNode)+strlen("EOF"))
        {
            pr_info("tarakernel: ******* WARNING ******* Buffer is too small to hold traffic info.. Increase from current %d or chop it up.\n", N_SENDBUF_SIZE);
            break;
        }

		strcpy(lpSendBuf+strlen(lpSendBuf), cThisNode);

		//Clear this spot so it can be used for next report...
        pSetup->cPendingIncomingReportArr[n].sIp = 0;  
        pSetup->cPendingIncomingReportArr[n].dIp = 0;   //Not necessary....
    }
        
    if (n == 0)
	{
    	if (pSetup->cShowInstructions.bits.doReportTraffic) //NOTE Never gets her if this is set because checking first in the function (including here is the other deleted)
    	    if (pSetup->cShowInstructions.bits.showOther)
                pr_info("tarakernel: No traffic to report to taralink...\n");
        kfree(lpSendBuf);
        return 0;
    }

	//Used to copy all to the buffer for sending before clearing... But now clearing just after the data put in buffer (see end of for loop above) 
	//memset(pSetup->cPendingIncomingReportArr, 0, sizeof(pSetup->cPendingIncomingReportArr));

    strcpy(lpSendBuf+strlen(lpSendBuf), "EOF");
	
	//Send traffic message to taralink. 
    sendMessage(nProcessId, lpSendBuf);
    kfree(lpSendBuf);
    return 1;
}

void sendCheckRequests(int nProcessId)
{
    //For now only sending requests to check partner and IP setup after finding forwarded traffic where neither sender or receiver is own IP..
    char *lpSendBuf = kmalloc(N_SENDBUF_SIZE, GFP_KERNEL);
        
    int n;
    strcpy(lpSendBuf, "CHECK|");
        
    for (n = 0; n < C_CHECK_ARRAY_SIZE; n++)
    {
        if (!pSetup->cCheckThese[n].ip)
            break;

        int nLen = strlen(lpSendBuf);
                
        if (nLen + 20 > N_SENDBUF_SIZE)
        {
            pr_info("tarakernel: ***** ERROR *** Buffer for traffic to check is too small (weird that gets here)...\n");
            break;  //Shouldn't get here... Error message just in case...
        }
                
        char *lpCheckWhat = (pSetup->cCheckThese[n].eCheckType == e_PossiblePartner? "partner?":"???");
        sprintf(lpSendBuf+nLen, "%s:%u^", lpCheckWhat, swappedEndian(pSetup->cCheckThese[n].ip));
    }

    if (n)
    {
        //Clean up the array for new check requests
	    memset(pSetup->cCheckThese, 0, sizeof(pSetup->cCheckThese));

        sendMessage(nProcessId, lpSendBuf);
        kfree(lpSendBuf);
    }
}

void checkTimedOperation(void)
{
/*
    241230 - probably not working.... checkRequestForStatus() is called with process id 0 below and such messages are never being sent... 
              checkRequestForStatus() is being called from hello_nl_recv_msg, so that's where it happens....
        
  NOTE! Commenting it out may change things, though, because pSetup->nLastTimedOperation is altered....

    if (pSetup->nLastTimedOperation)
    {
      u64 nCurrentTimestamp = ktime_get_coarse_real_ns();
      int nSecondsLapsed = (nCurrentTimestamp - pSetup->nLastTimedOperation) / (1000 * 1000 * 1000);
      
      //printk(KERN_INFO "tarakernel: %llu nanosec..\n", nCurrentTimestamp - nLastTimedOperation);
      
      if (nSecondsLapsed > 60)
      {
        pSetup->nLastTimedOperation = nCurrentTimestamp; 
       // printk(KERN_INFO "tarakernel: %d seconds lapsed.. Consider sending status..\n", nSecondsLapsed);
        //NOTE! Probably never sends request because checkRequestForStatus only sends if processId > 0 (and that's 1st param)
        checkRequestForStatus(0, "dummy");
      }
    }
    else
      pSetup->nLastTimedOperation = ktime_get_coarse_real_ns();
*/    
}
