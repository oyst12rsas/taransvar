//module_forwarding.c

struct _tagSpecification {
	//This structure holds our tag information
	unsigned int category : 2; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	unsigned int botNetId;	//Assigned by AkiliBomba
};

/*
 * Record a policy decision made by tarakernel itself. This deliberately says
 * only what the gateway knows: the packet was rejected. Demo/test ownership is
 * not inferred here; dbserver correlates the ordinary traffic tuple with a
 * demo that was registered centrally.
 */
static void reportRejectedTraffic(struct _PacketInspection *pPacket)
{
	int n;

	if (!pPacket || !pSetup)
		return;

	for (n = 0; n < C_TRAFFIC_REPORT_ARRAY_SIZE; n++)
	{
		struct _ipPort2 *pRec = &cPendingRejectedReportArr[n];

		if (pRec->sIp == pPacket->ip_header->saddr &&
			pRec->dIp == pPacket->ip_header->daddr &&
			pRec->sPort == pPacket->sPort &&
			pRec->dPort == pPacket->dPort)
		{
			pRec->nCount++;
			if (pPacket->tcp_header->urg_ptr || !pRec->nTag)
				pRec->nTag = pPacket->tcp_header->urg_ptr;
			break;
		}

		if (!pRec->sIp)
		{
			pRec->sIp = pPacket->ip_header->saddr;
			pRec->dIp = pPacket->ip_header->daddr;
			pRec->sPort = pPacket->sPort;
			pRec->dPort = pPacket->dPort;
			pRec->nCount = 1;
			pRec->nTag = pPacket->tcp_header->urg_ptr;
			pRec->nAction = e_TrafficRejected;
			break;
		}
	}

	if (n == C_TRAFFIC_REPORT_ARRAY_SIZE)
	{
		pr_warn_ratelimited("tarakernel: rejected-traffic report queue full; unable to record rejected packet\n");
		return;
	}

	/* Rejections are security-significant; do not wait for normal batching. */
	scheduleImmediateTrafficReport();
}

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

			reportRejectedTraffic(pPacket);
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
 		if (!bCommentPrinted)
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
		/*
		 * Fail open while the userspace controller is unavailable.  Before
		 * configuration arrives we do not know which destinations are TaraSec
		 * partners, so tagging every Internet TCP packet would be unsafe.  The
		 * userspace health reporter raises the degraded-assurance alert and
		 * partner-aware severity-1 tagging resumes once configuration exists.
		 */
		pr_warn_ratelimited("tarakernel: Protection unavailable: taralink configuration has not been received; allowing forwarded traffic without TaraSec inspection.\n");
		return NF_ACCEPT;
	}
	
	if (!skb)
	{
		pr_info("tarakernel: ***** FW ERROR - no skb record. Aborting.\n");
		return NF_ACCEPT;
	}

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

	struct _PacketInspection *pPacket = getPacketInfo(priv, skb, state);

	testing("FW", pPacket);

	if (pPacket->ip_header->protocol != IPPROTO_TCP)
		return NF_ACCEPT;

	struct _InfectionSpecification *pInfected = isInfected(pPacket->ip_header->saddr);

	if (pPacket->dPort == pSetup->nAdminSshPort && pInfected && pInfected->nSeverity > pSetup->nBlockSshThreshold)
	{
		pr_info("tarakernel: FW: Dropping traffic from infected unit to protected SSH port %u %s:%d -> %s:%d (severity/threshold: %d/%d)\n", pSetup->nAdminSshPort, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, pInfected->nSeverity, pSetup->nBlockSshThreshold);
		reportRejectedTraffic(pPacket);
		return NF_DROP;
	}

    checkThatTcp(pPacket,"start of forward handler");

	if (pPacket->tcp_header->urg)
		if (pSetup->cShowInstructions.bits.showUrgentPtrUsage)
			pr_info("tarakernel: FW: URG flag is set! urg_ptr set to %04X. %s->%s \n", pPacket->tcp_header->urg_ptr, pPacket->cSourceIp, pPacket->cDestIp);

	if (isPartner(pPacket->ip_header->daddr))
	{
		bool bForwarding = true;
		int nRetval = checkFixTagging(pPacket, bForwarding, state);

		tk_debug(3, "FW: Forwarding to partner after tagging: %s->%s, tag=%04X\n", pPacket->cSourceIp, pPacket->cDestIp, pPacket->tcp_header->urg_ptr);

		#ifdef ALTERNATIVE_TAGGING
		bool set_tsval = 1;
		bool set_tsecr = 0;

		__be32 new_tsval_be = 0b011111;
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

		union _TagUnion cUnion;
		cUnion.nTag = pPacket->tcp_header->urg_ptr;
		tk_debug(3, "FW: outbound tag %pI4:%d -> %pI4:%d tag=%u severity=%u packet_tag=%u\n", 
			&pPacket->ip_header->saddr, pPacket->sPort, &pPacket->ip_header->daddr, pPacket->dPort, cUnion.nTag, cUnion.cTag.presumed_infected, pPacket->nTag);

		return nRetval;
	}

	if (isPartner(pPacket->ip_header->saddr)) 	
	{
		union _TagUnion cUnion;
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
			pPacket->tcp_header->urg_ptr = 0;
			pPacket->tcp_header->urg = 0;
			checkFree(pPacket, false /*bLeavingPostRouting*/);
		}
		else	
			pr_info("tarakernel: Keeping tag!\n");

		return NF_ACCEPT;
	}	    

	bool bDMine = isMeOrMine(pPacket->ip_header->daddr);
	bool bSMine = isMeOrMine(pPacket->ip_header->saddr);

	if (!bDMine||!bSMine)
	{
		unsigned int nCheckIfPortForwarding = (bDMine?pPacket->dPort:(bSMine?pPacket->dPort:0)); 
		bool bPortForwarded = 0;
		if (nCheckIfPortForwarding)
		{
			if (portForwarded(nCheckIfPortForwarding))
			{
				bPortForwarded = 1;
				if (pSetup->cShowInstructions.bits.showOther)
					if (!dropFromLogging(pPacket))
						pr_info("tarakernel: Traffic with forwarded port: %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
			}
		}
		
		pSetup->cGlobalStatistics.nForwarded++;
		if (!bPortForwarded && pSetup->cShowInstructions.bits.showForwardNonPartner)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: FW Forward (to or from non-partner) %s:%d->%s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
		return NF_ACCEPT;
	}

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
