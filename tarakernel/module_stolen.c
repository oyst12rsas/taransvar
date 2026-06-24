
//module_stolen.c

/*#include <linux/kernel.h>
#include <linux/module.h>
#include <linux/skbuff.h>
#include <linux/ip.h>
#include <linux/udp.h>
#include <linux/in.h>
#include <linux/net.h>
#include <linux/socket.h>
#include <linux/string.h>
#include <net/sock.h>
*/


/*
static int send_udp_json_to_skb_dest(struct sk_buff *skb, const char *json)
{
  struct iphdr *iph;
  struct udphdr *udph;
  struct socket *sock = NULL;
    struct sockaddr_in addr = {0};
    struct msghdr msg = {0};
    struct kvec iov;
    int ret;

    if (!skb || !json)
        return -EINVAL;

    if (!pskb_may_pull(skb, sizeof(struct iphdr)))
        return -EINVAL;

    iph = ip_hdr(skb);
    if (!iph || iph->version != 4 || iph->protocol != IPPROTO_UDP)
        return -EINVAL;

    if (!pskb_may_pull(skb, ip_hdrlen(skb) + sizeof(struct udphdr)))
        return -EINVAL;

    udph = udp_hdr(skb);
    if (!udph)
        return -EINVAL;

    ret = sock_create_kern(&init_net, AF_INET, SOCK_DGRAM, IPPROTO_UDP, &sock);
    if (ret < 0) {
        //pr_err("sock_create_kern failed: %d\n", ret);
        pr_info("tarakernel: sock_create_kern failed: %d\n", ret);
        return ret;
    }

    addr.sin_family = AF_INET;
    addr.sin_addr.s_addr = iph->daddr;   // skb destination IP 
    addr.sin_port = TARALINK_LISTENING_TO_PORT;//udph->dest;          // skb destination UDP port 

    msg.msg_name = &addr;
    msg.msg_namelen = sizeof(addr);

    iov.iov_base = (char *)json;
    iov.iov_len  = strlen(json);

    ret = kernel_sendmsg(sock, &msg, &iov, 1, iov.iov_len);
    if (ret < 0)
        //pr_err("kernel_sendmsg failed: %d\n", ret);
        pr_info("kernel_sendmsg failed: %d\n", ret);
    else
        //pr_info("Sent %d bytes JSON to %pI4:%u\n", ret, &iph->daddr, ntohs(addr.sin_port));//udph->dest));
        pr_info("Sent %d bytes JSON to %pI4:%u\n", ret, &iph->daddr, ntohs(addr.sin_port));//udph->dest))

    sock_release(sock);
    return ret;
}*/

/*unsigned int hookfnHandleStolenPacket(...) 
{

  char json[256];

  snprintf(json, sizeof(json),
         "{\"src_ip\":\"%pI4\",\"src_port\":%u,"
         "\"dst_ip\":\"%pI4\",\"dst_port\":%u,"
         "\"event\":\"new-session\"}",
         &ip_hdr(skb)->saddr, ntohs(udp_hdr(skb)->source),
         &ip_hdr(skb)->daddr, ntohs(udp_hdr(skb)->dest));

  send_udp_json_to_skb_dest(skb, json);

    struct _PacketInspection *pPacket = pSetup->pStolenPacket[0];
    dev_queue_xmit(pSetup->skb);
}*/



#include <linux/kernel.h>
#include <linux/module.h>
#include <linux/skbuff.h>
#include <linux/ip.h>
#include <linux/udp.h>
#include <linux/in.h>
#include <linux/net.h>
#include <linux/socket.h>
#include <linux/string.h>
#include <linux/workqueue.h>
#include <linux/slab.h>
#include <net/sock.h>


static int send_udp_json(__be32 daddr, __be16 dport, const char *json)
{
    struct socket *sock = NULL;
    struct sockaddr_in addr = {0};
    struct msghdr msg = {0};
    struct kvec iov;
    int ret;

    ret = sock_create_kern(&init_net, AF_INET, SOCK_DGRAM, IPPROTO_UDP, &sock);
    if (ret < 0) {
        //pr_err("sock_create_kern failed: %d\n", ret);
        pr_info("tarakernel SENDING: sock_create_kern failed: %d\n", ret);
        return ret;
    }

    addr.sin_family = AF_INET;
    addr.sin_addr.s_addr = daddr;
    addr.sin_port = dport;

    msg.msg_name = &addr;
    msg.msg_namelen = sizeof(addr);

    iov.iov_base = (char *)json;
    iov.iov_len = strlen(json);

    ret = kernel_sendmsg(sock, &msg, &iov, 1, iov.iov_len);
    if (ret < 0)
        pr_info("tarakernel SENDING: ********* ERROR ********* kernel_sendmsg failed (while sending threat info UDP to partner): %d\n", ret);
    //else
    //  pr_info("tarakernel SENDING: Sent %d bytes to %pI4:%u\n", ret, &daddr, ntohs(dport));

    sock_release(sock);
    return ret;
}

