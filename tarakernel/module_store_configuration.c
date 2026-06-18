
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

        pr_info("tarakernel: Setup saved: %pI4, %pI4, %pI4, %02X\n",&pSetup->nMyIp, &pSetup->nInternalIp, &pSetup->nNettmask, pSetup->cShowInstructions.nValues);
            
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
    						&nm1,&nm2,&nm3,&nm4,
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

				    	if (nActive)	//Not sure if we should remove deactivated infections... Should be tested.
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
									{
										strcpy(pInfection->lpInfo, cInfo);
										pr_info("tarakernel: Info set to %s and severity to %u\n", pInfection->lpInfo, pInfection->nSeverity);
									}
									else
										pr_info("tarakernel: ********* ERROR allocating memory for threat info: %s and severity to %u\n", pInfection->lpInfo, pInfection->nSeverity);
								}
								else 
									pr_info("tarakernel: *********** ERROR ******** No infection found..\n");
							}
							else
							{
								int nCount = 0;
								struct _Node *pInfection;

								for (pInfection = pSetup->pConfigurationPointerList[nBlockDescriptor]; pInfection; pInfection = pInfection->pNext)
									nCount++;

								pr_info("tarakernel: *********** ERROR ******** No node found.. (%d currently in the list)\n", nCount);
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
				pr_info("tarakernel: About to read partners: %s\n", lpPointer); 
					char *lpColon = strchr(lpPointer, ':');
				//No idea why sscanf is returning 1 instead of 2 and cNettmask is undefine: if ((nRes = sscanf(lpPointer, "%s:%s", cIP, cNettmask)) >0) 
					if (lpColon)
					{
						*lpColon = 0;
						pr_info("tarakernel: Partner:%s/%s\n", lpPointer, lpColon + 1);
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
	pr_info("tarakernel: %d instruction(s) handled.\n", nCountInstructions);

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

