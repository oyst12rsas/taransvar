void checkRequestForStatus(int pid, char *lpPayload)
{
  //test_send_message(pid, "Not yet learned to assemble that status...");
  
  if (!pSetup->cShowInstructions.bits.showStatus)
  {
        if (pid)
              sendMessage(pid, "n/a");
        //printk(KERN_INFO "tarakernel: Show status is turned off\n");
        return;
  }
  
 /* struct _statistics {
    int nPreRouting;
    int nIncoming;
    int nOutgoing;
    int nForwarded;
    int nTagged;
    int nBlocked;
    int nFromPartnerTagged;
    int nFromPartnerUntagged;
    int nOutboundTagged;
    };
    */
    char *lpBuf = kmalloc(255 * sizeof(char), GFP_KERNEL);
    //char cBuf[200];
     
/*    sprintf(cBuf, "%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d", cGlobalStatistics.nPreRouting, 
          cGlobalStatistics.nIncoming, cGlobalStatistics.nOutgoing, 
          cGlobalStatistics.nForwarded, cGlobalStatistics.nTagged, 
          cGlobalStatistics.nBlocked,
          cGlobalStatistics.nFromPartnerUntagged, cGlobalStatistics.nFromPartnerTagged, cGlobalStatistics.nOutboundTagged
          ); */

    sprintf(lpBuf, "status|Prerout:\t%d\nIn:\t%d\nOut:\t%d\nForward:\t%d\nTagged:\t\t%d\nBlocked:\t\t%d\nFrom partner untagd:\t%d\nFrom partner tagd:\t%d\nOut tagd:\t%d\n", pSetup->cGlobalStatistics.nPreRouting, 
          pSetup->cGlobalStatistics.nIncoming, pSetup->cGlobalStatistics.nOutgoing, 
          pSetup->cGlobalStatistics.nForwarded, pSetup->cGlobalStatistics.nTagged, 
          pSetup->cGlobalStatistics.nBlocked,
          pSetup->cGlobalStatistics.nFromPartnerUntagged, pSetup->cGlobalStatistics.nFromPartnerTagged, pSetup->cGlobalStatistics.nOutboundTagged
          ); 

    if (pid)
      sendMessage(pid, lpBuf);  //NOTE! Always being call with pid=0 from module_timed_operations... so how are status messages sent?
      
    printk(KERN_INFO "tarakernel status:\n%s\n", lpBuf); // Printed when sending to taralink (don't print twice)
    kfree(lpBuf);
    
}

void checkPartner(u32 nIp)
{
        //Note: When sending: u32 nBigEndian = swappedEndian(pSetup->cCheckThese[n].ip);
        
        int n;
        
        for (n = 0; n < C_CHECK_ARRAY_SIZE; n++)
        {
                if (pSetup->cCheckThese[n].ip == nIp)
                      return;   //Already notified about this..
                      
                if (!pSetup->cCheckThese[n].ip)
                {
                      pSetup->cCheckThese[n].ip = nIp;
                      pSetup->cCheckThese[n].eCheckType = e_PossiblePartner;
                      return;
                }
        }

        // (n == C_CHECK_ARRAY_SIZE)
        //The array is full but no need to make any fuss about that.. (once some partners get registered, we can notify about others..)
}
