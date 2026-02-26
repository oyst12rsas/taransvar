//module_configuration.c

/*	This module is planned to hold functions for storing and retrieving configuration info
	For now(!!!), pConfiguration contains a table with one element for each of 
	BLOCK_DESCRIPTIOR_SERVERS and the others.. Each element holds a pointer to an array of elements
	of typre relevant to the BLOCK_DESCRIPTOR. Later, when the arrays get to big to fit in on erray 
	(max size seems to be 4096 byte??), each element can be and array of pointers to other blocks
	that holds the arrays..  
*/

int configuraton_init(void)
{
        if (!pSetup)  //Just in case...
        {
                pSetup = kmalloc(sizeof (struct _Setup), GFP_KERNEL);
        	memset(pSetup, 0, sizeof(struct _Setup));
                strncpy(pSetup->cTrafficPrefix, C_TRAFFIC_REPORT_PREFIX, strlen(C_TRAFFIC_REPORT_PREFIX)); //Init buffer so can send the prefix + cPendingIncomingReportArr to report incoming traffic. 
	
/*
        memset(pConfiguration, 0, sizeof(pConfiguration));
	memset(nConfigurationArraySize, 0, sizeof(nConfigurationArraySize));
	memset(nElementsInArray, 0, sizeof(nElementsInArray));
	memset(&cGlobalStatistics, 0, sizeof(cGlobalStatistics));

*/
        }
	
	

	if (sizeof(cBlockDescriptor)/sizeof(cBlockDescriptor[0]) < BLOCK_DESCRIPTIOR_LAST+1)
	{
		printk("tarakernel: ***** ERROR! Too few elements in cBlockDescriptor. Gonna crash!\n");
		return 0;
	}
	else
	    return 1;
}

u32 swappedEndian(u32 nUInt32Val)
{
	volatile uint32_t nConvertedIP;
        unsigned char* ipAddressBytes = (unsigned char*)&nUInt32Val;
	unsigned char* ipConvertedBytes = (unsigned char*)&nConvertedIP;

        ipConvertedBytes[0] = ipAddressBytes[3];
	ipConvertedBytes[1] = ipAddressBytes[2];
	ipConvertedBytes[2] = ipAddressBytes[1];
	ipConvertedBytes[3] = ipAddressBytes[0];
	return nConvertedIP; 
}

void listServers(void)
{
	int n;
	char cBuf[255];
	struct _ServerSpecification *pServerArray = (struct _ServerSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_SERVERS];
	memset(cBuf, 0, sizeof(cBuf));

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_SERVERS];n++)
	{
		//unsigned char* ipAddressBytes = (unsigned char*)&pServerArray[n].ipAddress;
		//sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X)", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pServerArray[n].ipAddress);
		sprintf(cBuf+strlen(cBuf), "%d-%d ", pServerArray[n].port, pServerArray[n].requests);
	}

	printk("tarakernel: Servers in array: %s\n", cBuf);
}

void listInspections(int nThelist)
{
	int n;
	char cBuf[255];
	
        if (!nThelist)
        {
              printk("IP addresses to inspect: ");
              listInspections(BLOCK_DESCRIPTIOR_INSPECT);    
              printk("IP addresses to drop: ");
              listInspections(BLOCK_DESCRIPTIOR_DROP);
              return;
        }
	
	struct _InspecitonSpecification *pInspectionArray = (struct _InspecitonSpecification *)pSetup->pConfiguration[nThelist];
	memset(cBuf, 0, sizeof(cBuf));

	for (n=0;n<pSetup->nElementsInArray[nThelist];n++)
	{
		unsigned char* ipAddressBytes = (unsigned char*)&pInspectionArray[n].ipAddress;
		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X)", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pInspectionArray[n].ipAddress);
	}

	printk("tarakernel: IPs in array: %s\n", cBuf);
}

void listHoneyports(void)
{
	printk("tarakernel: Don't know yet how to count honeyports...\n");
}


void listInfectionsPointerList(void)
{
	int nCount = 0;
	char *cBuf = memAlloc(C_SEGMENT_MAX_SIZE);
	//struct _InfectionSpecification *pInfectionArray = (struct _InfectionSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];
	
	struct _Node *pNode = pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS];
		
	while (pNode) 
	{
	        nCount++;
	        if (strlen(cBuf) > C_SEGMENT_MAX_SIZE-30)
	        {
	            strcpy(cBuf+strlen(cBuf), "[truncated]";
	            break;
	        }
	
	        nCount++;
		unsigned char* ipAddressBytes = (unsigned char*)&pNode->cInfection.ipAddress;

		if (*cBuf)
			strcpy(cBuf+strlen(cBuf), ", ");

		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X)", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pInfectionArray[n].ipAddress);
	}

        int nBytesTaken = nCount * (sizeof(void*) + sizeof(struct _InfectionSpecification));
	printk("tarakernel: Infections in pointer list (bytes taken by %d: %d): %s\n", n, nBytesTaken, cBuf);
	
	kfree(cBuf);
}


