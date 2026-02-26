//module_forwarding.c

struct _tagSpecification {
	//This structure holds our tag information
	unsigned int category : 2; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	unsigned int botNetId;	//Assigned by AkiliBomba
};




int checkFixTagging(struct _PacketInspection *pPacket, bool bForwarding)
{
	char *lpPrOrFw = (bForwarding?"FW":"PR");
	int nSenderIsInfected = isInfected(pPacket->ip_header->saddr);
	int nRequestedAssistance = requestedAssistance(pPacket->ip_header->daddr, pPacket->dPort);
	short bCommentPrinted = 0;  //Set to 1 to indicate that comment has been printed (otherwise print default at the end...
	char *lpInfectionStatus = memAlloc(200);
	sprintf(lpInfectionStatus, "%s%s %s", (nSenderIsInfected?"Sender is INFECTED!":""),
	                      (nSenderIsInfected && nRequestedAssistance? " and":""),
	                      (nRequestedAssistance? " receiver has requested ASSISTANCE!":"")); 
	        
	if (pSetup->cShowInstructions.bits.doTagging)
	{
		//First check if this unit has requested assistance alleviating brute force/D-DOS attack
			
		//Check if requested data that is less likely to be infected than this (drop the traffic)
		if (nRequestedAssistance && nRequestedAssistance < nSenderIsInfected)   
		{
			printk("tarakernel: %s: TARGET HAS REQUESTED ASSISTANCE! DROPPING PACKAGE FROM INFECTED: %s->%s, request: %d, this IP: %d\n", lpPrOrFw, pPacket->cSourceIp, pPacket->cDestIp, nRequestedAssistance, nSenderIsInfected);

			//kfree(pPacket); Being done by caller...
			kfree(lpInfectionStatus);
			return NF_DROP;
		}
		else
		{
			if (nRequestedAssistance) //This unit is under attack or chose to turn of receiving tagged traffic
			{       
				char *lpThisComputer = (!nSenderIsInfected?"not infected": "less severely tagged");       //nRequestedAssistance < nSenderIsInfected
				printk("tarakernel: %s Target has requested assistance, but this unit is %s (so sending)..: %s->%s, request: %d, this IP: %d\n", lpPrOrFw, lpThisComputer, pPacket->cSourceIp, pPacket->cDestIp, nRequestedAssistance, nSenderIsInfected);
                                    
			}
                              
			if (nSenderIsInfected)
			{
				//Outbound traffic to partner and tagging is turned on.. Tag it.
				union _TagUnion cUnion;
				cUnion.cTag.version_no = TAG_VERSION_NO;
				cUnion.cTag.presumed_infected = 5; //Presumably bot. TO DO: Diversify this....
				cUnion.cTag.botnet_id = 99; //To be assigned by Taransvar.. To be implemented later...
				pPacket->tcp_header->urg_ptr= cUnion.nBe16;//(__be16)cTag;//htons(0xFF00);  //Tag the package.
				pSetup->cGlobalStatistics.nOutboundTagged++;
			}
		}
		if (pSetup->cShowInstructions.bits.showForwardPartner)
		{
			printk("tarakernel: %s to partner: %s->%s: Tag: (%08X)\n", lpPrOrFw, pPacket->cSourceIp, pPacket->cDestIp, pPacket->tcp_header->urg_ptr);
		}
	}
	else
        {
  		if (!bCommentPrinted) //Already printed on this package... no need for more.	
        		if (pSetup->cShowInstructions.bits.showForwardPartner)
				printk("tarakernel: %s: to partner - %s - TAGGING DISABLED\n", lpPrOrFw, lpInfectionStatus);
                
	}

	if (!bCommentPrinted)		
		if (nSenderIsInfected || nRequestedAssistance)
			printk("tarakernel: %s: ****** %s (sending package)\n", lpPrOrFw, lpInfectionStatus);
	kfree(lpInfectionStatus);
	return NF_ACCEPT;
}

