#ifndef MODULE_GLOBALS_H
#define MODULE_GLOBALS_H

//Structs that are used both by absecurity kernel module and abmonitor user space program

#define C_TRAFFIC_REPORT_PREFIX "TRAFFIC|"

//For some reason, netlink messages seems not to be able to exceed 1050 byte or so, wich equals to around 50 messages. According to google, minimum should be 16kb, some say 4GB
#define C_TRAFFIC_REPORT_ARRAY_SIZE 49


//Used by taralink to set timer interval (seconds between pinging tarakernel). Seems to handle 1sek quite well. Bigger problem with big intervals like 30 easily cause problem
#define C_TIMER_INTERVAL_SECONDS 10

enum et_CheckType {e_PossiblePartner}; 


struct _showStatusBits 
{
        unsigned short nDummy : 2;
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

struct _Tag { //2 bytes - for use with the tcp_header->urg_ptr until we decide to increase TCP header size
	unsigned int version_no : 3;  //Set to TAG_VERSION_NO. Counting down in case field is used and hoping that it points to outside the block and programmers care to check.
	unsigned int presumed_infected : 3; //0=No indication, 1=Wifi/VPN, 2=rough partner, 3=probably malconfig, 4=probably sporadic, 5=probably bot  
	unsigned int botnet_id : 10;   //Assigned by Akili Bomba
};

union _TagUnion {
	struct _Tag cTag;
	__be16 nBe16;
};

struct _ipPort2 {
	volatile uint32_t ip;
	//uint32_t sPort, dPort, nCount;
        unsigned short int sPort, dPort, nCount;  //u32
        union _TagUnion cTagUnion;
};

#endif
