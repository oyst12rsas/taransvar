
/*
This function is called whenever there's IPv4 TCP traffic and returns NF_ACCEPT or NF_DROP to tell how the package should be handled. It can also choose to repost the package and send NF_DROP
*/

//#define C_IP_BLOCK_ADDRESS1 "195.88.54.16"//VG.NO
//#define C_IP_BLOCK_ADDRESS2 "195.88.55.16"//VG.NO

//My address on the one.com VPS server little endian (least significant byte first)
//#define C_MY_IP 0x62125851
//#define C_MY_IP 0x51581262
//#define C_MY_IP "98.18.88.81"
//#define C_MY_IP "81.88.18.98"

//#define C_MY_IP "192.168.0.110"

static unsigned int requestActionOutbound(char *lpStr)	
{
	/* To be changed. Check in memory structure:
	- Two arrays of pointers, one for IPv4 and one for IPv6
	- Each element contains a struct of an "Array Type Identifier(ATI)" and an array of elements that specifies a range of addresses and instructions on how to handle them. 
	- Each range instruction has the format:
		<ip-from><port-from><ip-to><port-to><instruction>
		instructions on how to handle them.  
	- "Array Type Identifier (ATI)" can be (more to be included):
		1: 
		
*/ 
	//return NF_ACCEPT;
	return NF_DROP;
}

void initPacket(struct _PacketInspection *pPacket, struct sk_buff *skb, const struct nf_hook_state *state, bool bSetupOwned)
{
	u32 ip; //NOTE! Need this one because the IPADDRESS macro seems not to work on pointers

	pPacket->skb = skb;
	pPacket->state = state;
	pPacket->ip_header = ip_hdr(skb); 

    ip = pPacket->sIp = ntohl(pPacket->ip_header->saddr);
	sprintf(pPacket->cSourceIp, "%u.%u.%u.%u", IPADDRESS(ip));

    ip = pPacket->dIp = ntohl(pPacket->ip_header->daddr);
	sprintf(pPacket->cDestIp, "%u.%u.%u.%u", IPADDRESS(ip));

	pPacket->nTotLen = pPacket->ip_header->tot_len;

	pPacket->tcp_header= (struct tcphdr *)((__u32 *)pPacket->ip_header+ pPacket->ip_header->ihl);
	pPacket->nTcpHeaderSize = (int)(pPacket->tcp_header->doff*32/8);	//doff is number of 32bit words.. Change to bytes...
	
	pPacket->sPort = htons((unsigned short int) pPacket->tcp_header->source);
	pPacket->dPort = htons((unsigned short int) pPacket->tcp_header->dest);
	
	pPacket->nTag = pPacket->tcp_header->urg_ptr;	//OT_Changed - 
	pPacket->bSetupOwned = bSetupOwned;
}

void checkFree(struct _PacketInspection *pPacket, bool bLeavingPostRouting)
{
	if (bLeavingPostRouting)
	{
		struct nf_conn *ct;
		enum ip_conntrack_info ctinfo;

		ct = nf_ct_get(pPacket->skb, &ctinfo);
		if (ct) {
			ct->mark = 0;
			//pr_info("tarakernel: Leaving POST_ROUTING - clearing ct-mark");
		}
	}

	if (!pPacket->bSetupOwned || bLeavingPostRouting)	//Tried not to clear after POST_ROUTING, but got messed up when tarakernel restarts because conntrack still keeps the session active... 
	{
//		pr_info("tarakernel: *********** Destroying pPacket\n"); //Should never get here anymore...
//		return;
		//pPacket should be freed

		//If owned by pSetup, then clear it from table...
		if (!pPacket->bSetupOwned)
			for (int n=0; n > N_MAX_PACKETS_PROCESS; n++)
				if (pSetup->cPacketsProcessing[n] == pPacket)
					//NOTE! Could do better testing... check if one has been found - and should be found...
					pSetup->cPacketsProcessing[n] = 0;

		kfree(pPacket);
	}
	//else, leave without kfree'ing
}

int isMeOrMine(unsigned int nIp)
{
        //Is this one of my IP addresses or one in my subnet?
	if (nIp == pSetup->nMyIp || nIp == pSetup->nInternalIp)
		return 1;
	
	if ((nIp & pSetup->nNettmask) == (pSetup->nInternalIp & pSetup->nNettmask))
	        return 1;
	
        return 0;	
}

