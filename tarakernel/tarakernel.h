//absecurity.h

#ifndef INCLUDED_ABSECURITY_H
#define INCLUDED_ABSECURITY_H

#define USE_POINTER_LIST 1

#include "module_globals.h"

//OT_Changed: 260225 - Some structs moved to module_globals.h


struct _PacketInspection
{
    struct sk_buff *skb; 
    const struct nf_hook_state *state;
	char cSourceIp[20], cDestIp[20];
	u32 sIp, dIp;
	int sPort, dPort;
	struct iphdr *ip_header; // ip header struct
	struct tcphdr *tcp_header; // tcp header struct
    int nTcpHeaderSize;
    int nTotLen;
    char *lpPayload;        
	union _TagUnion cTagUnion;
};


struct _statistics {
    int nPreRouting;
    int nIncoming;
    int nOutgoing;
    int nForwarded;
    int nTagged;
    int nBlocked;
    //New ones:
    int nFromPartnerTagged;
    int nFromPartnerUntagged;
    int nOutboundTagged;
};


#define TAG_VERSION_NO  0b111

#define C_REQUESTS_CLEAN 0
#define C_REQUESTS_PRESUMED_CLEAN 1
#define C_REQUESTS_NOT_BOT 2

#define C_CAT_CLEAN	0
#define C_CAT_REPORTED 	1
#define C_CAT_DROP 	3

#define C_TARGET_CLEAN 0
#define C_TARGET_UNKNOWN 1
#define C_TARGET_BOT 	2
#define C_TARGET_BOTNET_CCS 3

#define C_FREQ_CLEAN 0
#define C_FREQ_FIRSTTIME 1
#define C_FREQ_SPORADIC 2
#define C_FREQ_HACK	3
#define C_FREQ_DOS	4
#define C_FREQ_HOTSPOT	5

#define BLOCK_DESCRIPTIOR_SERVERS	0
#define BLOCK_DESCRIPTIOR_INFECTIONS	1
#define BLOCK_DESCRIPTIOR_WHITE_LIST	2
#define BLOCK_DESCRIPTIOR_BLACK_LIST	3
#define BLOCK_DESCRIPTIOR_PARTNERS	4
#define BLOCK_DESCRIPTIOR_INSPECT	5
#define BLOCK_DESCRIPTIOR_HONEYPORT	6
#define BLOCK_DESCRIPTIOR_ASSIST	7
#define BLOCK_DESCRIPTIOR_DROP		8

#define BLOCK_DESCRIPTIOR_LAST	BLOCK_DESCRIPTIOR_DROP

int elementStructSize(int nBlockDescriptor);

struct _threatSpecification {
	//Info to be passed on for specific address or range of addresses
	unsigned int version : 8; //in case various gatekeepers use different struct version... 
	unsigned int category : 3; //See C_CAT_CLEAN++ definition above
	unsigned int targeting : 2; //See C_TARGET_CLEAN++ definition above
	unsigned int frequency : 3; //See C_FREQ_CLEAN++ definition above
	//unsigned int freeSpace : 1;	//To align previous field to one byte.
	unsigned int botNetId;	//Assigned by AkiliBomba
};

struct _Ip4AddrPortRange {
	char from[4];
	unsigned short int portFrom;
	char to[4];
	unsigned short int portTo;
	struct _threatSpecification threat;
};

struct _ServerSpecification {
	//volatile uint32_t ipAddress;
	unsigned short int port;      //Port on router to be redirected to machine on subnet (redirection is handled by perl scrips setting up iptables/nf_tables)
	unsigned int requests : 3; 	
};

struct _ColoredIpSpecification {	//For black/whitelisting
	volatile uint32_t ipAddress;
	unsigned short int port;
};

struct _InfectionSpecification {
	volatile uint32_t ipAddress;
	volatile uint32_t ipNettmask;
	struct _threatSpecification cThreat;
};

