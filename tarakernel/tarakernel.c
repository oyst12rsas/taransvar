//tarakernel.c
//Kernel 5.4.0 version originally taken from https://gist.github.com/arunk-s/c897bb9d75a6c98733d6

//(Originally taken from) https://stackoverflow.com/questions/15215865/netlink-sockets-in-c-using-the-3-x-linux-kernel?lq=1

//10 is full system, 1 is just running the core system without and hokks or messages sent...
#define SYSTEM_OPERATION_LEVEL 10

#define C_VERSION 16
#define DEBUG_VERSION C_VERSION

/*There is some unsolved problem related to C_SEGMENT_MAX_SIZE. If we use 4000, the system can only store around 10 addresses to block, 
partners or whatever before freezing. We should figure out why and find the optimal value before needing the big numbers.*/
//#define C_SEGMENT_MAX_SIZE 4000
//#define C_SEGMENT_MAX_SIZE 50 Test with this....
#define C_SEGMENT_MAX_SIZE 1000

#include <linux/module.h>
#include <net/sock.h>
#include <linux/netlink.h>
#include <linux/skbuff.h>

/*Netfilter includes*/
#include <linux/init.h>
#include <linux/kernel.h>
#include <linux/netfilter.h>
#include <linux/netfilter_ipv4.h>
#include <net/netfilter/nf_conntrack.h>
#include <net/netfilter/nf_conntrack_ecache.h>

#include <linux/ip.h>
#include <linux/tcp.h>
#include <linux/udp.h>
#include <linux/string.h>
#include <linux/timekeeping.h>
#include <linux/skbuff.h>



#include "module_globals.h"
#include "tarakernel.h"
//#define CONFIG_MODULE_SIG 0

#define KSOCKET_NAME	"tarakernel"
#define KSOCKET_VERSION	"0.0.1"
#define KSOCKET_DESCPT	"Taransvar Security Solution"
#define KSOCKET_AUTHOR	"http://taransvar.no"
#define KSOCKET_DATE	"2025-01-13"

MODULE_AUTHOR(KSOCKET_AUTHOR);
MODULE_DESCRIPTION(KSOCKET_NAME"-"KSOCKET_VERSION"\n"KSOCKET_DESCPT);
MODULE_LICENSE("Dual BSD/GPL");

//Notes... sturcts are mostly defined in taralink.h
static unsigned int bReceivedConfiguration = 0;

void warn(char *lpMsg);
int isprintable(char ch);
//int packetInterpreter(void *priv, struct sk_buff *skb, const struct nf_hook_state *state);
void initPacket(struct _PacketInspection *pPacket, struct sk_buff *skb, const struct nf_hook_state *state, bool bSetupOwned);
struct _PacketInspection *getPacketInfo(void *priv, struct sk_buff *skb, const struct nf_hook_state *state);
void checkFree(struct _PacketInspection *pPacket, bool bLeavingPostRouting);
int isRequestForThreatElaboration(char *lpPayload,  struct iphdr *iph, struct udphdr *udph);
struct _Remote_infection *initElaboratedThreatInfo(struct _PacketInspection *pPacket);
__be32 hexstr_to_ip(const char *str);