int isSubNet(unsigned int nIp)
{
	return ((nIp & pSetup->nNettmask) == (pSetup->nInternalIp & pSetup->nNettmask));
}

char *inspectIsItMe(unsigned int nIp, char *cBuf);

char *inspectIsItMe(unsigned int nIp, char *cBuf)
{
      sprintf(cBuf, "IP&N: %08X, Int&N: %08X",  
      (nIp & pSetup->nNettmask), (pSetup->nInternalIp & pSetup->nNettmask));
      return cBuf;
}

int dropFromLogging(struct _PacketInspection *pPacket);
int dropFromLogging(struct _PacketInspection *pPacket)
{
	for (int n = 0; n < N_MAX_IPs_NOT_TO_LOG_TO_DMESG && pSetup->dontDmesgIPs[n]; n++)
	{
		bool bDrop = pPacket->ip_header->saddr == pSetup->dontDmesgIPs[n] || pPacket->ip_header->daddr == pSetup->dontDmesgIPs[n];
		//pr_info("tarakernel: %s: %pI4 - checking %pI4 and %pI4", (bDrop?"Drop":"Don't drop"), &pSetup->dontDmesgIPs[0], &pPacket->ip_header->saddr, &pPacket->ip_header->daddr);

		if (bDrop)
			return 1;
	}
	return 0;
}


void reportInboundTraffic(struct _PacketInspection *pPacket); //To avoid compiler warning
void reportInboundTraffic(struct _PacketInspection *pPacket)
{
    if (!pSetup->cShowInstructions.bits.doReportTraffic)
    {
          pr_info("tarakernel: ******* ERROR should never get to reportInboundTraffic()\n");
          return;
    }

	if (dropFromLogging(pPacket))
		return;	

	//Check if one of the IP addresses is among those put in setup->dontDmesgIPs not to be handled (for now only handles one..). 
	if (dropFromLogging(pPacket))
	{
		//pr_info("tarakernel: ******* Dropping logging because one of IPs is listed as not to log to dmesg\n");
		return;
	}


    //Queue this packet for sending to ABMonitor for further handling. 
    int n = 0;
    for (n = 0; n < sizeof(pSetup->cPendingIncomingReportArr) / sizeof(struct _ipPort2); n++)
    {
          if (pSetup->cPendingIncomingReportArr[n].sIp == pPacket->ip_header->saddr && 
			pSetup->cPendingIncomingReportArr[n].dIp == pPacket->ip_header->daddr && 
              pSetup->cPendingIncomingReportArr[n].sPort == pPacket->sPort &&
              pSetup->cPendingIncomingReportArr[n].dPort == pPacket->dPort)
          {
                //Already have registered traffic on this ip/port. Increase the count.
                pSetup->cPendingIncomingReportArr[n].nCount++;
                
         	//if (pSetup->cShowInstructions.bits.showOther)
                //        pr_info("tarakernel: PR: Reporting incoming traffic %s:%d -> %s:%d (increased count at #%d)\n",pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, n);
                break;
          }
          else
                if (!pSetup->cPendingIncomingReportArr[n].sIp)
                {
                      //Found an available slot...
                      pSetup->cPendingIncomingReportArr[n].sIp = pPacket->ip_header->saddr;
                      pSetup->cPendingIncomingReportArr[n].dIp = pPacket->ip_header->daddr;
                      pSetup->cPendingIncomingReportArr[n].sPort = pPacket->sPort;
                      pSetup->cPendingIncomingReportArr[n].dPort = pPacket->dPort;
                      pSetup->cPendingIncomingReportArr[n].nCount = 1;

					  //
					  //pSetup->cPendingIncomingReportArr[n].cTagUnion.nBe16 = pPacket->cTagUnion.nBe16;	//OT_Changed: 260225 - just testing if can get this through taralink to DB
					  pSetup->cPendingIncomingReportArr[n].nTag = 316;//TESTING 260225 pPacket->tcp_header->urg_ptr;	//OT_Changed: 260225 - just testing if can get this through taralink to DB

                  //    if (pSetup->cShowInstructions.bits.showOther)
              	//	      pr_info("tarakernel: PR: Reporting incoming traffic %s:%d -> %s:%d (put at #%d)\n",pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, n);
                      break;
                }
    }
    
    if (n == sizeof(pSetup->cPendingIncomingReportArr) / sizeof(struct _ipPort2))
    {
          //Array is full... Print warning...
          if (pSetup->cShowInstructions.bits.showOther)
                pr_info("tarakernel: ******* Queue of traffic reports is full (n=%d)... Please inform tarakernel support center\n", n);
    }
}