static unsigned int module_forwarding_handler(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
        struct _PacketInspection *pPacket;
	//static int nPackagesForwarded = 0;
	//u32 newGrossMemorySize;
	//u32 nCurrentHeaderSize;  //Given in number of 32bit words
	//u32 nOurTagSize;//, nOnlyShowCount;
		
	if (!bReceivedConfiguration)
	{
	        printk("tarakernel: Dropping forwarded package until configuration is received (please start taralink).\n");
		return NF_DROP;
	}
	
	if (!skb)
		return NF_ACCEPT;

	pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
	initPacket(pPacket, skb, state);

	if (pPacket->tcp_header->urg)
		if (pSetup->cShowInstructions.bits.showUrgentPtrUsage)
			printk("tarakernel: FW: URG flag is set! urg_ptr set to %08X. %s->%s \n", pPacket->tcp_header->urg_ptr, pPacket->cSourceIp, pPacket->cDestIp);

	if (isPartner(pPacket->ip_header->daddr))
	{
		bool bForwarding = true;
		int nRetval = checkFixTagging(pPacket, bForwarding);
		kfree(pPacket);
		return nRetval;
	}

	if (isPartner(pPacket->ip_header->saddr)) 	
	{
		//Inbound traffic from partner.. Check if tagged
		//unsigned int nTag = tcp_header->urg_ptr;
		//struct _Tag cTag;
		union _TagUnion cUnion;
		
		//cTag = 	(struct _Tag)tcp_header->urg_ptr;
		cUnion.nBe16 = pPacket->tcp_header->urg_ptr;
		if (pSetup->cShowInstructions.bits.showForwardPartner)
  			printk("tarakernel: FW from partner: %s->%s: Tag: (%08X)\n", pPacket->cSourceIp, pPacket->cDestIp, pPacket->tcp_header->urg_ptr);
  			
		if (pPacket->tcp_header->urg_ptr)
  			pSetup->cGlobalStatistics.nFromPartnerTagged++;
		else
  			pSetup->cGlobalStatistics.nFromPartnerUntagged++;

		pPacket->tcp_header->urg_ptr = 0;  //Remove the tag.. This is confidential information..
        kfree(pPacket);
		return NF_ACCEPT;
	}	    

	//To check traffic between two nodes in local network running through router, get rid of the rest... 
  	//#define C_INTERNAL_IP "192.168"
	//if (strstr(lpIpFrom,C_INTERNAL_IP) != lpIpFrom || strstr(lpIpTo,C_INTERNAL_IP) != lpIpTo)
	bool bSMine, bDMine;
	if (!(bDMine = isMeOrMine(pPacket->ip_header->daddr))||!(bDMine = isMeOrMine(pPacket->ip_header->saddr)))
	{
		//This is not traffic between two units in local network...
		//kfree(lpIpFrom);
		//kfree(lpIpTo);
		
		unsigned int nCheckIfPortForwarding = (bDMine?pPacket->dPort:(bSMine?pPacket->dPort:0)); 
		bool bPortForwarded = 0;
		if (nCheckIfPortForwarding)
		{
			if (portForwarded(nCheckIfPortForwarding))
			{
				bPortForwarded = 1;
				if (pSetup->cShowInstructions.bits.showOther)
					printk("tarakernel: Traffic with forwarded port: %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);///%s\n", ipFrom, ipTo);
			      
			}
		
		}
		
		pSetup->cGlobalStatistics.nForwarded++;
		if (!bPortForwarded && pSetup->cShowInstructions.bits.showForwardNonPartner)
			printk("tarakernel: FW Forward (to or from non-partner) %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);///%s\n", ipFrom, ipTo);
		return NF_ACCEPT;
	}

	//NOTE! Gets here when it's not traffic from or to partner and not traffic between two nodes in the internal network. 
	//Meaning it's traffic between sub net and non-partnering.... 

	{
		u32 nBigEndian = swappedEndian(pSetup->nMyIp); 
		sprintf(pSetup->c100, "%u.%u.%u.%u", IPADDRESS(nBigEndian));
	}

	if (isMeOrMine(pPacket->ip_header->daddr)||isMeOrMine(pPacket->ip_header->saddr))
	{
		if (pSetup->cShowInstructions.bits.showForwardNonPartner)
			printk("tarakernel: FW Traffic between subnet and non-partner: (%s -> %s - I'm %s)\n", pPacket->cSourceIp, pPacket->cDestIp, pSetup->c100);
	}
	else
	{
		printk("tarakernel: ********* Shouldn't get here (forwarding between two unknown addresses?) - most likely wrong IP or partner setup) - (%s -> %s while I'm %s)\n", pPacket->cSourceIp, pPacket->cDestIp, pSetup->c100);
        }
        
	return NF_ACCEPT;
}