void getTcpPayload(struct sk_buff *skb, char *lpBuffer, u32 nBufSize);
void sendMessage(int pid, char *msg);
void sendMessage2(int pid, char *lpReply);
void checkRequestForStatus(int pid, char *lpPayload);  //module_status.c
void test_send_message(int pid, char *lpMsg);
void checkTimedOperation(void);
int inspectThis(u32 sourceIp);
char *interpretSetup(char *lpBlockDescriptor, char *lpIpList);
char *interpretInspection(char *lpBlockDescriptor, char *lpIpList);
void listAssistRequests(void);
int requestedAssistance(unsigned int ipAddress, unsigned int nPort);
struct _InfectionSpecification *isInfected(volatile uint32_t ipAddress);
void removeInfection(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, short port);
bool trafficReportToTaralinkFound(int nProcessId);
void sendCheckRequests(int nProcessId);
void checkPartner(u32 nIp);
unsigned int tagThePacket(struct _PacketInspection *pPacket, const struct nf_hook_state *state, struct _InfectionSpecification *pInfected);	//module_tagging.c
int isNewConnection(struct sk_buff *skb);
void saveStolenPackage(struct _PacketInspection *pPacket);
uint8_t getDscp(struct _PacketInspection *pPacket);
void setDscp(struct iphdr *iph, uint8_t newDscp);
void recalcChecksum(struct _PacketInspection *pPacket);
void listInfectionsPointerList(void);
void setRemoteInfection(struct _Remote_infection *pRemoteInfection, struct _PacketInspection *pPacket, unsigned int dOwners_id, unsigned int dInfected, unsigned int dVersion, unsigned int nInfectionId, unsigned int nSeverity, unsigned int nBotnetId, char *lpInfo);
int clearIncomingTag(struct _PacketInspection *pPacket);

//Old one: static unsigned int sendUdpPackageAndQueueRetransmit(struct sk_buff *skb, const struct nf_hook_state *state, char *lpString);
static unsigned int sendUdpThreatPackage(__be32 destIp, __be32 sourceIp, __be16 sourcePort, struct _InfectionSpecification *pInfection);
static unsigned int queueRetransmit(struct sk_buff *skb, const struct nf_hook_state *state);

static int send_udp_json(__be32 daddr, __be16 dport, const char *json);

void checkThatTcp(struct _PacketInspection *pPacket, char *lpFromWhere);	//260320 - asdf... got problem with this....
//static void send_to_user(const char *msg);

//static int tcp_read_timestamp_option(struct sk_buff *skb, __be32 *tsval_be, __be32 *tsecr_be);
//static int tcp_set_timestamp_option(struct sk_buff *skb, bool set_tsval, __be32 new_tsval_be, bool set_tsecr, __be32 new_tsecr_be);

#define IPADDRESS(addr) \
	((unsigned char *)&addr)[3], \
	((unsigned char *)&addr)[2], \
	((unsigned char *)&addr)[1], \
	((unsigned char *)&addr)[0]

static char *cBlockDescriptor[] = {"SERVERS","INFECTIONS","WHITE_LIST","BLACK_LIST","PARTNERS","INSPECT","HONEYPORT","ASSIST","DROP"};

static struct _Setup *pSetup = NULL;

#include "module_configuration.h"
#include "module_store_configuration.h"
#include "module_packet_interpreter.h"

#include "module_TCP_functions.c"

#include "module_pre_routing_handler.c"
#include "module_status.c"
#include "module_timed_operations.c"
#include "module_packet_interpreter.c"
#include "module_store_configuration.c"

#include "module_forwarding.c"
#include "module_configuration.c"
#include "module_pointer_list.c"
#include "module_tagging.c"
#include "module_stolen.c"

#define NETLINK_USER 31

void warn(char *lpMsg){
	char* lpBuff = NULL;
	lpBuff = kmalloc(255 * sizeof(char), GFP_KERNEL);
	sprintf(lpBuff, "tarakernel (ver %d): %s", DEBUG_VERSION, lpMsg);
	printk(KERN_DEBUG "%s", lpBuff); 
	kfree(lpBuff);
}

void sendMessage(int pid, char *msg)
{
	struct nlmsghdr *nlh;
	int msg_size;
	int res;
	struct sk_buff *skb_out;
	msg_size=strlen(msg)+1;
	skb_out = nlmsg_new(msg_size,0);
	if(!skb_out)
	{
		printk(KERN_ERR "tarakernel: **** ERROR **** Failed to allocate new skb\n");
		return;
  	} 
	nlh=nlmsg_put(skb_out,0,0,NLMSG_DONE,msg_size,0);  
	NETLINK_CB(skb_out).dst_group = 0; /* not in mcast group */
  	strncpy(nlmsg_data(nlh),msg,msg_size);

	res=nlmsg_unicast(pSetup->nl_sk,skb_out,pid);

	if(res<0)
		printk(KERN_INFO "tarakernel: **** Error **** while sending to taralink\n");
	else
	{
		if (pSetup->cShowInstructions.bits.showOther)
			printk(KERN_INFO "tarakernel: Sent to taralink: %s\n", msg);
	}
}