struct _PacketInspection *getPacketInfo(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
	//Check first if this packet is stored in skb
	struct nf_conn *ct;
	enum ip_conntrack_info ctinfo;
	int nAvailable = -1; //Found an available slot (save it)

	ct = nf_ct_get(skb, &ctinfo);
	if (0)	//ØT 260316 - just testing... (ct) 
	{
    	if (ct->mark == 0) 
		{
			int n=0;
			//mark is not set.. Create a new memory object - check first if stored....if so, then that's error?
			for (n=0; n > N_MAX_PACKETS_PROCESS; n++)
			{
				struct _PacketInspection *pTest = pSetup->cPacketsProcessing[n];
				if (pTest->skb == skb) 			
				{
					pr_info("tarakernel: skb already stored (ERROR *****but skb->mark not set*****).. reusing... (ndx n)\n");
					return pTest;
				}
				if (!pTest)
					if (nAvailable < 0)
						nAvailable = n;
			}

			struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
			initPacket(pPacket, skb, state, true /*bSetupOwned*/);

			if (nAvailable < 0 && n == N_MAX_PACKETS_PROCESS)
			{
				pr_info("tarakernel: ************* ERROR ***** Increase N_MAX_PACKETS_PROCESSING\n");
				return pPacket; //NOTE! This one is never being deleted..... Program will stop very soon
			}

			if (nAvailable < 0)
				nAvailable = n; 	//Occupy the current one..

			pSetup->cPacketsProcessing[nAvailable] = pPacket;	
			//pr_info("tarakernel: ***** New packet info stored at slot %d\n", nAvailable);			
        	ct->mark = nAvailable +1;	//NOTE! 1 based (not 0-based) to indicate that a value is set (default is 0)
			nf_conntrack_event_cache(IPCT_MARK, ct);	//Shouldn't be necessary but some are doing this...

			//printk(KERN_INFO "tarakernel: (After setting) ct=%px mark=%u ctinfo=%d\n", ct, ct->mark, ctinfo);		

			return pPacket;
    	}
		else
		{
			struct _PacketInspection *pPacket = pSetup->cPacketsProcessing[ct->mark-1];
			if (!pPacket)
			{
				//ØT 260314 - For now, not trusting the stored value..... just debugging...
				pr_info("tarakernel: ******** skb->mark was set but cleared again... so reinstated now..\n");
				struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
				initPacket(pPacket, skb, state, true /*bSetupOwned*/);
				pSetup->cPacketsProcessing[ct->mark-1] = pPacket;	
				return pPacket;
			}
			else
			{
				//pr_info("tarakernel: ******** skb->mark was set and is being used..\n");
				return pPacket; //NOTE! ct->mark is 1-based because 0 means not set...
			}
		}
	}
	else
	{
		//This happened when hook was registered with pSetup->nf_PRE_ROUTING_hook_ops->priority = NF_IP_PRI_FIRST; - Use NF_IP_PRI_CONNTRACK + 1;
		//ØT - 260316 - just disabled for testing.... pr_info("tarakernel: ****** ERROR ***** conntrack probably not activated. Can't store packet info cross hooks\n");
		struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
		initPacket(pPacket, skb, state, false /*bSetupOwned*/);
		return pPacket;
	}

}

