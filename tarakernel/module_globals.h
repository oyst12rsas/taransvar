#ifndef MODULE_GLOBALS_H
#define MODULE_GLOBALS_H

#ifdef __KERNEL__
#include <linux/types.h>
#else
#include <stdint.h>
#endif

//Structs that are used both by absecurity kernel module and abmonitor user space program

//Defining prefixes used with THREAT_INFO_EXCHANGE (search this keyword for usage)
#define UDP_MSG_PREFIX                  "UDP_JSON"                      //No longer in use?
#define THREAT_INFO_SEPARATE_MSG        "THREAT_INFO_SEPARATE_MSG"
#define INFECTION_CHANGED_PREFIX        "INFECTION_CHANGED"
#define UDP_THREAT_INFO_REQUEST_PREFIX  "THREAT_INFO_REQUEST"
#define C_TRAFFIC_REPORT_PREFIX "TRAFFIC|"

//Used by sender when there's a new connection from infected unit to transmit elaborated threat information (this could just as well have been picket up by kernel as sent to user space and back to kernel)
#define TARALINK_LISTENING_TO_PORT      5551

//Used when receiver requests lacking information about infected unit sending traffic (normally just after restart or because expiring info has already been dropped)
#define TARAKERNEL_LISTENING_TO_PORT      5552  

//For some reason, netlink messages seems not to be able to exceed 1050 byte or so, wich equals to around 50 messages. According to google, minimum should be 16kb, some say 4GB
#define C_TRAFFIC_REPORT_ARRAY_SIZE 49


//Used by taralink to set timer interval (seconds between pinging tarakernel). Seems to handle 1sek quite well. Bigger problem with big intervals like 30 easily cause problem
#define C_TIMER_INTERVAL_MILLISECONDS 0
#define C_TIMER_INTERVAL_SECONDS 5

enum et_CheckType {e_PossiblePartner}; 


struct _showStatusBits 
{
    //    unsigned short nDummy : 2;    NOTE! All 16 bits are used (so removing the nDummy field). When adding more fields, do 32bit??...
        unsigned char doingNAT : 1;
        unsigned char showStatus : 1;
        unsigned char showPreRoutePartner : 1;
        unsigned char showPreRouteNonPartner : 1;
        unsigned char showForwardPartner : 1;
        unsigned char showForwardNonPartner : 1;
        unsigned char showUrgentPtrUsage : 1;
        unsigned char showOwnerless : 1;
        unsigned char showOther : 1;
        unsigned char showNew1 : 1;
        unsigned char showNew2 : 1;
        unsigned char doTagging : 1;
        unsigned char doReportTraffic :1;        
        unsigned char doInspection : 1;
        unsigned char doBlocking : 1;
        unsigned char doOther : 1;
};

union _showStatusBitsUnion
{
        struct _showStatusBits bits;
        unsigned short nValues;        
};


//OT_changed: 260225 - _Tag, _TagUnion and _PacketInspection moved from tarakernel.h,  _TagUnion put in _PacketInspection and _ipPort2 structures...
//***************** WARNING ************ If changing this structure, then also change the php interpretation in gatekeeper/ajax/tagStatus.php */
struct _Tag { //2 bytes - for use with the tcp_header->urg_ptr until we decide to increase TCP header size
	unsigned int version_no : 2; //ØT 260323 3->2  //Set to TAG_VERSION_NO. Counting down in case field is used and hoping that it points to outside the block and programmers care to check.
	unsigned int presumed_infected : 4; //ØT 260323 3->4 //0=No indication, 1=Wifi/VPN, 2=rough partner, 3=probably malconfig, 4=probably sporadic, 5=probably bot  
	//unsigned int botnet_id : 10;   //Assigned by Akili Bomba
        unsigned int owners_id : 10;
};

union _TagUnion {
	struct _Tag     cTag;
	//__be16 nBe16; /Unavailable in user space
        uint16_t        nTag;  //Use this to initialize the whole _Tag struct.
};

struct _ipPort2 {
    uint32_t sIp, dIp;
    uint16_t sPort, dPort, nCount;

    union {
        struct _Tag cTag;
        uint16_t nTag;
    };
};

#endif