void sendTestMessage(int nProcessId);
void sendTestMessage(int nProcessId)
{
}

int udpMsgFromSender(char *lpPayload);
int udpMsgFromSender(char *lpPayload)
{
    //************************ WARNING **************************** */
    //Exchanging threat info happens several places. Search for THREAT_INFO_EXCHANGE
    //Here is about sending extended threat info to new connections (altert is sent by tagging the packet header. The rest in UDP message)

	/*NOTE! This function is used both when:
		- Tarakernel at router forwards traffic from internal infected unit to new connections
		- When threat information is changed on router - it sends update info to partners that have established connection
		- MAYBE... also when target receives tagged traffic but doesn't have the extended threat info. E.g due to restart
		SO: DON'T CHANGE THE FORMAT WITHOUT ENSURING THAT IT CAN STILL HANDLE ALL INSTANCES
		CURRENTLY, the last one is not handled... it's only sending 5 fields, 9 are expected....
	*/

    /*260512 (reply to receivers request for info??):
	 ***** RECEIVED UDP message from sender via taralink: UDP_JSON:0A640069:50652^0^7^4^13^0^0^MMMMM

    What it looks like in taralink:
    UDP from 10.100.0.1:38003 -> UDP_JSON:0A640069:37334^0^7^4^13^0^0^MMMMM

    About to interprete: 0A640069:37334^0^7^4^13^0^0^MMMMM
    Unable to decode.. 1 fields founs
	Then it forwards to tarakernel (ends up here here)
    */

	if (!strcmp(lpPayload, "request_tarakernel_status"))
	{
//		pr_info("tarakernel: Request for info skipped: %s\n", lpPayload);
		return 0;
	}

	pr_info("tarakernel SENDING: ***** RECEIVED UDP message from sender: %s\n", lpPayload);

	//Search "Tagging UDP msg coding/decoding" for usage in source	(in module_tagging.c)
	//Coded by: sprintf(cUdpTagString, "%d:%d^%d^%d^%d", pPacket->ip_header->saddr, pPacket->tcp_header->source , cUnion.cTag.version_no, cUnion.cTag.presumed_infected, cUnion.cTag.botnet_id);
	unsigned int sIp, sPort, dVersion, dInfected, dOwners_id;
	char cSourceIp[100], cPrefix[100];
	unsigned int nInfectionId, nSeverity, nBotnetId;
	char cInfo[256];

	//Search for THREAT_INFO_EXCHANGE to find where it's generated
	int nFlds = sscanf(lpPayload, "%99[^ ] %99[^:]:%d^%d^%d^%d^%d^%d^%d^%255[^\n]", cPrefix, cSourceIp, &sPort, &dVersion, &dInfected, &dOwners_id, &nInfectionId, &nSeverity, &nBotnetId, cInfo);

	#define N_FIELDS_EXPECTED 10
		
	if (nFlds == N_FIELDS_EXPECTED)
	{
		sIp = hexstr_to_ip(cSourceIp);
		__be32 networkIp = sIp;	//asdfasdf was (htonl(sIp))
		__be16 networkPort = htons(sPort);
		bool bRegister = true;
		bool bRegistered;
		pr_info("tarakernel SENDING: ******* UDP threat info (via taralink) received: %pI4(%s):%d^%d^%d^%d, InfID: %d, sev: %d, botnet: %d, info: %s\n",
				&networkIp, cSourceIp, sPort, dVersion, dInfected, dOwners_id, nInfectionId, nSeverity, nBotnetId, cInfo);
		struct _Remote_infection *pRemoteInfection = findRemoteInfectionInfoReceived(networkIp, networkPort, bRegister = 1, &bRegistered);	

		if (!pRemoteInfection)
		{
			pr_warn("tarakernel: ********* ERROR ********** Unable to allocate memory for remote infection info.\n");
			return 0;
		}

		if (!bRegistered)
		{
			/*	bool bChanged = 0;

				if (!pRemoteInfection->lpInfo || strcmp(pRemoteInfection->lpInfo, cInfo))
				{
					pr_warn("tarakernel: ***** WARNING ***** Info is updated... Should have been changed... (%s -> %s)\n", pRemoteInfection->lpInfo, cInfo);
					bChanged = 1;
				}*/

			pr_info("tarakernel SENDING: Already have info for %pI4:%d - updated\n", &networkIp, sPort);
		}

		setRemoteInfection(pRemoteInfection, NULL, dOwners_id, dInfected, dVersion, nInfectionId, nSeverity, nBotnetId, cInfo);
		return 1;
	}

	pr_info("tarakernel SENDING: ****** ERROR ***** UDP message decoding failed. Found %d fields. Should have been %d\n", nFlds, N_FIELDS_EXPECTED);

	return 0;
}