struct delayed_fwd_job {
    struct work_struct work;
    struct sk_buff *skb;
    struct net_device *outdev;
};

static void delayed_forward_cb(struct work_struct *work)
{
    struct delayed_fwd_job *job = container_of(work, struct delayed_fwd_job, work);
    int ret;

    if (!job->skb)
        goto out;

    job->skb->dev = job->outdev;
    
    ret = dev_queue_xmit(job->skb);
    if (ret)
        pr_info("tarakernel SENDING: dev_queue_xmit failed: %d\n", ret);
    else
        pr_info("tarakernel SENDING: Original packet retransmitted\n");

out:
    if (job->outdev)
        dev_put(job->outdev);
    kfree(job);
}

static unsigned int sendUdpThreatPackage(__be32 destIp, __be32 sourceIp, __be16 sourcePort, struct _InfectionSpecification *pInfection)
{
    char cUdpTagString[400];
    //NOTE! sourceIp is client IP (E.g 192.168.50.100) - don't send that to remote server.. 
    // destIp is partner IP (where to send)

//    sprintf(cUdpTagString, "%08X:%d^%d^%d^%d ", ntohl(sourceIp), ntohs(sourcePort), pInfection->cTag.version_no, pInfection->cTag.presumed_infected, pInfection->cTag.owners_id);
    //************************ WARNING **************************** */
    //Exchanging threat info happens several places. Search for THREAT_INFO_EXCHANGE
    //Here is about sending extended threat info to new connections (altert is sent by tagging the packet header. The rest in UDP message)

    sprintf(cUdpTagString, "%s %08X:%d^%d^%d^%d^%d^%d^%d^%s", 
            "ELABORATED_THREAT_INFO",       //THREAT_INFO_SEPARATE_MSG
            ntohl(sourceIp), ntohs(sourcePort), 
            pInfection->cTag.version_no, 
            pInfection->cTag.presumed_infected, 
            pInfection->cTag.owners_id, 
            pInfection->nInfectionId, 
            pInfection->nSeverity, pInfection->nBotnetId, 
            pInfection->lpInfo);

    /* send UDP immediately */
    pr_info("tarakernel SENDING: New session. Sending UDP with threat info to receiver (%pI4:%d): %s.\n", &destIp, TARALINK_LISTENING_TO_PORT, cUdpTagString);
    pr_info("tarakernel: Format: ELABORATED_THREAT_INFO IP:PORT^VERSION^TAG_PRESUMED_INFECTED^TAG_OWNER_ID^INFECTION_ID^SEVERITY^BOTNET_ID^INFO\n");
    return send_udp_json(destIp, htons(TARALINK_LISTENING_TO_PORT), cUdpTagString);
}


static unsigned int queueRetransmit(struct sk_buff *skb, const struct nf_hook_state *state)
{
    struct delayed_fwd_job *job;
    struct iphdr *iph;
    struct tcphdr _tcph, *tcph;

    if (!skb || !state)
    {
      pr_info("tarakernel SENDING: No skb or wrong state\n");
        return NF_ACCEPT;   /* fail open */
    }

    iph = ip_hdr(skb);
    if (!iph || iph->version != 4 || iph->protocol != IPPROTO_TCP)
    {
      pr_info("tarakernel SENDING: Not IPv4 TCP\n");
        return NF_ACCEPT;   /* fail open */
    }

    tcph = skb_header_pointer(skb, ip_hdrlen(skb), sizeof(_tcph), &_tcph);
    if (!tcph)
        return NF_ACCEPT;

    /* hold original packet */
    job = kzalloc(sizeof(*job), GFP_ATOMIC);
    if (!job)
    {
      pr_info("tarakernel SENDING: Failed to alloc memory\n");
        return NF_ACCEPT;   /* fail open */
    }

    INIT_WORK(&job->work, delayed_forward_cb);
    job->skb = skb;
    job->outdev = state->out;
    if (job->outdev)
        dev_hold(job->outdev);

    schedule_work(&job->work);
    pr_info("tarakernel SENDING: Job for sending original packet scheduled\n");
    return NF_STOLEN;
}


