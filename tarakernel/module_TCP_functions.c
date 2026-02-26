//module_TCP_functions.c
//#include <ctype.h>

int isprintable(char ch)
{
  //Note! There's isprint() funciton but it doesn't work on Ubuntu22 server.. So implemented this instead...
  if (ch >= 'a' && ch <= 'z') return 1;
  if (ch >= 'A' && ch <= 'Z') return 1;
  if (ch >= '0' && ch <= '9') return 1;
  if (strchr("!@#$%^&*()_+=-[]{}\\| ", ch) != 0) return 1;
  return 0;
}

void getTcpPayload(struct sk_buff *skb, char *lpBuffer, u32 nBufSize)
{
	//For now only called from module_forwarding.c but should also be called from module_packet_interpreter.c
	//struct sk_buff *sb = NULL;

	//Do we need both these?
//	struct iphdr *iph;
	struct iphdr *ip_header; // ip header struct

	struct tcphdr *tcp_header; // tcp header struct
	//struct udphdr *udp_header; // udp header struct
//	struct sk_buff *sock_buff;
//	u32 nTcpHeaderSize = -1;

//	unsigned int sport, dport;

//	sb = skb;

//	iph = ip_hdr(skb);
//	iph = ip_hdr(sb);

//	sock_buff = skb;

//	if (!sock_buff) 
//		return;

	ip_header = (struct iphdr *)skb_network_header(skb);

	if (!ip_header) 
	{
		printk("Absec: No ip_header!\n");
		return;
	}

	//Commented to test if works
	tcp_header= (struct tcphdr *)((__u32 *)ip_header+ ip_header->ihl);

	if (!tcp_header) 
	{
		printk("Absec: No tcp_header!\n");
		return;
	}

//	nTcpHeaderSize = (int)(tcp_header->doff*32/8);	//doff is number of 32bit words.. Change to bytes...


	if(ip_header->protocol==IPPROTO_TCP) // && sport==80	//if TCP PACKET
	{ 
		char *lpPayload;
		//int nTotLen = iph->tot_len;
		int nPos = 0;
		int n;
		int nPayLoadLen = ip_header->tot_len - sizeof(struct iphdr) - (tcp_header->doff*32/8);


		//Try to extract the TCP payload...
		lpPayload = (char*) tcp_header + (tcp_header->doff*32/8);
		
		//Try to find printable characters in the lpPayload... (for some reason not woring)
		for (n=0;n<nPayLoadLen && nPos < nBufSize-1 ;n++)
		  if (isprintable(*(lpPayload+n)))
        		{
	                  lpBuffer[nPos++] = *(lpPayload+n);	  
	        	}
	        lpBuffer[nPos] = 0;
		
		//strncpy(lpBuffer, lpPayload, nBufSize-1);
		//*(lpBuffer+nBufSize-1) = 0;
		
		//strcpy(lpBuffer, "TCP: Size \n");//, (int)(tcp_header->doff*32/8));
		//sprintf(lpBuffer,"data size: %d\n", nPayLoadLen);
	}
	else
		strcpy(lpBuffer, "not TCP");//""Size \n");//, (int)(tcp_header->doff*32/8));

}