void listInfections(void)
{
	printk("tarakernel: listInfections() is no longer supposed to be called... Aborting");
	return;
	int n;
	char cBuf[255];
	struct _InfectionSpecification *pInfectionArray = (struct _InfectionSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS];n++)
	{
	        if (strlen(cBuf) > sizeof(cBuf)-30)
	        {
	            strcpy(cBuf+strlen(cBuf), "[truncated]";
	            break;
	        }

		unsigned char* ipAddressBytes = (unsigned char*)&pInfectionArray[n].ipAddress;

		if (n)
			strcpy(cBuf+strlen(cBuf), ", ");

		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X)", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pInfectionArray[n].ipAddress);
	}

        int nBytesTaken = (unsigned char*)&pInfectionArray[n] - (unsigned char*)&pInfectionArray[0];
        char *lpShow;
        if (n<10)
          lpShow = cBuf;
        else
          lpShow = "<too many to show>";
	printk("tarakernel: Infections in array (bytes taken by %d: %d): %s\n", n, nBytesTaken, lpShow);
}//listInfections()

void listColored(int nBlockDescriptor)
{
	int n;
	char cBuf[255];
	struct _ColoredIpSpecification *pServerArray = (struct _ColoredIpSpecification *)pSetup->pConfiguration[nBlockDescriptor];
	memset(cBuf, 0, sizeof(cBuf));

	for (n=0;n<pSetup->nElementsInArray[nBlockDescriptor];n++)
	{
		unsigned char* ipAddressBytes = (unsigned char*)&pServerArray[n].ipAddress;
		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X), ", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pServerArray[n].ipAddress);
	}

	printk("tarakernel: IPs in array: %s\n", cBuf);

//	printk("tarakernel: listColored() disabled..\n");
}


int isListedForInspection(volatile uint32_t ipAddress)
{
	int n;
//	char cBuf[255];
	struct _InspecitonSpecification *pInspectArray = (struct _InspecitonSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INSPECT];
	//unsigned char* ipAddressBytes;

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INSPECT];n++)
	{
		if (pInspectArray[n].ipAddress == ipAddress)
		{
		//	sprintf(cBuf, "%08X is tagged for inspection! Should tag..\n", pInspectArray[n].ipAddress);
		//	printk(cBuf);
			return 1;
		}
		
	}

	//sprintf(cBuf, "%08X is not tagged for inspection..\n", ipAddress);
	//printk(cBuf);
	return 0;
}//isListedForInspection()


int isPartner(volatile uint32_t ipAddress)
{
	int n;
	//char cBuf[255];
	struct _PartnerSpecification *pPartnerArray = (struct _PartnerSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_PARTNERS];

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_PARTNERS];n++)
	{
		if (pPartnerArray[n].ipAddress == ipAddress)
		{
			/*unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;//pPartnerArray[n].ipAddress;
			sprintf(cBuf, "%d.%d.%d.%d(%08X) is identified as a partner! Should tag..\n", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pPartnerArray[n].ipAddress);
			printk(cBuf);*/
			return 1;
		}
		
	}

	/*	unsigned char* ipAddressBytes;
        ipAddressBytes = (unsigned char*)&ipAddress;//&pPartnerArray[n].ipAddress;
	sprintf(cBuf, "%d.%d.%d.%d(%08X) is not a partner.. No tagging..\n", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], ipAddress);
	printk(cBuf);*/
	return 0;
}

void listPartners(void)
{
	int n;
	char cBuf[255];
	struct _PartnerSpecification *pPartnerArray = (struct _PartnerSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_PARTNERS];
	memset(cBuf, 0, sizeof(cBuf));

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_PARTNERS];n++)
	{
		unsigned char* ipAddressBytes = (unsigned char*)&pPartnerArray[n].ipAddress;
		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X)", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pPartnerArray[n].ipAddress);
	}

	printk("tarakernel: IPs in array: %s\n", cBuf);
}