int handleUdp(	struct _PacketInspection *pPacket);
int handleUdp(	struct _PacketInspection *pPacket)
{
	struct udphdr *udph;

	if (!pskb_may_pull(pPacket->skb, (ip_hdr(pPacket->skb)->ihl * 4) + sizeof(struct udphdr))) 
	    return NF_ACCEPT;

	udph = udp_hdr(pPacket->skb);

	if  (ntohs(udph->dest) == TARAKERNEL_LISTENING_TO_PORT)
	{
		pr_info("tarakernel SENDING: Received: UDP %pI4:%u -> %pI4:%u\n", &pPacket->ip_header->saddr, ntohs(udph->source), &pPacket->ip_header->daddr, ntohs(udph->dest));

		unsigned int ihl;
		unsigned int payload_offset;
		unsigned int payload_len;

		if (!pskb_may_pull(pPacket->skb, sizeof(struct iphdr)))
   			return NF_ACCEPT;

		struct iphdr *iph = ip_hdr(pPacket->skb);

		//if (iph->protocol != IPPROTO_UDP)
   		//	return NF_ACCEPT;

		ihl = iph->ihl * 4;

		if (!pskb_may_pull(pPacket->skb, ihl + sizeof(struct udphdr)))
   			return NF_ACCEPT;

		udph = (struct udphdr *)((unsigned char *)iph + ihl);

		payload_offset = ihl + sizeof(struct udphdr);
		payload_len = ntohs(udph->len) - sizeof(struct udphdr);

		char buf[256];
		unsigned int copy_len;

		if (payload_len == 0)
   			return NF_ACCEPT;

		copy_len = min(payload_len, (unsigned int)(sizeof(buf) - 1));

		if (skb_copy_bits(pPacket->skb, payload_offset, buf, copy_len) < 0)
   			return NF_ACCEPT;

		buf[copy_len] = '\0';

		//Should we also check IP address here?
		//TO_DO - This is where to catch request elaboration from other partner about infected traffic
		//Could also catch such info here instead of letting it go to user space and then sent back to kernel? ()

		/*if (strstr(buf, UDP_THREAT_INFO_REQUEST_PREFIX) == buf) {
		    pr_info("tarakernel: matched request string only\n");
   			checkFree(pPacket, false);
   			return NF_DROP;
		}*/

		if (isRequestForThreatElaboration(buf, iph, udph))	//module_tagging.c
			return NF_DROP;

		pr_info("tarakernel: ********* WARNING ****** Unknown content to thread info service port.. Port scanning?\n");
	}

	checkFree(pPacket, false /*bLeavingPostRouting*/);
	return NF_ACCEPT;
}