static void hello_nl_recv_msg(struct sk_buff *skb)
{
        //The idea here is that taralink manages the traffic by directing tarakernel on what to do. (lpPayload will hold the instructions)
	struct nlmsghdr *nlhead;
	char cReply[100], *lpPayload, *lpConfigPrefix;
		
	nlhead = (struct nlmsghdr*)skb->data;    //nlhead message comes from skb's data... (sk_buff: unsigned char *data)
	lpPayload = (char*)nlmsg_data(nlhead);    

	if (!pSetup->taralink_pid)
		pSetup->taralink_pid = nlhead->nlmsg_pid;

	if (!bReceivedConfiguration || pSetup->cShowInstructions.bits.showOther)
		printk(KERN_INFO "tarakernel: Received: %s\n",lpPayload);

	//Check if it's config info (starts with CONFIG 0..n)
	lpConfigPrefix = strstr(lpPayload, "CONFIG ");
    
	if (lpConfigPrefix && (lpConfigPrefix == lpPayload))
	{
		module_storeConfiguration(lpPayload + strlen("CONFIG ")); //Check if this is the configuration....
		return;
	}

	//Now send reply...

	if (!bReceivedConfiguration)
		strcpy(cReply, "configuration 0?"); //Configuration not yet received.. Request it..
	else
	{
	    sendCheckRequests(nlhead->nlmsg_pid);    //Defined in module_timed_operations.c 
	    debugRoutine(); //Defined in module_timed_operations.c

		if (udpMsgFromSender(lpPayload))
			return;
		else
		    //*************** NOTE! Make changes here... probably never sends status as long as there's traffic....
			if (trafficReportToTaralinkFound(nlhead->nlmsg_pid)) //Defined in module_timed_operations.c
			{
			    //Also logged by trafficReportToTaralinkFound()
				//if (pSetup->cShowInstructions.bits.showOther)
				//	printk(KERN_INFO "tarakernel: Traffic report sent to taralink\n");
					
				return;
			}
			else
				if (!strcmp(lpPayload, "request_tarakernel_status"))
				{
			        //sendTestMessage(nlhead->nlmsg_pid); //Or do other debug stuff...
					checkRequestForStatus(nlhead->nlmsg_pid, lpPayload);   //Defined in module_status.c
					return;
				}
				else
    			{
        			strcpy(cReply, "Hello msg from kernel");
				}
        }
	sendMessage(nlhead->nlmsg_pid, cReply);
}