void storeColoredIp(int nBlockDescriptor, struct _ColoredIpSpecification *pElementsArray, volatile uint32_t ipAddress)
{
	struct _ColoredIpSpecification cNewElement;
	
	printk("tarakernel: Storing setup element. element#: %d, size of struct: %ld, slots in array: %d (tot size: %ld),  max block size: %d\n",
	      pSetup->nElementsInArray[nBlockDescriptor]+1,
	      sizeof(struct _ColoredIpSpecification),
	      pSetup->nConfigurationArraySize[nBlockDescriptor],
	      pSetup->nConfigurationArraySize[nBlockDescriptor] *sizeof(struct _ColoredIpSpecification), 
	      C_SEGMENT_MAX_SIZE);
	
	cNewElement.ipAddress = ipAddress;
	pElementsArray[pSetup->nElementsInArray[nBlockDescriptor]]= cNewElement;
	pSetup->nElementsInArray[nBlockDescriptor]++;
	listColored(nBlockDescriptor);
}

void storeColoredListElement(int nBlockDescriptor, volatile uint32_t ipAddress)
{
	unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
	void *pElement = pSetup->pConfiguration[nBlockDescriptor];
	printk("tarakernel: About to store: %d.%d.%d.%d\n", (int)ipAddressBytes[3],
		(int)ipAddressBytes[2], (int)ipAddressBytes[1], (int)ipAddressBytes[0]);

	if (!pElement)
	{
		//void *pArrayPointer;
		struct _ColoredIpSpecification* servers;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[nBlockDescriptor]);
	
		switch (nBlockDescriptor) 
		{
			case BLOCK_DESCRIPTIOR_WHITE_LIST:
			case BLOCK_DESCRIPTIOR_BLACK_LIST:
//				printk("tarakernel: About to learn to store block descriptor %d\n",	nBlockDescriptor);
				break;
			default:
				printk("tarakernel: ERROR unknown block descriptor. Aborting: %d\n",	nBlockDescriptor);
				return;
		}

	
	 	pSetup->nConfigurationArraySize[nBlockDescriptor] = (int) (C_SEGMENT_MAX_SIZE / sizeof(struct _ColoredIpSpecification))-1;

/*	Error probably here....	*/	servers = (struct _ColoredIpSpecification*) kmalloc(pSetup->nConfigurationArraySize[nBlockDescriptor]*sizeof(struct _ColoredIpSpecification), GFP_KERNEL);

                if (!servers)
		{
                	printk("tarakernel: ******** ERROR! Unable to allocate new memory for B/W listing. Aborting.\n");
                        return;
                }

		printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[nBlockDescriptor], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[nBlockDescriptor]);

		pElement = pSetup->pConfiguration[nBlockDescriptor] = servers;
	}
	else
		printk("tarakernel: Array exist with %d elements: %s\n", pSetup->nElementsInArray[nBlockDescriptor], cBlockDescriptor[nBlockDescriptor]);



	switch (nBlockDescriptor) 
	{
		case BLOCK_DESCRIPTIOR_WHITE_LIST:
		case BLOCK_DESCRIPTIOR_BLACK_LIST:
			storeColoredIp(nBlockDescriptor, pElement, ipAddress);
			break;
		default:
			printk("tarakernel: ERROR unknown block descriptor (in this func) : %s - aborting\n", cBlockDescriptor[nBlockDescriptor]);	
			return;
	}
}

int blackListed(__be32 ipAddress)
{
//	return 0;

	//***** NOTE! ipAddress in TCP package are Little Endian (Least significant byte first...Memory configuration will be stored the same way....)




	//iph->saddr = (__be32) ntohl(0xCC4FC5C8); - suggestion on how to write to saddr field
//	char cBuf[32];
//	u32 sip = ntohl(ipAddress);
	int n;
		
//	sprintf(cBuf, "%u.%u.%u.%u", IPADDRESS(sip));
//	printk("tarakernel: Checking if %s(%08X) is blacklisted\n", cBuf, ipAddress);

	struct _ColoredIpSpecification *pList = (struct _ColoredIpSpecification*) pSetup->pConfiguration[BLOCK_DESCRIPTIOR_BLACK_LIST];

	if (!pList)
	{
		//printk("tarakernel: No blacklist.. returning\n");
		return 0;
	}

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_BLACK_LIST];n++)	//asdf
	{
		struct _ColoredIpSpecification* pCheckThis = &pList[n];
		//char cBlack[30];
		//u32 sip = ntohl(pCheckThis->ipAddress);
		//printk("tarakernel: Checking: %u.%u.%u.%u(%08X)\n", IPADDRESS(sip),pCheckThis->ipAddress);

		if (pCheckThis->ipAddress == ipAddress)
		{
		        u32 sip = ntohl(ipAddress);
        	        if (pSetup->cShowInstructions.bits.doTagging)
                        {		
			        printk("tarakernel: Blacklisted address found: %u.%u.%u.%u(%08X)\n", IPADDRESS(sip), pCheckThis->ipAddress);
			        return 1;	//asdf
			}
			else
			{
			      printk("tarakernel: Blacklisted address found - BUT BLOCKING IS DISABLED: %u.%u.%u.%u(%08X)\n", IPADDRESS(sip), pCheckThis->ipAddress);
			      return 0;
			}
		}
	}


	return 0;

}

