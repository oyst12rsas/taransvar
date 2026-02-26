//module_packet_interpreter.c
//NOTE! Include and call from module_pre_routing_handler.c

#include "module_packet_interpreter.h"

//Only run full packet inspection on on packet to avoid overloading the system. 

int inspectThis(u32 sourceIp)
{
        char *lpSourceIpAddr; 
        lpSourceIpAddr = (char *)kmalloc(16, GFP_KERNEL);
	int n, nArrSize;

        const char *lpDrop[] = {"151.101",	//Fastly
			"172.217",		//Google
			"20.114.189.70",	//MS Ads
			"172.232",		//Proxy data
				"dummy"};	

//	const char *lpOnlyShowIf[] = {"192.168.1.113","46.30.213.62"};
	const char *lpOnlyShowIf[] = {"81.88.18.98","98.18.88.81"};

	sprintf(lpSourceIpAddr, "%u.%u.%u.%u", IPADDRESS(sourceIp));

	//Check list of IP-addresses to drop (return if any of these
	nArrSize = sizeof(lpDrop)/sizeof(lpDrop[0]);
	for (n=0;n<nArrSize;n++)
		if (strstr(lpSourceIpAddr,lpDrop[n]) == lpSourceIpAddr)
		{
		        kfree(lpSourceIpAddr);
			return 0;
		}

	//Check list of IP-addresses to examine (return none of these
	for (n=0;n<sizeof(lpOnlyShowIf)/sizeof(lpOnlyShowIf[0]);n++) 
		if (strstr(lpSourceIpAddr,lpOnlyShowIf[n]) == lpSourceIpAddr)
        		{
        		        kfree(lpSourceIpAddr);
        			return 1;
        		}

        kfree(lpSourceIpAddr);

	if (sizeof(lpOnlyShowIf)/sizeof(lpOnlyShowIf[0]) > 0)
		return 0;	//There's elements and been through array without finding this IP
		
        return 1;
}

int packetInterpreter(struct _PacketInspection *pPacket)
{
        //char *lpSourceIpAddr, *lpDestIpAddr; 

	//u32 ip;

	if (++nPackageSequenceNumber < N_INSPECT_PACKAGE_START_NUMBER)
		return NF_ACCEPT;

//	struct _PacketInspection *pPacket = (struct _PacketInspection *)kmalloc(sizeof(struct _PacketInspection), GFP_KERNEL);

//OK HERE*******************

	if (!pPacket->ip_header || pPacket->ip_header != (struct iphdr *)skb_network_header(pPacket->skb)) 
	{
		printk("tarakernel ERROR finding ip_header\n");
		kfree(pPacket);
		return NF_ACCEPT;
	}

	if (!inspectThis(ntohl(pPacket->ip_header->saddr)))
	{
		kfree(pPacket);
		return NF_ACCEPT;
	}

//////OK HERE*************

	if(pPacket->ip_header->protocol==IPPROTO_TCP) // && sport==80	//if TCP PACKET
	{ 
		//char *lpPayload;
		char *lpPayloadBuf, *lpPayloadHex;
		//char cPayload[30], cPayloadHex[60];
		nPacketsInspected++;
		int n;

		if (nPacketsInspected > N_INSPECTION_PACKETS_TO_SHOW)
		{
			kfree(pPacket);
			return NF_ACCEPT;
	        }
//******** OK HERE*
	        
	        #define D_PAYLOAD_MAX_SIZE  30

                lpPayloadBuf = (char *)kmalloc(D_PAYLOAD_MAX_SIZE, GFP_KERNEL);
                lpPayloadHex = (char *)kmalloc(D_PAYLOAD_MAX_SIZE*2, GFP_KERNEL);

		pPacket->sPort = htons((unsigned short int) pPacket->tcp_header->source);
		pPacket->dPort = htons((unsigned short int) pPacket->tcp_header->dest);

		//Try to extract the TCP payload...
		//NOTE! This is wrong.. IP header comes before TCP header...
		pPacket->lpPayload = (char*) pPacket->tcp_header + pPacket->nTcpHeaderSize;
		strncpy(lpPayloadBuf, pPacket->lpPayload, D_PAYLOAD_MAX_SIZE-1);
		lpPayloadBuf[D_PAYLOAD_MAX_SIZE-1] = 0;

//****** OK HERE

		for (n=0;n<D_PAYLOAD_MAX_SIZE-1;n++)
			sprintf(lpPayloadHex+n*2,"%02X", lpPayloadBuf[n]);
			
		lpPayloadHex[n] = 0;

		printk("tarakernel packet inspection: TCP %s:%d -> %s:%d hdr-size: %d, tot_len: %d data: %s\n", pPacket->cSourceIp, pPacket->sPort, 
		        pPacket->cDestIp, pPacket->dPort, pPacket->nTcpHeaderSize, pPacket->nTotLen, lpPayloadHex);

	        kfree(lpPayloadBuf);
	        kfree(lpPayloadHex);
  
	}
		//printk("tarakernel: Running packet inspection, but was hoping it's TCP package/n");
		//nPackageSequenceNumber = N_INSPECT_PACKAGE_NUMBER -1;	//To hit next time as well

	//In case of future need. Send source and destiny ip to taralink for analysis if this is malconfigured IP or partner IP..
	//checkPartner(pPacket->ip_header->saddr);
	//checkPartner(pPacket->ip_header->daddr);
                
	//Code below is to increase size of TCP header to give space for tagging.. However for now just try to use the tcp_header->urg_ptr field - assuming it's not in use.
	//nCurrentHeaderSize = (int)(pPacket->ip_header->ihl + pPacket->tcp_header->doff*32/8);	//doff is number of 32bit words.. Change to bytes...
	
	//nOurTagSize = sizeof(struct _threatSpecification);
	//newGrossMemorySize = pPacket->ip_header->tot_len + nOurTagSize;

	//Prepare for allocing memory for the new package...
//	cNewGrossMemory = kvmalloc();

//	NOTE! This print line makes the system crash......
//	printk("tarakernel FW:(#: %d) (hdr size: %d, tot_len: %d, our tag: %d) %s -> %s\n", nPackagesForwarded, nCurrentHeaderSize, newGrossMemorySize, nOurTagSize, ipFrom, ipTo);

        //if (pSetup->cShowInstructions.bits.showOther)
	//	printk("tarakernel FW (**** NEVER GETS HERE): %s -> %s\n", pPacket->cSourceIp, pPacket->cDestIp);///%s\n", ipFrom, ipTo);
	
	if (1)
	{
		//NOTE! This is not working...
//		#define PAYLOAD_BUFF_SIZE 1000
//		char cPayloadBuffer[PAYLOAD_BUFF_SIZE];
//		getTcpPayload(skb, cPayloadBuffer, PAYLOAD_BUFF_SIZE);   //Defined in module_TCP_functions.h
    	        //printk("tarakernel payload: %s\n", lpIpFrom);
	        //Commented out because made the system crash...
	}

        /* NOTE! This is how we're doing the tagging for now... This field is meant to point to section
        of the packet that is urgent and will be sent first. 
        There's also a bit field in TCP header that is supposed to be set to 1, so if that field is 0, 
        then we can probably use this pointer for tagging. 
        */


	return NF_ACCEPT;
}