static void send_to_user(const char *msg)
{
	//Use this to send messages to user space initiated from kernel... 
	struct sk_buff *skb_out;
    struct nlmsghdr *nlh;
    int msg_size;
    int res;


	struct sock *nl_sk = pSetup->nl_sk;
	u32 pid = pSetup->taralink_pid;

	if (!pid)
	{
		pr_info("tarakernel: ****** ERROR ****** Taralink process id not yet initialized... Unable to send message - aborting: %s\n", msg);
		return;
	}

    msg_size = strlen(msg) + 1;

    skb_out = nlmsg_new(msg_size, GFP_KERNEL);
    if (!skb_out) {
        printk(KERN_ERR "Failed to allocate netlink skb\n");
        return;
    }

    nlh = nlmsg_put(skb_out, 0, 0, NLMSG_DONE, msg_size, 0);
    if (!nlh) {
        kfree_skb(skb_out);
        printk(KERN_ERR "nlmsg_put failed\n");
        return;
    }

    memcpy(nlmsg_data(nlh), msg, msg_size);

    res = nlmsg_unicast(nl_sk, skb_out, pid);
    if (res < 0)
        printk(KERN_INFO "Error while sending to user: %d\n", res);
}


//Timer callback and includes
#include <linux/timer.h>
#include <linux/jiffies.h>

static struct timer_list my_timer;


static void my_timer_cb(struct timer_list *t)
{
	pr_info("For now not getting here..... (unless you read this message)\n");

	//printk("tarakernel: my_timer fired  ******************** DEBUGGING ONLY:::\n");
		
    checkTimedOperation();  //module_timed_operations.h	- should be implemented as true timed operation - don't delay packets here....

    // rearm [TIMER_SECONDS] seconds later (if wanted) - or much for debug
	if (1)
		mod_timer(&my_timer, jiffies + msecs_to_jiffies(TIMER_SECONDS * 1000));
	else
		pr_info("tarakernel: **************Dropping rearming timer for debug! ******************");


	//send_to_user("tarakernel here... I got a timer.. how are you?");
}