void storeServerInfo(int port, char *lpQuality)
{
	struct _ServerSpecification cNewElement, *pServerArray;
	cNewElement.port = port;
	cNewElement.requests = C_REQUESTS_CLEAN;

	pServerArray = (struct _ServerSpecification*) pSetup->pConfiguration[BLOCK_DESCRIPTIOR_SERVERS];
	pServerArray[pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_SERVERS]]= cNewElement;
	pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_SERVERS]++;
	listServers();
}

void storeInfectionInPointerList(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, char *lpQuality)
{	
/*struct _InfectionSpecification {
	volatile uint32_t ipAddress;
	volatile uint32_t ipNettmask;
	struct _threatSpecification cThreat;
};*/

/*	struct _InfectionSpecification cNewElement;
	struct _InfectionSpecification *pInfectionArray;
	cNewElement.ipAddress = ipAddress;
	cNewElement.ipNettmask = ipNettmask;
	int n;
*/
        _Node *pNode = getNewBefore(pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS], int nStructSize);
        pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS] = pNode;
	pNode->cInfection.ipAddress = ipAddress;
	pNode->cInfection.ipNettmask = ipNettmask;

/*struct _threatSpecification {
	//Info to be passed on for specific address or range of addresses
	unsigned int category : 3; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	unsigned int botNetId;	//Assigned by AkiliBomba
};*/
	if (!strcmp(lpQuality, "firsttime"))
	{
		//cNewElement.cThreat. = C_REQUESTS_CLEAN;
		//NOTE! category is char:3 - value 0-7
		pNode->cInfection.cThreat.category = 7; //Somewhere in the middle... Just for test for now...
	}
	else
		pNode->cInfection.cThreat.category = 7; //Somewhere in the middle... Just for test for now...
	
	listInfectionsPointerList();
}//storeInfectionInPointerList()


void storeInfection(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, char *lpQuality)
{	
/*struct _InfectionSpecification {
	volatile uint32_t ipAddress;
	volatile uint32_t ipNettmask;
	struct _threatSpecification cThreat;
};*/

	
#ifndef USE_POINTER_LIST
	storeInfectionInPointerList(ipAddress, ipNettmask, lpQuality);
#else
      
	      printk("tarakernel: ********** ERROR storeInfection() is no longer supposed to be called... Aborting\n");
      return;

		struct _InfectionSpecification cNewElement;
		struct _InfectionSpecification *pInfectionArray;
		cNewElement.ipAddress = ipAddress;
		cNewElement.ipNettmask = ipNettmask;
		int n;
	

/*struct _threatSpecification {
	//Info to be passed on for specific address or range of addresses
	unsigned int category : 3; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	unsigned int botNetId;	//Assigned by AkiliBomba
};*/
	if (!strcmp(lpQuality, "firsttime"))
	{
		//cNewElement.cThreat. = C_REQUESTS_CLEAN;
		//NOTE! category is char:3 - value 0-7
		cNewElement.cThreat.category = 7; //Somewhere in the middle... Just for test for now...
	}
	else
		cNewElement.cThreat.category = 7; //Somewhere in the middle... Just for test for now...

	pInfectionArray = (struct _InfectionSpecification*) pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];

	//First check if there's available slots
	for (n=0; n < pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS];n++)
	{
		if (pInfectionArray[n].ipAddress == 0 || pInfectionArray[n].ipAddress == ipAddress)
		{
			pInfectionArray[n]= cNewElement;
			break;
		}
	}

	if (n >= pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS])
	{
		pInfectionArray[pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS]]= cNewElement;
		pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS]++;
	}
	
	listInfections();
#endif
	
}//storeInfection()


int isInfectedPointerList(volatile uint32_t ipAddress)
{
	int n;
	
	struct _InfectionSpecification *pInfectionArray = (struct _InfectionSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];

	for (struct _Node *pNode = pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS]; pNode; pNode = pNode->pNext)
	{
		if (ipAddress == pNode->cInfection.ipAddress)
		{
		        //NOTE! ******************* NEED TO FIX THE THREAT CATEGORIES *************
		        printk("tarakernel: **** Traffic from infected unit! Threat category: %d\n", pInfectionArray[n].cThreat.category); 
		        return pNode->cInfection.cThreat.category;
                }
	}

	//printk("tarakernel: Not infected unit.....\n");
	return 0;
}

