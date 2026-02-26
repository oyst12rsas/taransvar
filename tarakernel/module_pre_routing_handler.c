
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

void initPacket(struct _PacketInspection *pPacket, struct sk_buff *skb, const struct nf_hook_state *state)
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
	
	pPacket->cTagUnion.nBe16 = pPacket->tcp_header->urg_ptr;	//OT_Changed - 
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

void reportInboundTraffic(struct _PacketInspection *pPacket); //To avoid compiler warning
void reportInboundTraffic(struct _PacketInspection *pPacket)
{
    if (!pSetup->cShowInstructions.bits.doReportTraffic)
    {
          printk("tarakernel: ******* ERROR should never get to reportInboundTraffic()\n");
          return;
    }

    //Queue this packet for sending to ABMonitor for further handling. 
    int n = 0;
    for (n = 0; n < sizeof(pSetup->cPendingIncomingReportArr) / sizeof(struct _ipPort2); n++)
    {
          if (pSetup->cPendingIncomingReportArr[n].ip == pPacket->ip_header->saddr && 
              pSetup->cPendingIncomingReportArr[n].sPort == pPacket->sPort &&
              pSetup->cPendingIncomingReportArr[n].dPort == pPacket->dPort)
          {
                //Already have registered traffic on this ip/port. Increase the count.
                pSetup->cPendingIncomingReportArr[n].nCount++;
                
         	//if (pSetup->cShowInstructions.bits.showOther)
                //        printk("tarakernel: PR: Reporting incoming traffic %s:%d -> %s:%d (increased count at #%d)\n",pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, n);
                break;
          }
          else
                if (!pSetup->cPendingIncomingReportArr[n].ip)
                {
                      //Found an available slot...
                      pSetup->cPendingIncomingReportArr[n].ip = pPacket->ip_header->saddr;
                      pSetup->cPendingIncomingReportArr[n].sPort = pPacket->sPort;
                      pSetup->cPendingIncomingReportArr[n].dPort = pPacket->dPort;
                      pSetup->cPendingIncomingReportArr[n].nCount = 1;

					  //
					  //pSetup->cPendingIncomingReportArr[n].cTagUnion.nBe16 = pPacket->cTagUnion.nBe16;	//OT_Changed: 260225 - just testing if can get this through taralink to DB
					  pSetup->cPendingIncomingReportArr[n].cTagUnion.nBe16 = 316;//TESTING 260225 pPacket->tcp_header->urg_ptr;	//OT_Changed: 260225 - just testing if can get this through taralink to DB

                  //    if (pSetup->cShowInstructions.bits.showOther)
              	//	      printk("tarakernel: PR: Reporting incoming traffic %s:%d -> %s:%d (put at #%d)\n",pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, n);
                      break;
                }
    }
    
    if (n == sizeof(pSetup->cPendingIncomingReportArr) / sizeof(struct _ipPort2))
    {
          //Array is full... Print warning...
          if (pSetup->cShowInstructions.bits.showOther)
                printk("tarakernel: ******* Queue of traffic reports is full (n=%d)... Please inform tarakernel support center\n", n);
    }
}

