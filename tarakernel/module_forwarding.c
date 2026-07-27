//module_forwarding.c

struct _tagSpecification {
	//This structure holds our tag information
	unsigned int category : 2; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	unsigned int botNetId;	//Assigned by AkiliBomba
};

int checkFixTagging(struct _PacketInspection *pPacket, bool bForwarding, const struct nf_hook_state *state)
{
	char *lpPrOrFw = (bForwarding?"FW":"PR");

	struct _InfectionSpecification *pInfected = isInfected(pPacket->ip_header->saddr);
	int nSenderIsInfected = (pInfected?pInfected->cTag.presumed_infected:0);
	int nRequestedAssistance = requestedAssistance(pPacket->ip_header->daddr, pPacket->dPort);
	short bCommentPrinted = 0;  //Set to 1 to indicate that comment has been printed (otherwise print default at the end...
	char cInfectionStatus[200];
	sprintf(cInfectionStatus, "%s%s %s", (nSenderIsInfected?"Sender is INFECTED!":""),
	                      (nSenderIsInfected && nRequestedAssistance? " and":""),
	                      (nRequestedAssistance? " receiver has requested ASSISTANCE!":"")); 
	        
	//pr_info("tarakernel: FW - in checkFixTagging for %s->%s\n", pPacket->cSourceIp, pPacket->cDestIp);

	if (pSetup->cShowInstructions.bits.doTagging)
	{
		//First check if this unit has requested assistance alleviating brute force/D-DOS attack
			
		//Check if requested data that is less likely to be infected than this (drop the traffic)
		if (nRequestedAssistance && nRequestedAssistance < nSenderIsInfected)   
		{
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: %s: TARGET HAS REQUESTED ASSISTANCE! DROPPING PACKAGE FROM INFECTED: %s->%s, request: %d, this IP: %d\n", lpPrOrFw, pPacket->cSourceIp, pPacket->cDestIp, nRequestedAssistance, nSenderIsInfected);

			//kfree(pPacket); Being done by caller...
			//checkFree(pPacket..)		Being done by caller...
			return NF_DROP;
		}
		else
		{
			if (nRequestedAssistance) //This unit is under attack or chose to turn of receiving tagged traffic
			{       
				char *lpThisComputer = (!nSenderIsInfected?"not infected": "less severely tagged");       //nRequestedAssistance < nSenderIsInfected
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: %s Target has requested assistance, but this unit is %s (so sending)..: %s->%s, request: %d, this IP: %d\n", lpPrOrFw, lpThisComputer, pPacket->cSourceIp, pPacket->cDestIp, nRequestedAssistance, nSenderIsInfected);
			}
                              
			if (nSenderIsInfected)
				if (tagThePacket(pPacket, state, pInfected) == NF_STOLEN)
					return NF_STOLEN;
		}

		if (pSetup->cShowInstructions.bits.showForwardPartner)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: %s to partner (changed): %s->%s: Tag: %04X, presumed_inf: %u, severity: %u\n", lpPrOrFw, pPacket->cSourceIp, pPacket->cDestIp, 
							pPacket->tcp_header->urg_ptr, 
							pInfected?pInfected->cTag.presumed_infected:0,
							pInfected?pInfected->nSeverity:0 );
	}
	else
  		if (!bCommentPrinted) //Already printed on this package... no need for more.	
       		if (pSetup->cShowInstructions.bits.showForwardPartner)
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: %s: to partner - %s - TAGGING DISABLED\n", lpPrOrFw, cInfectionStatus);

	if (!bCommentPrinted)		
		if (nSenderIsInfected || nRequestedAssistance)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: %s: ****** %s (sending package)\n", lpPrOrFw, cInfectionStatus);

	return NF_ACCEPT;
}

int clearIncomingTag(struct _PacketInspection *pPacket)
{
	//Should read from setting? Later based on the recipient??
	return 1; //false;
}