int isInfected(volatile uint32_t ipAddress)
{
        return isInfectedPointerList(ipAddress);
/*        
	int n;
	struct _InfectionSpecification *pInfectionArray = (struct _InfectionSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS];n++)
	{
		if (ipAddress == pInfectionArray[n].ipAddress)
		{
		        //NOTE! ******************* NEED TO FIX THE THREAT CATEGORIES *************
		        printk("tarakernel: **** Traffic from infected unit! Threat category: %d\n", pInfectionArray[n].cThreat.category); 
		        return pInfectionArray[n].cThreat.category;
                }
	}

	//printk("tarakernel: Not infected unit.....\n");
	return 0;
  */
}//isInfected()

void removeInfectionFromPointerList(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, short port)
{
	int n;

	for (struct _Node **pNodePointer = &pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS]; *pNodePointer; pNodePointer = &pNode->pNext)
		if (ipAddress == (*pNodePointer)->cInfection.ipAddress)
		{
			struct _Node *pDeleteThis = *pNodePointer;
			pNodePointer = pDeleteThis->pNext;
			kfree(pDeleteThis);
		        return;
                }

	printk("tarakernel: ***** WARNING Disabled infection not found when trying to remove if from pointer list.....\n");
	return;
}

void removeInfection(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, short port)
{
        removeInfectionFromPointerList(ipAddress, ipNettmask, port);
        return;
        
/*	int n;
	struct _InfectionSpecification *pInfectionArray = (struct _InfectionSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_INFECTIONS];

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_INFECTIONS];n++)
	{
		if (ipAddress == pInfectionArray[n].ipAddress)
		{
		        //NOTE! ******************* NOTE! Unused spaced will (probably?) be used next time *************
		        memset(&pInfectionArray[n], 0, sizeof(struct _InfectionSpecification));
		        return;
                }
	}

	printk("tarakernel: ***** WARNING Disabled infection not found when trying to remove if from list.....\n");
	return;
	*/
}


void storePartner(char *lpIP, char *lpNettmask)
{
	//NOTE! This function is based on the generic storeInstruction()
	struct _PartnerSpecification cNewElement;
	long unsigned int nVal;
	volatile uint32_t nUInt32Val;
	int nError;
	//unsigned char* ipAddressBytes;
	//char cConvertedIp[4];

        struct _PartnerSpecification* pPartners = pSetup->pConfiguration[BLOCK_DESCRIPTIOR_PARTNERS];

        if (!pPartners)
	{
		//void *pArrayPointer;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_PARTNERS]);
	
		pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_PARTNERS] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _PartnerSpecification)-1;

		pPartners = (struct _PartnerSpecification*) kmalloc(pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_PARTNERS]*sizeof(struct _PartnerSpecification), GFP_KERNEL);
		printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_PARTNERS], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_PARTNERS]);

		pSetup->pConfiguration[BLOCK_DESCRIPTIOR_PARTNERS] = pPartners;
	}
	else
		printk("tarakernel: Array exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_PARTNERS]);
	
	if ((nError = kstrtoul(lpIP, 16, &nVal)))
		printk("tarakernel: kstrtoul returned %d for ip (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIP);
	
        //Make the IP address little endian (least significant byte first, because that's how we receive it in IP header)
        nUInt32Val = (volatile uint32_t)nVal;
        cNewElement.ipAddress = swappedEndian(nUInt32Val);

	printk("tarakernel: Trying to turn IP: %08X -> %08X\n", nUInt32Val, cNewElement.ipAddress); 

	if ((nError = kstrtoul(lpNettmask, 16, &nVal)))
		printk("tarakernel: kstrtoul returned %d for nettmask\n", nError);
	
	cNewElement.ipNettmask = swappedEndian(nVal);

	pPartners[pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_PARTNERS]]= cNewElement;
	pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_PARTNERS]++;
	listPartners();
}//storePartner()