struct _PartnerSpecification {
	volatile uint32_t ipAddress;
	volatile uint32_t ipNettmask;
};

struct _InspecitonSpecification {
	volatile uint32_t ipAddress;
	volatile uint32_t ipNettmask;
};

struct _HoneyportSpecification {
        u32 port;
        char handling[20];  //NOTE! Better make this enum field. but after all there'll not be many... 
};

struct _AssistanceRequest {
	volatile uint32_t ipAddress;
        u32 port;
        unsigned short nQuality : 7;
        short bWantsSpoofed : 1;
};

struct _WhoIs {
	volatile uint32_t ipAddress;
        char szWhoIs[21];
};

struct _CheckIp {
        enum et_CheckType eCheckType;
        u32   ip;
};

#define C_CHECK_ARRAY_SIZE 5

struct _Setup {
	bool bTrafficReportsBeingHandled;
        char cTrafficPrefix[8]; //supposed to contain "TRAFFIC|", see C_TRAFFIC_REPORT_PREFIX defined in module_globals.h
        struct _ipPort2 cPendingIncomingReportArr[C_TRAFFIC_REPORT_ARRAY_SIZE]; //struct _ipPort2 is defined in module_globals.h because also used by AbMonitor
        //struct _ipPort2 cPendingOutgoingReportArr[C_TRAFFIC_REPORT_ARRAY_SIZE];  
	u32 nMyIp;
	u32 nInternalIp;  //OT1111
	u32 nNettmask;
//	u16 nShowInstructions;
	union _showStatusBitsUnion cShowInstructions;   
	struct _statistics cGlobalStatistics;
	void *pConfiguration[BLOCK_DESCRIPTIOR_LAST+1];     //NOTE! About to become depricated. Use pConfigurationPointerList instead 
	void *pConfigurationPointerList[BLOCK_DESCRIPTIOR_LAST+1];    //NOTE! About to take over from pConfiguration 
	unsigned int nConfigurationArraySize[BLOCK_DESCRIPTIOR_LAST+1]; //Max number of elements in array... NOTE! To be dropped later??
	unsigned int nElementsInArray[BLOCK_DESCRIPTIOR_LAST+1];      //Number of elemens currently in arrayNOTE! To be dropped
	
	//Traffic warnings waiting to be sent to ABMonitor
  
	u64 nLastTimedOperation; // = 0;
	struct sock *nl_sk;// = NULL;

	//static struct nf_hook_ops *nf_blockicmppkt_ops = NULL;
	struct nf_hook_ops *nf_PRE_ROUTING_hook_ops; // = NULL;
	struct nf_hook_ops *nf_POST_ROUTING_hook_ops;// = NULL;
	struct nf_hook_ops *nf_FORWARDING_hook_ops;// = NULL;
	char c100[200];
	unsigned char nBlockIncomingTaggedTrafficLevel;
	
	struct _CheckIp cCheckThese[C_CHECK_ARRAY_SIZE];
};

typedef struct _Node _Node;
static int nElementsInList = 10;
static int nIteration = 0;

struct _Node {
  _Node *pNext;
  union {
	struct _InfectionSpecification cInfection;
	struct _PartnerSpecification cPartner;
      };
};


void getMeAndMine(char *lpBuf, int nBufSize);
int isMeOrMine(unsigned int nIp);
int isSubNet(unsigned int nIp);
void debugRoutine(void);  //Defined in module_timing_operations.c, called in tarakernel.c when receiving request for status from taralink.
int checkFixTagging(struct _PacketInspection *pPacket, bool bForwarding);   //module_forwarding.c
void *memAlloc(int nSize);  //Defined in module_pointer_list.c
_Node *getNewBefore(_Node *pPointer, int nStructSize);
_Node *getNewAfter(_Node *pPointer, int nStructSize); //Defined in module_pointer_list.c
_Node *getLast(_Node *pPointer);  //Defined in module_pointer_list.c
void doInfectionsPointerListTest(void); //Remove this when tested..

#endif