static unsigned int module_ip4_pre_routing_handler(void *priv, struct sk_buff *skb, const struct nf_hook_state *state)
{
        int bToOrFromMe = 0;

        checkTimedOperation();  //module_timed_operations.h

	if (!skb)
		return NF_ACCEPT;

	struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
	initPacket(pPacket, skb, state);

	if (pPacket->ip_header->protocol != IPPROTO_TCP)
	{
	        kfree(pPacket);
		return NF_ACCEPT;
	}
	else
	{
	  //ØT 240103 - delete this section.....
		//return NF_ACCEPT;
	}

	pSetup->cGlobalStatistics.nPreRouting++;
        
	if (!bReceivedConfiguration)
	{
                //NOTE! IF YOU CHANGE THIS TEXT, THEN ALSO CHANGE IN crontasks.pl. IT'S LOOKING FOR IT
		printk("tarakernel: Start taralink to send configuration! %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
		kfree(pPacket);
		return NF_ACCEPT;
	}
	
	if(blackListed(pPacket->ip_header->saddr))  //See "W/B List" and "Domains" in localhost/dashboard for ip-addresses here.  
	{
		unsigned int nRetval = requestActionOutbound(NULL);//str);
                pSetup->cGlobalStatistics.nBlocked++;
		kfree(pPacket);
		if (nRetval == NF_DROP)
		      printk("tarakernel: ***** Dropping traffic from blacklisted sender.\n");
		return nRetval; //NF_ACCEPT or NF_DROP;
	}

	if (isListedForInspection(pPacket->dIp) || isListedForInspection(pPacket->sIp))
		packetInterpreter(pPacket);

        //if (pPacket->ip_header->saddr == pSetup->nInternalIp || pPacket->ip_header->daddr == pSetup->nInternalIp)
        if (isSubNet(pPacket->ip_header->saddr) && isSubNet(pPacket->ip_header->daddr))
	{
		if (pSetup->cShowInstructions.bits.showOther)
			printk("tarakernel: PR: Traffic with subnet %s:%d -> %s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
			
		kfree(pPacket);
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
              
		if (isPartner(pPacket->ip_header->saddr)) 	
		{
		        /*
		        This is probably traffic both to this node and to nodes inside this network (NAT will translate to local IP address). Meaning the same
		        traffic will also show up in module_forwardning.c...
	        	*/
			union _TagUnion cUnion;
		
			//cTag = 	(struct _Tag)tcp_header->urg_ptr;
			cUnion.nBe16 = pPacket->tcp_header->urg_ptr;
			
			if (pPacket->tcp_header->urg_ptr)
			{
			        if (cUnion.cTag.presumed_infected > pSetup->nBlockIncomingTaggedTrafficLevel)
			        {
                                      printk("tarakernel: ******* WARNING ***** Dropping tagged data with presumed severity (%u) exceeding blocking threshold (%u). (%s -> %s)\n", cUnion.cTag.presumed_infected, pSetup->nBlockIncomingTaggedTrafficLevel, pPacket->cSourceIp, pPacket->cDestIp); 
                                      return NF_DROP;
			        }
		              //Remove the tag by default. This is traffic to the server (forwarded traffic doesn't come here..??????)
		              //Note! Sometimes (always?) even a Ubuntu computer droppes the package if tagged this way...
		              sprintf(pSetup->c100, "Tag was (but removed): (%08X) Infected: %u, botnetId: %u, block threshold: %u",  pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id, pSetup->nBlockIncomingTaggedTrafficLevel);
		              
			        pPacket->tcp_header->urg_ptr = 0; //Removing tag. 

			        //When an incoming packet on a Linux system is tagged using the urg_ptr field, often, the package
			        //still doesn't go through if the urg_ptr field is cleared at the receiver end. Is this because the URG flag is set somewhere?
			        //The line below gives compiler error. Is the URG flag somewhere else?
                                //pPacket->tcp_header->URG = 0;
			}
			else
			        strcpy(pSetup->c100, "(Not tagged)");
			
			
	        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				printk("tarakernel: PRE ROUTING: Inbound from partner: %s (%s -> %s)\n",pSetup->c100, pPacket->cSourceIp, pPacket->cDestIp);
		        
			if (cUnion.cTag.version_no)
			  pSetup->cGlobalStatistics.nFromPartnerTagged++;
			else
			  pSetup->cGlobalStatistics.nFromPartnerUntagged++; 
		}
		else
          	        if (pSetup->cShowInstructions.bits.doReportTraffic)
                                reportInboundTraffic(pPacket);
		
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
  					printk("tarakernel: PRE ROUTING: Tagging outbound for partner: Tag: (%08X) Infected: %u, botnetId: %u (%s -> %s)\n", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id, pPacket->cSourceIp, pPacket->cDestIp);
  		      }
  		      else
		        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
					printk("tarakernel: PR: Outbound for partner - BUT TAGGING IS DISABLED (%s -> %s)\n", pPacket->cSourceIp, pPacket->cDestIp);
			*/		
	        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				printk("tarakernel: PR: Outbound for partner - not handling tagging in PRE_ROUTING (%s -> %s)\n", pPacket->cSourceIp, pPacket->cDestIp);
					
		}
		else
	        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
    				printk("tarakernel: PR: Outbound for non-partner %s:%d -> %s:%d\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);  //asdfasfd
	}
	
	if (!bToOrFromMe)
	{
        	if (pSetup->cShowInstructions.bits.showOwnerless)
        	{
			char cBuf1[50], cBuf2[50];
			printk("tarakernel: PR: Neither to or from me.. What is this? %s:%d (%s) -> %s:%d (%s)\n", 
				pPacket->cSourceIp, pPacket->sPort, inspectIsItMe(pPacket->ip_header->saddr, cBuf1),  
				pPacket->cDestIp, pPacket->dPort, inspectIsItMe(pPacket->ip_header->daddr, cBuf2));
		}
	}
	//else
	//NOTE! Probably already commented... Should check that with flag before printing anything here...
	//	if (pSetup->cShowInstructions.bits.showOther)
	//		printk("tarakernel: PR: To or from me or mine: %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
	
	kfree(pPacket);
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
//printk("Abs: POST ROUTING ACCEPTING ALL\n"); 
//return NF_ACCEPT;


        int bToOrFromMe = 0;

        //No longer do this, handled by abmonitor... checkTimedOperation();  //module_timed_operations.h

	if (!skb)
		return NF_ACCEPT;

	struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);
	initPacket(pPacket, skb, state);

	if (pPacket->ip_header->protocol != IPPROTO_TCP)
	{
	        kfree(pPacket);
		return NF_ACCEPT;
	}

        //cGlobalStatistics.nPostRouting++;
        
	if (!bReceivedConfiguration)
	{
	        //Only warn in pre routing...
		//printk("tarakernel: Start abmonitor to send configuration! %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp); 
		kfree(pPacket);
		return NF_ACCEPT;
	}

	if (pPacket->ip_header->saddr == pSetup->nInternalIp || pPacket->ip_header->daddr == pSetup->nInternalIp)
	{
	    //To or from the router itself (not to be forwarded to other..). This is probably not interesting to anybody. (except my firewall.....)
		//OT_Changed: 260225 - incoming traffic is interesting to "SampleBank" or "HoneyPot"... Start logging this....
		reportInboundTraffic(pPacket);	//OT_Changed: 260225 - added this... 
		kfree(pPacket);
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
			cUnion.nBe16 = pPacket->tcp_header->urg_ptr;
			sprintf(pSetup->c100, "Tag: (%08X) Infected: %u, botnetId: %u", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id);

	        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				printk("tarakernel: POST ROUTING Inbound from partner: %s (%s -> %s)\n", pSetup->c100, pPacket->cSourceIp, pPacket->cDestIp); 

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
			int nRetval = checkFixTagging(pPacket, bForwarding);  //Defined in module_forwarding.c
			kfree(pPacket);
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
				sprintf(pSetup->c100, "Tag: (%08X) Infected: %u, botnetId: %u", pPacket->tcp_header->urg_ptr, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id);
			}
			else
			        strcpy(pSetup->c100, "BUT TAGGING IS DISABLED");
			        
	        	if (pSetup->cShowInstructions.bits.showPreRoutePartner)
				printk("tarakernel: POST ROUTING Outbound for partner. Tagging is normally handled in forwarding (T001): %s (%s -> %s)\n", pSetup->c100, pPacket->cSourceIp, pPacket->cDestIp);
			*/
		}
		else
	        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
  				printk("tarakernel: POST ROUTING Outbound for non-partner (no tagging) (%s:%d -> %s:%d).\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
	}
	
	if (!bToOrFromMe)
	{
		//Check if it's package to be forwarded to subnet
		char *lpTag = (pPacket->tcp_header->urg_ptr > 0 ? "" : "NOT ");
		
    		if (isMeOrMine(pPacket->ip_header->saddr))
    		{
	        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
				printk("tarakernel: POST ROUTING From subnet to external (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
	        }
		else
      			if (isMeOrMine(pPacket->ip_header->daddr))
      			{
        	        	if (pSetup->cShowInstructions.bits.showPreRouteNonPartner)
        				printk("tarakernel: POST ROUTING From external to subnet (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort);
			}
		        else
		        {
		        	if (pPacket->ip_header->daddr == pSetup->nMyIp && portForwarded(pPacket->dPort))
		        	{
					if (pSetup->cShowInstructions.bits.showOther)
        					printk("tarakernel: POST ROUTING to forwarded port (was %stagged) %s:%d -> %s:%d\n", lpTag, pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort); 
		        	}
        	        	else
        	        	{
        	        	        //asdf
       	        	                char *lpTemp = kmalloc(200, GFP_KERNEL);
       	        	                if (lpTemp)
       	        	                {
       	        	                        getMeAndMine(lpTemp, 200);
        				        printk("tarakernel: **** WARNING **** PR None of mine.. Probably partner malconfiguration? (%s:%d->%s:%d %s)\n", pPacket->cSourceIp, pPacket->sPort, pPacket->cDestIp, pPacket->dPort, lpTemp);
        				}
        				else
						printk("tarakernel: **** ERROR allocating buffer in post  routing handling\n");
						
					kfree(lpTemp);
        			}
  			}
	}
	kfree(pPacket);
        return NF_ACCEPT;
}