void storeInspectionDirective(int nBlockDescriptor, char *lpIP, char *lpNettmask)
{
	//NOTE! This function is based on the generic storePartner() (Because both are storing the IP in HEX format)
	struct _InspecitonSpecification cNewElement;
	long unsigned int nVal;
	volatile uint32_t nUInt32Val;
	int nError;
	unsigned char* ipAddressBytes;
	//char cConvertedIp[4];
	volatile uint32_t nConvertedIP;

        struct _InspecitonSpecification* pInspections = pSetup->pConfiguration[nBlockDescriptor];

        if (!pInspections)
	{
		//void *pArrayPointer;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[nBlockDescriptor]);
	
		pSetup->nConfigurationArraySize[nBlockDescriptor] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _InspecitonSpecification)-1;

		pInspections = (struct _InspecitonSpecification*) kmalloc(pSetup->nConfigurationArraySize[nBlockDescriptor]*sizeof(struct _InspecitonSpecification), GFP_KERNEL);
		printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[nBlockDescriptor], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[nBlockDescriptor]);

		pSetup->pConfiguration[nBlockDescriptor] = pInspections;
	}
	else
		printk("tarakernel: Array exist: %s\n", cBlockDescriptor[nBlockDescriptor]);
	
	if ((nError = kstrtoul(lpIP, 16, &nVal)))
		printk("tarakernel: kstrtoul returned %d for ip (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpIP);
	
        //Make the IP address little endian (least significant byte first, because that's how we receive it in IP header)
        nUInt32Val = (volatile uint32_t)nVal;
        ipAddressBytes = (unsigned char*)&nUInt32Val;
	unsigned char* ipConvertedBytes = (unsigned char*)&nConvertedIP;
	//Original: unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;

        ipConvertedBytes[0] = ipAddressBytes[3];
	ipConvertedBytes[1] = ipAddressBytes[2];
	ipConvertedBytes[2] = ipAddressBytes[1];
	ipConvertedBytes[3] = ipAddressBytes[0];
	//nConvertedIP = (volatile uint32_t) &cConvertedIp; 
	
	//Original: unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
	
	
	cNewElement.ipAddress = nConvertedIP;//nVal;  
	printk("tarakernel: Trying to turn IP: %08X -> %08X\n", nUInt32Val, nConvertedIP); 

	if ((nError = kstrtoul(lpNettmask, 16, &nVal)))
		printk("tarakernel: kstrtoul returned %d for nettmask\n", nError);
	
	cNewElement.ipNettmask = nVal;

	pInspections[pSetup->nElementsInArray[nBlockDescriptor]]= cNewElement;
	pSetup->nElementsInArray[nBlockDescriptor]++;
	listInspections(0);
}//storeInspectionDirective()





void storeHoneyport(char *lpPort, char *lpHandling)
{
	int nError;
	//NOTE! This function is based on the generic storeInspectionDirective() 
	struct _HoneyportSpecification cNewElement;
	//volatile uint32_t nUInt32Val;
	unsigned long nVal;

        struct _HoneyportSpecification* pHoneyports = pSetup->pConfiguration[BLOCK_DESCRIPTIOR_HONEYPORT];

        if (!pHoneyports)
	{
		//void *pArrayPointer;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_HONEYPORT]);
	
		pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_HONEYPORT] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _HoneyportSpecification)-1;

		pHoneyports = (struct _HoneyportSpecification*) kmalloc(pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_HONEYPORT]*sizeof(struct _HoneyportSpecification), GFP_KERNEL);
		printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_HONEYPORT], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_HONEYPORT]);

		pSetup->pConfiguration[BLOCK_DESCRIPTIOR_HONEYPORT] = pHoneyports;
	}
	else
		printk("tarakernel: Array exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_HONEYPORT]);
	
	if ((nError = kstrtoul(lpPort, 16, &nVal)))
		printk("tarakernel: kstrtoul returned %d for port (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpPort);
	
	cNewElement.port = (u32) nVal; 

	pHoneyports[pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_HONEYPORT]]= cNewElement;
	pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_HONEYPORT]++;
	listHoneyports();
}//storeInspectionDirective()


void listAssistRequests()
{
	int n;
	char cBuf[255];
	struct _AssistanceRequest *pArray = (struct _AssistanceRequest *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_ASSIST];
	memset(cBuf, 0, sizeof(cBuf));

	for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_ASSIST];n++)
	{
		unsigned char* ipAddressBytes = (unsigned char*)&pArray[n].ipAddress;
		sprintf(cBuf+strlen(cBuf), "%d.%d.%d.%d(%08X), ", (int)ipAddressBytes[0], (int)ipAddressBytes[1], (int)ipAddressBytes[2], (int)ipAddressBytes[3], pArray[n].ipAddress);
	}

	printk("tarakernel: IPs in array: %s\n", cBuf);
//  printk("tarakernel: Not yet learned to list assist requests...\n");

}



