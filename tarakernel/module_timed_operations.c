//module_timed_operations.h

/*
 * Policy rejections use their own queue so an accepted packet with the same
 * tuple cannot be coalesced into a rejection before the report is flushed.
 * module_forwarding.c is included later in tarakernel.c and uses this queue.
 */
static struct _ipPort2 cPendingRejectedReportArr[C_TRAFFIC_REPORT_ARRAY_SIZE];

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

#define N_SENDBUF_SIZE 2000
//#define N_SENDBUF_SIZE 200

static int appendTrafficQueue(char *lpSendBuf, int bufSize,
                              struct _ipPort2 *pQueue, int nQueueSize)
{
    int n;
    int nWritten = 0;

    for (n = 0; n < nQueueSize; n++)
    {
        struct _ipPort2 *pRec = &pQueue[n];
        char cThisNode[150];
        int nMaxWrite;

        if (!pRec->sIp)
            break;

        snprintf(cThisNode, sizeof(cThisNode), "%08X-%X-%08X-%X-%X-%X-%X^",
                 swappedEndian(pRec->sIp), pRec->sPort,
                 swappedEndian(pRec->dIp), pRec->dPort,
                 pRec->nCount, pRec->nTag, pRec->nAction);

        nMaxWrite = bufSize - strlen(lpSendBuf);
        if (nMaxWrite <= strlen(cThisNode) + strlen("EOF"))
        {
            pr_info("tarakernel: ******* WARNING ******* Buffer is too small to hold more traffic info; remaining records will be sent on the next report.\n");
            break;
        }

        strcpy(lpSendBuf + strlen(lpSendBuf), cThisNode);
        memset(pRec, 0, sizeof(*pRec));
        nWritten++;
    }

    return nWritten;
}

bool getTrafficReport(char *lpSendBuf, int bufSize);
bool getTrafficReport(char *lpSendBuf, int bufSize)
{
    int nWritten = 0;

    pSetup->bTrafficReportsBeingHandled = true;
    strcpy(lpSendBuf, C_TRAFFIC_REPORT_PREFIX);

    /* Ordinary observations keep action 0; policy rejections are action 1. */
    nWritten += appendTrafficQueue(lpSendBuf, bufSize,
                                   pSetup->cPendingIncomingReportArr,
                                   C_TRAFFIC_REPORT_ARRAY_SIZE);
    nWritten += appendTrafficQueue(lpSendBuf, bufSize,
                                   cPendingRejectedReportArr,
                                   C_TRAFFIC_REPORT_ARRAY_SIZE);

    if (!nWritten)
    {
        if (pSetup->cShowInstructions.bits.doReportTraffic &&
            pSetup->cShowInstructions.bits.showOther)
            pr_info("tarakernel: No traffic to report to taralink...\n");

        pSetup->bTrafficReportsBeingHandled = false;
        return false;
    }

    strcpy(lpSendBuf + strlen(lpSendBuf), "EOF");
    pSetup->bTrafficReportsBeingHandled = false;
    return true;
}

bool trafficReportToTaralinkFound(int nProcessId)
{
    return false;
}

void sendCheckRequests(int nProcessId)
{
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
            break;
        }
                
        char *lpCheckWhat = (pSetup->cCheckThese[n].eCheckType == e_PossiblePartner? "partner?":"???");
        sprintf(lpSendBuf+nLen, "%s:%u^", lpCheckWhat, swappedEndian(pSetup->cCheckThese[n].ip));
    }

    if (n)
    {
	    memset(pSetup->cCheckThese, 0, sizeof(pSetup->cCheckThese));

        sendMessage(nProcessId, lpSendBuf);
        kfree(lpSendBuf);
    }
}

void sendTrafficReport(void);
void sendTrafficReport()
{
    if (pSetup->bTrafficReportsBeingHandled)
    {
        pr_info("tarakernel: ************* Dropping sending traffic report (requested by taralink) because already being handled..\n");
        return;
    }
    pSetup->bTrafficReportsBeingHandled = true;

    char *lpSendBuf = kmalloc(N_SENDBUF_SIZE, GFP_KERNEL);

    if (lpSendBuf && getTrafficReport(lpSendBuf, N_SENDBUF_SIZE))
        send_to_user(lpSendBuf);

    kfree(lpSendBuf);

    /* Keep the timer armed if either queue still contains unsent records. */
    pSetup->bSendTrafficReport =
        pSetup->cPendingIncomingReportArr[0].sIp ||
        cPendingRejectedReportArr[0].sIp;
}

void checkTimedOperation(void)
{
    if (pSetup->bSendTrafficReport ||
        pSetup->cPendingIncomingReportArr[0].sIp ||
        cPendingRejectedReportArr[0].sIp)
    {
        sendTrafficReport();
        return;
    }

/*
    241230 - probably not working.... checkRequestForStatus() is called with process id 0 below and such messages are never being sent... 
              checkRequestForStatus() is being called from hello_nl_recv_msg, so that's where it happens....

    if (pSetup->nLastTimedOperation)
    {
      u64 nCurrentTimestamp = ktime_get_coarse_real_ns();
      int nSecondsLapsed = (nCurrentTimestamp - pSetup->nLastTimedOperation) / (1000 * 1000 * 1000);
      
      if (nSecondsLapsed > 60)
      {
        pSetup->nLastTimedOperation = nCurrentTimestamp; 
        checkRequestForStatus(0, "dummy");
      }
    }
    else
      pSetup->nLastTimedOperation = ktime_get_coarse_real_ns();
*/    
}