static unsigned int module_forwarding_handler(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
		
	if (!bReceivedConfiguration)
	{
	    pr_info("tarakernel: Dropping forwarded package until configuration is received (please start taralink).\n");
		return NF_DROP;
	}
	
	if (!skb)
	{
		pr_info("tarakernel: ***** FW ERROR - no skb record. Aborting.\n");
		return NF_ACCEPT;
	}

	//Just checking if mark is set in PRE_ROUTING

	#ifdef ALTERNATIVE_TAGGING
	
	struct nf_conn *ct;
	enum ip_conntrack_info ctinfo;

	ct = nf_ct_get(skb, &ctinfo);
	if (ct) {
		printk(KERN_INFO "tarakernel: FORWARD   ct=%px mark=%u ctinfo=%d\n", ct, ct->mark, ctinfo);		

		if (ct->mark == 0) 
			pr_info("tarakernel: ****** ERROR - mark was not set in FORWARD handler\n");
		else
			pr_info("tarakernel: ****** Mark was set in FORWARD handler\n");
	}
	else
		pr_info("tarakernel: ****** ERROR - Unable to get conntrack info\n");
	#endif

	//pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
	//initPacket(pPacket, skb, state);
	struct _PacketInspection *pPacket = getPacketInfo(priv, skb, state);

	testing("FW", pPacket);

	if (pPacket->ip_header->protocol != IPPROTO_TCP)
		return NF_ACCEPT;	//260320 - not sure about this......

	struct _InfectionSpecification *pInfected = isInfected(pPacket->ip_header->saddr);	//Check if packet is from infected unit in my subnet

	if (pPacket->dPort == 22 && pInfected)
	{
		pr_info("tarakernel: FW: Dropping traffic from infected unit to port 22 (ssh) %s:%d -> %s:%d (severity: %d)\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, pInfected->nSeverity);		
		return NF_DROP;
	}

    checkThatTcp(pPacket,"start of forward handler");	//260320 - asdf... got problem with this....

	if (pPacket->tcp_header->urg)
		if (pSetup->cShowInstructions.bits.showUrgentPtrUsage)
			pr_info("tarakernel: FW: URG flag is set! urg_ptr set to %04X. %s->%s \n", pPacket->tcp_header->urg_ptr, pPacket->cSourceIp, pPacket->cDestIp);

	if (isPartner(pPacket->ip_header->daddr))
	{
		//Packet from our own subnet going to a partner and their subnet.
		bool bForwarding = true;
		int nRetval = checkFixTagging(pPacket, bForwarding, state);	//state may be NF_INET_FORWARD??

		pr_info("tarakernel: FW: Forwarding to partner (after tagging).. %s->%s, urg_ptr %04X.\n", pPacket->cSourceIp, pPacket->cDestIp, pPacket->tcp_header->urg_ptr);

		#ifdef ALTERNATIVE_TAGGING

		//For now, always set this for test..
		bool set_tsval = 1;
		bool set_tsecr = 0;	//Don't know what we can use this for.

		__be32 new_tsval_be = 0b011111;	//6 bit
        __be32 new_tsecr_be;

		__be32 tsval_be, tsecr_be;

		if (tcp_read_timestamp_option(skb, &tsval_be, &tsecr_be)) 
		{
			if (tcp_set_timestamp_option(skb, set_tsval, new_tsval_be, set_tsecr, new_tsecr_be))
			{
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: ******* TSval tagging successful!\n");
			}
			else
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: ******* Failed to tag using TSval field\n");
		}
		else
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: **** Unable to read TSval\n");

		#endif

		checkFree(pPacket, nRetval != NF_ACCEPT /*bLeavingPostRouting*/);

		//ØT - need to check why unable to get the tag...
		union _TagUnion cUnion;
		cUnion.nTag = pPacket->tcp_header->urg_ptr;
		pr_info("tarakernel: **** FW: Checking outbound tag: %pI4:%d -> %pI4:%d tag: %u, severity: %u\n (packet->nTag: %u)", 
			&pPacket->ip_header->saddr, pPacket->sPort, &pPacket->ip_header->saddr, pPacket->dPort, cUnion.nTag, cUnion.cTag.presumed_infected, pPacket->nTag);

		return nRetval;
	}

	if (isPartner(pPacket->ip_header->saddr)) 	
	{
		//Inbound traffic from partner.. Check if tagged
		//unsigned int nTag = tcp_header->urg_ptr;
		//struct _Tag cTag;
		union _TagUnion cUnion;
		
		//cTag = 	(struct _Tag)tcp_header->urg_ptr;
		cUnion.nTag = pPacket->tcp_header->urg_ptr;
		if (pSetup->cShowInstructions.bits.showForwardPartner)
			if (!dropFromLogging(pPacket))
	  			pr_info("tarakernel: FW from partner: %s->%s: Tag: (%04X)\n", pPacket->cSourceIp, pPacket->cDestIp, pPacket->tcp_header->urg_ptr);
  			
		if (pPacket->tcp_header->urg_ptr)
  			pSetup->cGlobalStatistics.nFromPartnerTagged++;
		else
  			pSetup->cGlobalStatistics.nFromPartnerUntagged++;

		if (clearIncomingTag(pPacket))
		{
			pPacket->tcp_header->urg_ptr = 0;  //Remove the tag when forwarded to subnet.. This is confidential information..
			pPacket->tcp_header->urg = 0;		//260318 This may have been forgotten elsewhere....
	    	//recalcChecksum(pPacket);	//ØT 260318 - Seems like lots of packets get lost with this enabled...
			checkFree(pPacket, false /*bLeavingPostRouting*/);
		}
		else	
			pr_info("tarakernel: Keeping tag!\n");

		return NF_ACCEPT;
	}	    

	//To check traffic between two nodes in local network running through router, get rid of the rest... 
  	//#define C_INTERNAL_IP "192.168"
	//if (strstr(lpIpFrom,C_INTERNAL_IP) != lpIpFrom || strstr(lpIpTo,C_INTERNAL_IP) != lpIpTo)

	bool bDMine = isMeOrMine(pPacket->ip_header->daddr);
	bool bSMine = isMeOrMine(pPacket->ip_header->saddr);

	if (!bDMine||!bSMine)
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
					if (!dropFromLogging(pPacket))
						pr_info("tarakernel: Traffic with forwarded port: %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);///%s\n", ipFrom, ipTo);
			}
		}
		
		pSetup->cGlobalStatistics.nForwarded++;
		if (!bPortForwarded && pSetup->cShowInstructions.bits.showForwardNonPartner)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: FW Forward (to or from non-partner) %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);///%s\n", ipFrom, ipTo);
		return NF_ACCEPT;
	}

	//NOTE! Gets here when it's not traffic from or to partner and not traffic between two nodes in the internal network. 
	//Meaning it's traffic between sub net and non-partnering.... 

	{
		u32 nBigEndian = swappedEndian(pSetup->nMyIp); 
		sprintf(pSetup->c100, "%u.%u.%u.%u", IPADDRESS(nBigEndian));
	}

	if (bDMine||bSMine)
	{
		if (pSetup->cShowInstructions.bits.showForwardNonPartner)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: FW Traffic between subnet and non-partner: (%s -> %s - I'm %s)\n", pPacket->cSourceIp, pPacket->cDestIp, pSetup->c100);
	}
	else
	{
		if (!dropFromLogging(pPacket))
			pr_info("tarakernel: ********* Shouldn't get here (forwarding between two unknown addresses?) - most likely wrong IP or partner setup) - (%s -> %s while I'm %s)\n", pPacket->cSourceIp, pPacket->cDestIp, pSetup->c100);
    }
        
	return NF_ACCEPT;
}