void storeAssistanceRequest(char *lpSpec)
{
        //Format: <ip>:<port>-<quality>-<want spoofed>-<active>^  e.g: 7F000001:0-0-0^
	char cIP[50];
	int nConverted, nError;
	char *lpFound; 
	long unsigned int nVal;
	unsigned int nPort, nQuality, nWantsSpoofed, nActive;
	//NOTE! This function is based on the generic storeInspectionDirective() 
	struct _AssistanceRequest cNewElement;

        printk("tarakernel: Storing assist request... \n");

        struct _AssistanceRequest* pRequests = pSetup->pConfiguration[BLOCK_DESCRIPTIOR_ASSIST];

        if (!pRequests)
	{
		//void *pArrayPointer;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_ASSIST]);
	
		pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_ASSIST] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _AssistanceRequest)-1;

		pRequests = (struct _AssistanceRequest*) kmalloc(pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_ASSIST]*sizeof(struct _AssistanceRequest), GFP_KERNEL);
		printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_ASSIST], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_ASSIST]);

		pSetup->pConfiguration[BLOCK_DESCRIPTIOR_ASSIST] = pRequests;
	}
	else
		printk("tarakernel: Array exist: %s\n", cBlockDescriptor[BLOCK_DESCRIPTIOR_ASSIST]);

        //Convert 7F000001:0-0-0-1 to  7F000001 0 0 0 1
        while ((lpFound = strchr(lpSpec, ':')))
            *lpFound = ' ';
        while ((lpFound = strchr(lpSpec, '-')))
            *lpFound = ' ';

	printk("tarakernel: About to convert: %s\n", lpSpec);
	if (sscanf(lpSpec, "%s %u %u %u %u", cIP, &nPort, &nQuality, &nWantsSpoofed, &nActive) == 5)
	{
	  printk("tarakernel: Able to convert...\n");
	}
	else
	{
	  printk("tarakernel: *********** Error converting (got %d)...\n", nConverted);
	}

//	if ((nError = kstrtoul(lpPort, 16, &nVal)))
//		printk("tarakernel: kstrtoul returned %d for port (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, lpPort);

	if ((nError = kstrtoul(cIP, 16, &nVal)))
		printk("tarakernel: *********** kstrtoul returned %d for ip (ERANGE=%d, EINVAL=%d) for %s\n", nError, ERANGE, EINVAL, cIP);

        if (nActive)
        {
		cNewElement.ipAddress = swappedEndian(nVal); //Store IP-address as "little endian" unsigned int.
        	cNewElement.port = nPort;
        	cNewElement.nQuality = nQuality;
        	cNewElement.bWantsSpoofed = nWantsSpoofed;

		pRequests[pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_ASSIST]]= cNewElement;
		pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_ASSIST]++;
	}
	else
	{
	        printk("********* Instructed to remove assistance request...\n");
		int n;
		struct _AssistanceRequest *pArray = (struct _AssistanceRequest *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_ASSIST];

		for (n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_ASSIST];n++)
		{
		        if (pArray[n].ipAddress == swappedEndian(nVal))
		        {
		        	printk("tarakernel: Requested to remove assistance request.. but for now just nulling it out...\n");
				cNewElement.ipAddress = 0;
        			cNewElement.port = 0;
        			cNewElement.nQuality = 0;
        			cNewElement.bWantsSpoofed = 0;
  				pRequests[n]= cNewElement;
  				break;
		        }
		}

	        //asdf
	}
	listAssistRequests();
}//storeAssistanceRequest()


int requestedAssistance(unsigned int ipAddress, unsigned int nPort)
{
	//Check if this ip (and port) has requrested assistance alleviating brute force /D-DOS attack
	//asdf
	struct _AssistanceRequest* pRequests = pSetup->pConfiguration[BLOCK_DESCRIPTIOR_ASSIST];

	for (int n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_ASSIST];n++)	
	{
		struct _AssistanceRequest* pCheckThis = &pRequests[n];
		//u32 sip = ntohl(pCheckThis->ipAddress);
		//printk("tarakernel: Checking assistance req: %u.%u.%u.%u(%08X)\n", IPADDRESS(sip),pCheckThis->ipAddress);

		if (pCheckThis->ipAddress == ipAddress)
		{
		        if (!pCheckThis->nQuality) {
		                printk("tarakernel: ****** ERROR Quality requested was 0, changing to 5\n");
		                pCheckThis->nQuality = 5;
		        }
	                return pCheckThis->nQuality;	//Found the IP (should also check if port is specified)
	        }
	}
	return 0; //IP address not found...
}


