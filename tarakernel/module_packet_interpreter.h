#ifndef MODULE_PACKET_INTERPRETER_H_INCLUDED
#define MODULE_PACKET_INTERPRETER_H_INCLUDED

//module_packet_interpreter.h

#define INTERCEPTOR_VERSION 1
static int nPackageSequenceNumber = 0;
static int nPacketsInspected = 0;
#define N_INSPECT_PACKAGE_START_NUMBER 10
#define N_INSPECTION_PACKETS_TO_SHOW 50


//Check structures here:
//https://docs.huihoo.com/doxygen/linux/kernel/3.7/include_2uapi_2linux_2tcp_8h_source.html


int packetInterpreter(struct _PacketInspection *pPacket);


/* Relevant structures defined in c header files....
Be aware that there are two definitions of the same structure originating from Unix and Linux world..
struct iphdr {
    #if defined(__LITTLE_ENDIAN_BITFIELD)
        __u8    ihl:4,
                version:4;
    #elif defined (__BIG_ENDIAN_BITFIELD)
        __u8    version:4,
                ihl:4;
    #else
        #error  "Please fix <asm/byteorder.h>"
    #endif
         __u8   tos;
         __u16  tot_len;
         __u16  id;
         __u16  frag_off;
         __u8   ttl;
         __u8   protocol;
         __u16  check;
         __u32  saddr;
         __u32  daddr;
         //The options start here. 
};

struct tcphdr {
   25     __be16  source;
   26     __be16  dest;
   27     __be32  seq;
   28     __be32  ack_seq;
   29 #if defined(__LITTLE_ENDIAN_BITFIELD)
   30     __u16   res1:4,
   31         doff:4,
   32         fin:1,
   33         syn:1,
   34         rst:1,
   35         psh:1,
   36         ack:1,
   37         urg:1,
   38         ece:1,
   39         cwr:1;
   40 #elif defined(__BIG_ENDIAN_BITFIELD)
   41     __u16   doff:4,
   42         res1:4,
   43         cwr:1,
   44         ece:1,
   45         urg:1,
   46         ack:1,
   47         psh:1,
   48         rst:1,
   49         syn:1,
   50         fin:1;
   51 #else
   52 #error  "Adjust your <asm/byteorder.h> defines"
   53 #endif  
   54     __be16  window;
   55     __sum16 check;
   56     __be16  urg_ptr;
   57 };
   58 
 */

#endif