static unsigned int module_ip4_pre_routing_handler(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
    int bToOrFromMe = 0;

	if (!skb)
		return NF_ACCEPT;

	struct _PacketInspection *pPacket = getPacketInfo(priv, skb, state); 

	if (pPacket->ip_header->protocol == IPPROTO_UDP)
	{
		//This is UDP packet.. Can't use TCP methods to find port and other info...
		return handleUdp(pPacket);
	}
	else
		if (pPacket->ip_header->protocol != IPPROTO_TCP)
		{
			checkFree(pPacket, false /*bLeavingPostRouting*/);
			return NF_ACCEPT;
		}

    checkThatTcp(pPacket,"start of pre_routing");	//260320 - asdf... got problem with this....

	pSetup->cGlobalStatistics.nPreRouting++;
        
	if (!bReceivedConfiguration)
	{
                //NOTE! IF YOU CHANGE THIS TEXT, THEN ALSO CHANGE IN crontasks.pl. IT'S LOOKING FOR IT
		pr_info("tarakernel: Start taralink to send configuration! %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
		checkFree(pPacket, false /*bLeavingPostRouting*/);
		return NF_ACCEPT;
	}

	//ØT - 260305 - From now log everything if set to logging 
	if (pSetup->cShowInstructions.bits.doReportTraffic)
        reportInboundTraffic(pPacket);
	
	//Check TSval tagging

	/* sval in the option part of the TCP header is another possible way to send data we're exploring...
	__be32 tsval_be, tsecr_be;

	if (tcp_read_timestamp_option(skb, &tsval_be, &tsecr_be)) {
	    u32 tsval = ntohl(tsval_be);
    	//u32 tsecr = ntohl(tsecr_be);
		//printk(KERN_INFO "tarakernel: ****************** TSval was set: %d\n", tsval);
	}
	//else
	//	pr_info("tarakernel: ****************** Failed to read TSval values??\n");

	*/

	if(blackListed(pPacket->ip_header->saddr))  //See "W/B List" and "Domains" in localhost/dashboard for ip-addresses here.  
	{
		unsigned int nRetval = requestActionOutbound(NULL);//str);
		pSetup->cGlobalStatistics.nBlocked++;

		if (nRetval == NF_DROP)
		{
			if (!dropFromLogging(pPacket))
			    pr_info("tarakernel: ***** Dropping traffic from blacklisted sender.\n");
			checkFree(pPacket, true /*bLeavingPostRouting*/);
		}
		else
			checkFree(pPacket, false /*bLeavingPostRouting*/);


		return nRetval; //NF_ACCEPT or NF_DROP;
	}

	if (isListedForInspection(pPacket->dIp) || isListedForInspection(pPacket->sIp))
		packetInterpreter(pPacket);

    //if (pPacket->ip_header->saddr == pSetup->nInternalIp || pPacket->ip_header->daddr == pSetup->nInternalIp)
    if (isSubNet(pPacket->ip_header->saddr) && isSubNet(pPacket->ip_header->daddr))
	{
		if (pSetup->cShowInstructions.bits.showOther)
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: PR: Traffic with subnet %s:%d -> %s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
			
		checkFree(pPacket, false /*bLeavingPostRouting*/);
		return NF_ACCEPT;
	}

	//Can we check here if inbound traffic to this computer (nettwork)?
	if (isMeOrMine(pPacket->ip_header->daddr))
	{
        /*
                    Is this where we check for tagging and match with information about servers in the network?
                    probably not because it has to be run through the NAT first to see where the packages are heading. 
                    Meaning the matching will be in module_forwarding.c
        */
        bToOrFromMe = 1;
		u32 nPartnerIp = isPartner(pPacket->ip_header->saddr);
              
		if (nPartnerIp) 	
		{
			/*
		    This is probably traffic both to this node and to nodes inside this network (NAT will translate to local IP address). Meaning the same
		    traffic will also show up in module_forwardning.c...
	        */
			union _TagUnion cUnion;
			cUnion.nTag = pPacket->tcp_header->urg_ptr;
			
			if (cUnion.nTag)
			{
				struct _Remote_infection *pInfection = initElaboratedThreatInfo(pPacket);	//module_tagging.c
				bool bDropping = cUnion.cTag.presumed_infected > pSetup->nBlockIncomingTaggedTrafficLevel;
				char cBuf[300];

				snprintf(pSetup->c100, sizeof(pSetup->c100)-1, "%s Tag-severity %u/%u. %s->%s, severity: %d, botnet: %d, owner_id: %d, info: %s, unrep traff: %d/%d (check gatekeeper)\n", (bDropping?"**DROPPING packet**":"(tag removed)"), 
						cUnion.cTag.presumed_infected, pSetup->nBlockIncomingTaggedTrafficLevel, pPacket->cSourceIp, pPacket->cDestIp, pInfection->nSeverity, pInfection->nBotnetId, pInfection->dOwners_id, pInfection->lpInfo, pInfection->nByteCount, pInfection->nPacketCount);

				if (strlen(pSetup->c100) == sizeof(pSetup->c100)-1)	//NOTE c100 is now 200 chars
					if (!dropFromLogging(pPacket))
						pr_warn("tarakernel: ***** ERROR **** pSetup->c100 buffer is to short. %zu bytes required (currently %zu)\n", strlen(cBuf)+1, sizeof(pSetup->c100));

				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: PR infected: %s", pSetup->c100);

			    if (bDropping)
			    {
                    //pr_info("tarakernel: **DROPPING**: Tag-severity %u/%u. %s->%s, severity: %d, botnet: %d, owner_id: %d, info: %s, unrep traff: %d/%d (check gatekeeper)\n", 
					//	cUnion.cTag.presumed_infected, pSetup->nBlockIncomingTaggedTrafficLevel, pPacket->cSourceIp, pPacket->cDestIp, pInfection->nSeverity, pInfection->nBotnetId, pInfection->dOwners_id, pInfection->lpInfo, pInfection->nByteCount, pInfection->nPacketCount); 

					checkFree(pPacket, true /*bLeavingPostRouting*/);	//leaving POST_ROUTING or returning NF_DROP doesn't matter.. it's leaving the system.
                    return NF_DROP;
			    }

		        //Remove the tag by default. This is traffic to the server (forwarded traffic doesn't come here..??????)
		        //Note! Sometimes (always?) even a Ubuntu computer droppes the package if tagged this way... (maybe because of checksum error?)
		        //sprintf(pSetup->c100, "Tag was %04X (removed) Infected: %u, owners_id: %u, block threshold: %u",  pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.owners_id, pSetup->nBlockIncomingTaggedTrafficLevel);
		              
				pPacket->tcp_header->urg_ptr = 0; //Removing tag. 
			    checkThatTcp(pPacket,"after clearing urg_ptr");	//260320 - asdf... got problem with this....
			    recalcChecksum(pPacket);

				//When an incoming packet on a Linux system is tagged using the urg_ptr field, often, the package
			    //still doesn't go through if the urg_ptr field is cleared at the receiver end. Is this because the URG flag is set somewhere?
			    //The line below gives compiler error. Is the URG flag somewhere else?
                //pPacket->tcp_header->URG = 0;
				//NOTE! THIS WAS MOST LIKELY BECASE THE CHECKSUM WAS WRONG. IT'S NOW BEING CALCULATED ABOVE
			}
			else
			    strcpy(pSetup->c100, "(Not tagged)");

			//Check tagging based on DSCP part of ToS (Type of Service)
			uint8_t dscp = getDscp(pPacket);
			
			if (dscp)
			{
				bool bBlock = dscp > pSetup->nBlockIncomingTaggedTrafficLevel;

				if (!dropFromLogging(pPacket))
					printk ("tarakernel: Packet was tagged using the DSCP part of ToS.. Value: %d. System is set to block packets above %d, so %s\n", 
									dscp, pSetup->nBlockIncomingTaggedTrafficLevel, (bBlock?"BLOCKING" : "NOT blocking"));

				setDscp(ip_hdr(pPacket->skb), 0);	
			    checkThatTcp(pPacket,"after setting DSCP");	//260320 - asdf... got problem with this....
			    recalcChecksum(pPacket);

				if(bBlock)
				{
					checkFree(pPacket, true /*bLeavingPostRouting*/);	//POST_ROUTING or NF_DROP doesn't matter.. it's leaving the system.
					return NF_DROP;
				}
			}
			
			
	        if (pSetup->cShowInstructions.bits.showPreRoutePartner) {
				//pr_info("tarakernel: PRE ROUTING: Inbound from partner: %s (%s -> %s)\n",pSetup->c100, pPacket->cSourceIp, pPacket->cDestIp);
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: PRE ROUTING Inbound from partner(%pI4): %s (%s:%d -> %s:%d)\n", &nPartnerIp, pSetup->c100, pPacket->cSourceIp, ntohs(pPacket->tcp_header->source), pPacket->cDestIp, ntohs(pPacket->tcp_header->dest)); 
			}
			if (cUnion.cTag.version_no)
			  pSetup->cGlobalStatistics.nFromPartnerTagged++;
			else
			  pSetup->cGlobalStatistics.nFromPartnerUntagged++; 
		}
//		else
//          	if (pSetup->cShowInstructions.bits.doReportTraffic)
//                reportInboundTraffic(pPacket);	//ØT 260305 - report everything PRE ROUTING....
		
        }    

	//Can we check here if outbound traffic from this computer or subnet?
	if (isMeOrMine(pPacket->ip_header->saddr))
	{
		bToOrFromMe = 1;

        if (isPartner(pPacket->ip_header->daddr)) 	
		{
		    /*
	        Is this where to tag outbound traffic? It may also be tagged in module_forwardning.c...
	        */

            /* Don't do the tagging here.... Handled in forwarding handled (module_forwarding.c)
        	if (pSetup->cShowInstructions.bits.doTagging)
        	{

				union _TagUnion cUnion;
		
				cUnion.cTag.version_no = TAG_VERSION_NO;
				cUnion.cTag.presumed_infected = 5; //Presumably bot. TO DO: Diversify this....
				cUnion.cTag.botnet_id = 99; //To be assigned by Akili Bomba.. To be implemented later...
				pPacket->tcp_header->urg_ptr= cUnion.nBe16;//(__be16)cTag;//htons(0xFF00);  //Tag the package.
				pSetup->cGlobalStatistics.nOutboundTagged++;
		        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
  					pr_info("tarakernel: PRE ROUTING: Tagging outbound for partner: Tag: (%04X) Infected: %u, botnetId: %u (%s -> %s)\n", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id, pPacket->cSourceIp, pPacket->cDestIp);
  		      }
  		      else
		        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
					pr_info("tarakernel: PR: Outbound for partner - BUT TAGGING IS DISABLED (%s -> %s)\n", pPacket->cSourceIp, pPacket->cDestIp);
			*/		
			if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: PR: Outbound for partner - not handling tagging in PRE_ROUTING (%s -> %s)\n", pPacket->cSourceIp, pPacket->cDestIp);
					
		}
		else
	    	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: PR: Outbound for non-partner %s:%d -> %s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort); 
	}
	
	if (!bToOrFromMe)
	{
        	if (pSetup->cShowInstructions.bits.showOwnerless)
        	{
			char cBuf1[50], cBuf2[50];
			if (!dropFromLogging(pPacket))
				pr_info("tarakernel: PR: Neither to or from me.. What is this? %s:%d (%s) -> %s:%d (%s)\n", 
					pPacket->cSourceIp, pPacket->sPort, inspectIsItMe(pPacket->ip_header->saddr, cBuf1),  
					pPacket->cDestIp, pPacket->dPort, inspectIsItMe(pPacket->ip_header->daddr, cBuf2));
		}
	}
	//else
	//NOTE! Probably already commented... Should check that with flag before printing anything here...
	//	if (pSetup->cShowInstructions.bits.showOther)
	//		pr_info("tarakernel: PR: To or from me or mine: %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
	
	checkFree(pPacket, false /*bLeavingPostRouting*/);
	return NF_ACCEPT;
}// PRE ROUTING