void storeInstruction(int nBlockDescriptor, volatile uint32_t ipAddress, volatile uint32_t ipNettmask, int port, char *lpQuality)
{
	//Use this to extract the 4 unsighed chars if required:
	//	unsigned char* ipAddressBytes = (unsigned char*)&ipAddress;
	//	printk("%d.%d.%d.%d", (int)ipAddressBytes[3], (int)ipAddressBytes[2],
	//		(int)ipAddressBytes[1], (int)ipAddressBytes[0]);

	void *pElement = pSetup->pConfiguration[nBlockDescriptor];
	if (!pElement)
	{
		void *pArrayPointer;
		printk("tarakernel: Array doesn't exist: %s\n", cBlockDescriptor[nBlockDescriptor]);
	
		switch (nBlockDescriptor) 
		{
			case BLOCK_DESCRIPTIOR_SERVERS:
			{	//(struct _ServerSpecification)[]
				struct _ServerSpecification* servers;
				pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_SERVERS] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _ServerSpecification)-1;

				servers = (struct _ServerSpecification*) kmalloc(pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_SERVERS]*sizeof(struct _ServerSpecification), GFP_KERNEL);
				printk("tarakernel: Array created for %s. Mem: %d elements: %d\n", cBlockDescriptor[nBlockDescriptor], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_SERVERS]);

				pArrayPointer = servers;
				break;
			}
			case BLOCK_DESCRIPTIOR_INFECTIONS:
			{
				if (USE_POINTER_LIST)
				{
					//Don't do anything here when using pointer list...
					pArrayPointer = NULL;
				}
				else
				{
					struct _InfectionSpecification* infections;
					//asdfasdf
					pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_INFECTIONS] = (int) C_SEGMENT_MAX_SIZE / sizeof(struct _InfectionSpecification)-1;

					infections = (struct _InfectionSpecification*) kmalloc(pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_INFECTIONS]*sizeof(struct _InfectionSpecification), GFP_KERNEL);
					printk("tarakernel: Array created for %s. Bytes: %d elements: %d, element size: %d\n", cBlockDescriptor[nBlockDescriptor], C_SEGMENT_MAX_SIZE, pSetup->nConfigurationArraySize[BLOCK_DESCRIPTIOR_INFECTIONS], sizeof(struct _InfectionSpecification));
					pArrayPointer = infections;
				}
				break;
			}
			case BLOCK_DESCRIPTIOR_WHITE_LIST:
//				break;
			case BLOCK_DESCRIPTIOR_BLACK_LIST:
				printk("tarakernel: ERROR black/while lists are saved elsewhere %d\n",	nBlockDescriptor);
				break;
			default:
				printk("tarakernel: ERROR unknown block descriptor: %d\n",	nBlockDescriptor);
		}

		pSetup->pConfiguration[nBlockDescriptor] = pArrayPointer;

	}
	else
		if (!USE_POINTER_LIST)
			printk("tarakernel: Array exist: %s\n", cBlockDescriptor[nBlockDescriptor]);

	switch (nBlockDescriptor) 
	{
		case BLOCK_DESCRIPTIOR_SERVERS:
			storeServerInfo(port, lpQuality);
			printk("tarakernel: Been storing server info\n");
			break;

		case BLOCK_DESCRIPTIOR_INFECTIONS:
			storeInfection(ipAddress, ipNettmask, lpQuality);
			break;
		case BLOCK_DESCRIPTIOR_WHITE_LIST:
		case BLOCK_DESCRIPTIOR_BLACK_LIST:
			printk("tarakernel: ERROR Colored lists are stored elsewhere.. %d\n",	nBlockDescriptor);
			break;
		default:
			printk("tarakernel: ERROR unknown block descriptor: %d\n",	nBlockDescriptor);
	}
}//storeInstruction()

void releaseConfiguration(void)
{
	printk("tarakernel: Should learn how to clean up the configuration properly/n");
/*	int n;
	for (n=0;n<sizeof(pSetup->pConfiguration);n++)
		if (pConfiguration[n])
			kfree(pSetup->pConfiguration[n]);
*/
}

bool portForwarded(unsigned int nCheckIfPortForwarding)
{
	struct _ServerSpecification *pServerArray = (struct _ServerSpecification *)pSetup->pConfiguration[BLOCK_DESCRIPTIOR_SERVERS];

	for (int n=0;n<pSetup->nElementsInArray[BLOCK_DESCRIPTIOR_SERVERS];n++)
		if (pServerArray[n].port == nCheckIfPortForwarding)
			return true;

	return false;
}//portForwarded()