static int __init hello_init(void) 
{
    //doPointerTest();  //See module_pointer_list.c
    //return 0;

    pr_info("tarakernel: Started (version %d). Start taralink to send configuration.\n", C_VERSION);
	
	if (!configuration_init())		//defined in module_configuration.c;
		pr_info("tarakernel: ****** ERROR ***** configuration_init() returned false\n");
		//return -1;
	
	if (1) 	//Do testing here after pSetup is initialized
	{
		pr_info("******** DEBUGGING (not initializing) **************");
		//Set up a timer
		timer_setup(&my_timer, my_timer_cb, 0);
    	mod_timer(&my_timer, jiffies + msecs_to_jiffies(5 * 1000));	// fire once after 1 second 

    	printk(KERN_INFO "tarakernel: timer armed\n");
		//return 0;
	}

	if (SYSTEM_OPERATION_LEVEL > 1) //Without this there's nothing
	{
		struct netlink_kernel_cfg cfg = {
    			.input = hello_nl_recv_msg,
		};

       	//Create socket and register call back funciton (hello_nl_recv_msg) for receiving messages from user space program (taralink)
		printk(KERN_INFO "tarakernel: NETLINK_USER=%d\n", NETLINK_USER);		
		printk(KERN_INFO "tarakernel: calling netlink_kernel_create()\n");
		pSetup->nl_sk = netlink_kernel_create(&init_net, NETLINK_USER, &cfg);

		if(!pSetup->nl_sk)
		{
			printk(KERN_ALERT "tarakernel: ************* Error creating socket.\n");
			return -10;
		}
		printk(KERN_INFO "tarakernel: netlink_kernel_create() succeeded.\n");
	}

	//******* Register PRE ROUTING hook ****************
	if (SYSTEM_OPERATION_LEVEL > 5) //Not really needed
	{
		pSetup->nf_PRE_ROUTING_hook_ops = (struct nf_hook_ops*)kcalloc(1, sizeof(struct nf_hook_ops), GFP_KERNEL);
		if (pSetup->nf_PRE_ROUTING_hook_ops != NULL) 
		{
			pSetup->nf_PRE_ROUTING_hook_ops->hook = (nf_hookfn*)module_ip4_pre_routing_handler;//nf_blockipaddr_handler;
			pSetup->nf_PRE_ROUTING_hook_ops->hooknum = NF_INET_PRE_ROUTING;
			pSetup->nf_PRE_ROUTING_hook_ops->pf = NFPROTO_IPV4;
			pSetup->nf_PRE_ROUTING_hook_ops->priority = NF_IP_PRI_CONNTRACK + 1; //NF_IP_PRI_FIRST;	NOTE! Start it after CONNTRACK is attached to the queue

			nf_register_net_hook(&init_net, pSetup->nf_PRE_ROUTING_hook_ops);
		}
	}

	//******* Register POST ROUTING hook ****************
	if (SYSTEM_OPERATION_LEVEL > 5) //Not really needed
	{
		pSetup->nf_POST_ROUTING_hook_ops = (struct nf_hook_ops*)kcalloc(1, sizeof(struct nf_hook_ops), GFP_KERNEL);
		if (pSetup->nf_POST_ROUTING_hook_ops != NULL) 
		{
			pSetup->nf_POST_ROUTING_hook_ops->hook = (nf_hookfn*)module_ip4_post_routing_handler;//nf_blockipaddr_handler;
			pSetup->nf_POST_ROUTING_hook_ops->hooknum = NF_INET_POST_ROUTING;
			pSetup->nf_POST_ROUTING_hook_ops->pf = NFPROTO_IPV4;
			pSetup->nf_POST_ROUTING_hook_ops->priority = NF_IP_PRI_FIRST;

			nf_register_net_hook(&init_net, pSetup->nf_POST_ROUTING_hook_ops);
		}
	}

	//******* Register FORWARDING hook ****************
	
	if (SYSTEM_OPERATION_LEVEL > 2) //This is a main function
	{
		pSetup->nf_FORWARDING_hook_ops = (struct nf_hook_ops*)kcalloc(1, sizeof(struct nf_hook_ops), GFP_KERNEL);
		if (pSetup->nf_FORWARDING_hook_ops != NULL) 
		{
			pSetup->nf_FORWARDING_hook_ops->hook = (nf_hookfn*)module_forwarding_handler;//nf_blockipaddr_handler;
			pSetup->nf_FORWARDING_hook_ops->hooknum = NF_INET_FORWARD;
			pSetup->nf_FORWARDING_hook_ops->pf = NFPROTO_IPV4;
			pSetup->nf_FORWARDING_hook_ops->priority = NF_IP_PRI_FIRST + 1;

			nf_register_net_hook(&init_net, pSetup->nf_FORWARDING_hook_ops);
		}
	}
	warn("tarakernel: Netfilter hooks registered\n");

	/*
	//Set up a timer
	timer_setup(&my_timer, my_timer_cb, 0);
    mod_timer(&my_timer, jiffies + msecs_to_jiffies(TIMER_SECONDS * 3 * 1000));	// 3 times normal in the start.. (in case debugging)
    pr_info("tarakernel: timer armed\n");
	*/
    pr_info("tarakernel: Timer disabled for now..\n");	//NOTE! Timer is for now handled by timer in taralink requesting.. Better try move it here.

	return 0;
}

static void __exit hello_exit(void) 
{
    if (pSetup->nf_PRE_ROUTING_hook_ops != NULL) {
		nf_unregister_net_hook(&init_net, pSetup->nf_PRE_ROUTING_hook_ops);
		kfree(pSetup->nf_PRE_ROUTING_hook_ops);
	}
    if (pSetup->nf_POST_ROUTING_hook_ops != NULL) {
		nf_unregister_net_hook(&init_net, pSetup->nf_POST_ROUTING_hook_ops);
		kfree(pSetup->nf_POST_ROUTING_hook_ops);
	}
	if (pSetup->nf_FORWARDING_hook_ops != NULL) {
		nf_unregister_net_hook(&init_net, pSetup->nf_FORWARDING_hook_ops);
		kfree(pSetup->nf_FORWARDING_hook_ops);
	}

    printk(KERN_INFO "tarakernel: Tarakernel stopped.\n");
	netlink_kernel_release(pSetup->nl_sk);

    // timer: safe teardown when unloading 
    timer_delete_sync(&my_timer);
    pr_info("tarakernel: timer stopped\n");
}

module_init(hello_init); 
module_exit(hello_exit);

MODULE_LICENSE("GPL");