void getMeAndMine(char *lpBuf, int nBufSize)
{
//
      //Put my external and internal
    u32 nLittleEndian = swappedEndian(pSetup->nMyIp);
    sprintf(lpBuf, "Me: %u.%u.%u.%u", IPADDRESS(nLittleEndian));
    nLittleEndian = swappedEndian(pSetup->nInternalIp);
    if (nBufSize > strlen(lpBuf) + 30)
        sprintf(lpBuf+strlen(lpBuf), ", internal: %u.%u.%u.%u", IPADDRESS(nLittleEndian));
    else
        strcpy(lpBuf, "BUFFER TOO SMALL!");

    nLittleEndian = swappedEndian(pSetup->nNettmask);

    if (nBufSize > strlen(lpBuf) + 30)
        sprintf(lpBuf+strlen(lpBuf), ", nett: %u.%u.%u.%u", IPADDRESS(nLittleEndian));
    else
        strcpy(lpBuf, "BUFFER TOO SMALL!");
}


// **************** POST ROUTING *******************
static unsigned int module_ip4_post_routing_handler(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
//pr_info("Abs: POST ROUTING ACCEPTING ALL\n"); 
//return NF_ACCEPT;

	int bToOrFromMe = 0;

    //No longer do this, handled by abmonitor... checkTimedOperation();  //module_timed_operations.h

	if (!skb)
		return NF_ACCEPT;

	//struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
	//initPacket(pPacket, skb, state);asdf
	struct _PacketInspection *pPacket = getPacketInfo(priv, skb, state);

	if (pPacket->ip_header->protocol != IPPROTO_TCP)
	{
		checkFree(pPacket, true);	//Now leaving POST_ROUTING - so kfree the memory
		return NF_ACCEPT;
	}

    //cGlobalStatistics.nPostRouting++;
        
	if (!bReceivedConfiguration)
	{
	    //Only warn in pre routing...
		//pr_info("tarakernel: Start abmonitor to send configuration! %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
		checkFree(pPacket, true);	//Now leaving POST_ROUTING - so kfree the memory
		return NF_ACCEPT;
	}

	if (pPacket->ip_header->saddr == pSetup->nInternalIp || pPacket->ip_header->daddr == pSetup->nInternalIp)
	{
	    //To or from the router itself (not to be forwarded to other..). This is probably not interesting to anybody. (except my firewall.....)
		//OT_Changed: 260225 - incoming traffic is interesting to "SampleBank" or "HoneyPot"... Start logging this....
		//reportInboundTraffic(pPacket);	//ØT 260305 - NOw report everything prerouting..//OT_Changed: 260225 - added this... 
		checkFree(pPacket, true);	//Now leaving POST_ROUTING - so kfree the memory
		return NF_ACCEPT;
	}
        
	//Can we check here if inbound traffic to this computer (NOTE inbound to sub nettwork will be checked below)?
	if (pPacket->ip_header->daddr == pSetup->nMyIp)//ipMyAddress)
	//if (isMeOrMine(pPacket->ip_header->daddr))
	{
        bToOrFromMe = 1;
              
		if (isPartner(pPacket->ip_header->saddr)) 	
		{
			union _TagUnion cUnion;
			cUnion.nTag = pPacket->tcp_header->urg_ptr;
			sprintf(pSetup->c100, "Tag: (%04X) Infected: %u, owners_id: %u", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.owners_id);

	        if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				pr_info("tarakernel: POST ROUTING Inbound from partner: %s (%s:%d -> %s:%d)\n", pSetup->c100, pPacket->cSourceIp, ntohs(pPacket->tcp_header->source), pPacket->cDestIp, ntohs(pPacket->tcp_header->dest)); 

			if (cUnion.cTag.version_no)
				pSetup->cGlobalStatistics.nFromPartnerTagged++;
			else
				pSetup->cGlobalStatistics.nFromPartnerUntagged++;
		}		      
        }    

	//Can we check here if outbound traffic from this computer (NOTE! Outbound traffic from sub network will be checked below)?
	if (pPacket->ip_header->saddr == pSetup->nMyIp)
	//if (isMeOrMine(pPacket->ip_header->saddr))
	{
        bToOrFromMe = 1;

        if (isPartner(pPacket->ip_header->daddr)) //If outbound traffic for partner.	
		{
	        //***** Do tagging in case it's a server and not only a router (routers are tagging while forwarding. See T001)
		    bool bForwarding = false;   //This is PRE ROUTING, not forwarding
			int nRetval = checkFixTagging(pPacket, bForwarding, state);  //Defined in module_forwarding.c
			checkFree(pPacket, true);	//Now leaving POST_ROUTING - so kfree the memory
			return nRetval;
		
        	/* Code below is now fixed by checkFixTagging()
        	if (pSetup->cShowInstructions.bits.doTagging)
			{
				union _TagUnion cUnion;

				cUnion.cTag.version_no = TAG_VERSION_NO;
				cUnion.cTag.presumed_infected = 5; //Presumably bot. TO DO: Diversify this....
				cUnion.cTag.botnet_id = 99; //To be assigned by Akili Bomba.. To be implemented later...
				pPacket->tcp_header->urg_ptr= cUnion.nBe16;//(__be16)cTag;//htons(0xFF00);  //Tag the package.
				pSetup->cGlobalStatistics.nOutboundTagged++;
				sprintf(pSetup->c100, "Tag: (%04X) Infected: %u, botnetId: %u", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id);
			}
			else
			        strcpy(pSetup->c100, "BUT TAGGING IS DISABLED");
			        
	        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				pr_info("tarakernel: POST ROUTING Outbound for partner. Tagging is normally handled in forwarding (T001): %s (%s -> %s)\n", pSetup->c100, pPacket->cSourceIp, pPacket->cDestIp);
			*/
		}
		else
        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
				if (!dropFromLogging(pPacket))
	  				pr_info("tarakernel: POST ROUTING Outbound for non-partner (no tagging) (%s:%d -> %s:%d).\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
	}
	
	if (!bToOrFromMe)
	{
		//Check if it's package to be forwarded to subnet
		char *lpTag = (pPacket->tcp_header->urg_ptr > 0 ? "" : "NOT ");
		
    	if (isMeOrMine(pPacket->ip_header->saddr))
    	{
        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
				if (!dropFromLogging(pPacket))
					pr_info("tarakernel: POST ROUTING From subnet to external (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
	    }
		else
    		if (isMeOrMine(pPacket->ip_header->daddr))
  			{
		    	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
					if (!dropFromLogging(pPacket))
		    				pr_info("tarakernel: POST ROUTING From external to subnet (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
			}
		    else
		    {
	        	if (pPacket->ip_header->daddr == pSetup->nMyIp && portForwarded(pPacket->dPort))
	        	{
					if (pSetup->cShowInstructions.bits.showOther)
						if (!dropFromLogging(pPacket))
	        				pr_info("tarakernel: POST ROUTING to forwarded port (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort); 
		    	}
    	    	else
    	       	{
        	        //asdf
    	    		char *lpTemp = kmalloc(200, GFP_KERNEL);
       	        	if (lpTemp)
       	            {
       	                getMeAndMine(lpTemp, 200);
						if (!dropFromLogging(pPacket))
        			    	pr_info("tarakernel: **** WARNING **** PR None of mine.. Probably partner malconfiguration? (%s:%d->%s:%d %s)\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, lpTemp);
    				}
    				else
					{
						pr_info("tarakernel: **** ERROR allocating buffer in post routing handling\n");
						kfree(lpTemp);
        			}
				}
  			}
	}
	checkFree(pPacket, true);	//Now leaving POST_ROUTING - so kfree the memory
    return NF_ACCEPT;
}

