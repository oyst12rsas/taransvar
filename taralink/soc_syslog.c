//soc_syslog.c
//Functions for receiving syslog (UDP 514) messages from firewalls or others. 

//To test:  echo '<34>1 2026-04-09T08:33:12Z firewall1 app1 1234 ID47 blocked connection from 1.2.3.4' | nc -u 10.10.10.10 514

/*
Iptables format: 
IN=eth0 OUT= MAC= SRC=1.2.3.4 DST=10.0.0.1 LEN=60 PROTO=TCP SPT=12345 DPT=22

Fortinet: 
srcip=1.2.3.4 srcport=12345 dstip=10.0.0.1 dstport=22 proto=6 action=deny

Cisco: 
%ASA-6-106100: access-list denied tcp 1.2.3.4/12345 -> 10.0.0.1/22

echo '<34>1 2026-04-09T08:33:12Z firewall1 app1 1234 ID47 blocked connection from 1.2.3.4 (Std RFC5424 msg)' | nc -u 10.10.10.254 514
echo 'IN=eth0 OUT= MAC= SRC=1.2.3.4 DST=10.0.0.1 LEN=60 PROTO=TCP SPT=12345 DPT=22' | nc -u 10.10.10.254 514
echo '%ASA-6-106100: access-list denied tcp 1.2.3.4/12345 -> 10.0.0.1/22' | nc -u 10.10.10.254 514
echo 'srcip=1.2.3.4 srcport=12345 dstip=10.0.0.1 dstport=22 proto=6 action=deny' | nc -u 10.10.10.254 514

NOTE! ***** REMEMBER TO OPEN THE SAID PORTS IN IPTABLES *****************
ACCEPT  udp  --  enp1s0  *  10.10.10.0/24  0.0.0.0/0  udp dpt:514
ACCEPT  udp  --  enp1s0  *  10.10.10.0/24  0.0.0.0/0  udp dpt:5551

sudo tcpdump -i any -nn udp port 514

*/

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <netinet/in.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

#define MAX_SOC_MSG 4096

typedef struct
{
    char szSenderIp[INET_ADDRSTRLEN];
    unsigned short nSenderPort;

    int nPri;
    int nFacility;
    int nSeverity;

    char szHostname[256];
    char szTag[128];
    char szMessage[2048];
    char szRaw[MAX_SOC_MSG];

    int bIsSyslog;
} SOC_SYSLOG_MSG;

struct _AttackEvent
{
    int is_attack;
    char action[32];

    char src_ip[64];
    int src_port;

    char dst_ip[64];
    int dst_port;

    char protocol[16];
    char device[128];
    char raw[4096];
    char cDescription[256];
} AttackEvent;

/*
create table syslog (
	syslogId int unsigned not null auto_increment, 
	senderIp int unsigned not null, 
    senderPort smallint unsigned not null,
	created timestamp not null default current_timestamp,
    pri integer null,
    facility integer null,
    severity integer null,
	hostname varchar(256),
    tag varchar(128),
    message text,
    rawmessage text,
	isSyslog tinyint null,
	primary key(syslogId)
);

create table syslogThreat(
	syslogThreatId int unsigned not null auto_increment, 
	syslogId int unsigned,
	created timestamp not null default current_timestamp,
	owner_id int unsigned null,
	unit_id int unsigned null,
	confirmed_unit_id int unsigned null,
    is_attack smallint unsigned null,
    action char(32),
	src_ip int unsigned not null, 
    src_port smallint unsigned not null,
	dst_ip int unsigned not null, 
    dst_port int unsigned not null,
    protocol varchar(16),
    device varchar(128),
	primary key(syslogThreatId)
);
*/


/* --------------------------------------------------------- */
/* Helpers                                                   */
/* --------------------------------------------------------- */

/*static void copy_trunc(char *dst, size_t dst_sz, const char *src)
{
    if (!dst || dst_sz == 0)
        return;

    if (!src)
    {
        dst[0] = '\0';
        return;
    }

    strncpy(dst, src, dst_sz - 1);
    dst[dst_sz - 1] = '\0';
}*/

static int extract_kv_string(const char *msg, const char *key, char *out, size_t out_sz)
{
    const char *p;
    size_t key_len;
    printf("Looking for %s\n", key);

    if (!msg || !key || !out || out_sz == 0)
        return 0;

    p = strstr(msg, key);
    if (!p)
        return 0;

    p += strlen(key);

    key_len = strcspn(p, " \t\r\n");
    if (key_len >= out_sz)
        key_len = out_sz - 1;

    memcpy(out, p, key_len);
    out[key_len] = '\0';
    printf("Found: %s\n", out);
    return 1;
}

static int extract_kv_int(const char *msg, const char *key, int *out)
{
    const char *p;

    if (!msg || !key || !out)
        return 0;

    p = strstr(msg, key);
    if (!p)
        return 0;

    p += strlen(key);
    *out = atoi(p);
    return 1;
}

static void initSocSyslogMsg(SOC_SYSLOG_MSG *pMsg)
{
    memset(pMsg, 0, sizeof(*pMsg));
    pMsg->nPri = -1;
    pMsg->nFacility = -1;
    pMsg->nSeverity = -1;
}

static void trimCrlf(char *s)
{
    if (!s)
        return;

    size_t len = strlen(s);

    while (len > 0 && (s[len - 1] == '\n' || s[len - 1] == '\r'))
    {
        s[len - 1] = '\0';
        len--;
    }
}

static int parseSyslogPri(const char *lpMsg, int *pnPri, const char **lpAfterPri)
{
    if (!lpMsg || lpMsg[0] != '<')
        return 0;

    const char *p = lpMsg + 1;
    int pri = 0;

    if (!isdigit((unsigned char)*p))
        return 0;

    while (*p && isdigit((unsigned char)*p))
    {
        pri = pri * 10 + (*p - '0');
        p++;
    }

    if (*p != '>')
        return 0;

    if (pnPri)
        *pnPri = pri;

    if (lpAfterPri)
        *lpAfterPri = p + 1;

    return 1;
}

static void copy_trunc(char *dst, size_t dst_sz, const char *src)
{
    //Just to avoid message:  warning: ‘%s’ directive output may be truncated writing up to 4095 bytes into a region of size 256 [-Wformat-truncation=]
    if (!dst || dst_sz == 0)
        return;

    if (!src)
    {
        dst[0] = '\0';
        return;
    }

    strncpy(dst, src, dst_sz - 1);
    dst[dst_sz - 1] = '\0';
}

// ************* DECODE KNOWN MESSAGE FORMATS ***************

// ************** iptables *****************
int parse_iptables(const char *msg, struct _AttackEvent *pEv)
{
    if (!msg || !pEv)
    {
        printf ("No msg or pEv\n");
        return 0;
    }

    if (!strstr(msg, "SRC=") || !strstr(msg, "DST="))
    {
        printf ("No SRC or DST in %s\n", msg);
        return 0;
    }

    pEv->src_port = -1;
    pEv->dst_port = -1;

    extract_kv_string(msg, "SRC=", pEv->src_ip, sizeof(pEv->src_ip));
    extract_kv_string(msg, "DST=", pEv->dst_ip, sizeof(pEv->dst_ip));
    extract_kv_string(msg, "PROTO=", pEv->protocol, sizeof(pEv->protocol));

    char cFrom[150];
    char cDesc[150];
    extract_kv_string(msg, "FROM=", cFrom, sizeof(cFrom));
    extract_kv_string(msg, "DESC=", cDesc, sizeof(cDesc));
    snprintf(pEv->cDescription, sizeof(pEv->cDescription), "FROM=%s, DESC=%s", cFrom, cDesc);

    extract_kv_int(msg, "SPT=", &pEv->src_port);
    extract_kv_int(msg, "DPT=", &pEv->dst_port);

    if (strstr(msg, "DROP") || strstr(msg, "REJECT") || strstr(msg, "DENY"))
    {
        pEv->is_attack = 1;
        copy_trunc(pEv->action, sizeof(pEv->action), "deny");
    }

    return 1;
}

// *********************** fortinet ****************************
int parse_fortinet(const char *msg, struct _AttackEvent *pEv)
{
    int proto_num = -1;

    if (!msg || !pEv)
        return 0;

    if (!strstr(msg, "srcip="))
        return 0;

    pEv->src_port = -1;
    pEv->dst_port = -1;

    extract_kv_string(msg, "srcip=", pEv->src_ip, sizeof(pEv->src_ip));
    extract_kv_int(msg, "srcport=", &pEv->src_port);
    extract_kv_string(msg, "dstip=", pEv->dst_ip, sizeof(pEv->dst_ip));
    extract_kv_int(msg, "dstport=", &pEv->dst_port);
    extract_kv_int(msg, "proto=", &proto_num);
    extract_kv_string(msg, "action=", pEv->action, sizeof(pEv->action));

    switch (proto_num)
    {
        case 6:  copy_trunc(pEv->protocol, sizeof(pEv->protocol), "TCP"); break;
        case 17: copy_trunc(pEv->protocol, sizeof(pEv->protocol), "UDP"); break;
        case 1:  copy_trunc(pEv->protocol, sizeof(pEv->protocol), "ICMP"); break;
        default: snprintf(pEv->protocol, sizeof(pEv->protocol), "%d", proto_num); break;
    }

    if (strcmp(pEv->action, "deny") == 0 || strcmp(pEv->action, "blocked") == 0)
        pEv->is_attack = 1;

    return 1;
}

#include <stdio.h>
#include <string.h>
#include <ctype.h>



// ************************ Cisco *************************************

int parse_cisco(const char *msg, struct _AttackEvent *ev)
{
    char action[32] = {0};
    char proto[16] = {0};
    char src_ip[64] = {0};
    char dst_ip[64] = {0};
    int src_port = -1;
    int dst_port = -1;

    if (!msg || !ev)
        return 0;

    ev->src_port = -1;
    ev->dst_port = -1;

    if (!strstr(msg, "%ASA-"))
        return 0;

    if (sscanf(msg,
               "%%ASA-%*d-%*d: access-list %31s %15s %63[^/]/%d -> %63[^/]/%d",
               action, proto, src_ip, &src_port, dst_ip, &dst_port) == 6)
    {
        copy_trunc(ev->action, sizeof(ev->action), action);
        copy_trunc(ev->protocol, sizeof(ev->protocol), proto);
        copy_trunc(ev->src_ip, sizeof(ev->src_ip), src_ip);
        copy_trunc(ev->dst_ip, sizeof(ev->dst_ip), dst_ip);
        ev->src_port = src_port;
        ev->dst_port = dst_port;

        if (strstr(action, "denied") || strstr(action, "deny") || strstr(action, "dropped"))
            ev->is_attack = 1;

        return 1;
    }

    return 0;
}

#include <cjson/cJSON.h>

int parse_cowrie_JSON(const char *msg, struct _AttackEvent *ev)
{
/*    const char *json_str =
        "{\"action\":\"archive\",\"dst_port\":2222,\"src_port\":38260,"
        "\"timestamp\":1775845748.2726,\"login_success\":1,"
        "\"event\":\"cowrie_session_closed\",\"confidence\":\"medium\","
        "\"dst_ip\":\"10.10.10.11\",\"sensor\":\"honeypotvm\","
        "\"src_ip\":\"10.10.10.10\",\"command_count\":0,"
        "\"proto\":\"tcp\",\"duration_sec\":\"218.442\","
        "\"session\":\"b5ee5263cc50\",\"severity\":4}";
    */

    cJSON *json = cJSON_Parse(msg);
    if (!json) {
        printf("Error parsing JSON\n");
        return 0;
    }

    // Extract fields
    const char *src_ip = cJSON_GetObjectItem(json, "src_ip")->valuestring;
    const char *dst_ip = cJSON_GetObjectItem(json, "dst_ip")->valuestring;
    int src_port       = cJSON_GetObjectItem(json, "src_port")->valueint;
    int dst_port       = cJSON_GetObjectItem(json, "dst_port")->valueint;
    int severity       = cJSON_GetObjectItem(json, "severity")->valueint;
    const char *event  = cJSON_GetObjectItem(json, "event")->valuestring;
    const char *proto  = cJSON_GetObjectItem(json, "proto")->valuestring;

    if (!src_ip || !strlen(src_ip))     return 0;
    if (!dst_ip || !strlen(dst_ip))     return 0;

	strcpy(ev->protocol, proto);
	strcpy(ev->src_ip, src_ip);
	strcpy(ev->dst_ip, dst_ip);
	ev->src_port = src_port;
	ev->dst_port = dst_port;

    const char *timestamp  = cJSON_GetObjectItem(json, "timestamp")->valuestring;
    const char *session  = cJSON_GetObjectItem(json, "session")->valuestring;
    const char *sensor  = cJSON_GetObjectItem(json, "sensor")->valuestring;
    const char *action  = cJSON_GetObjectItem(json, "action")->valuestring;

    snprintf(ev->cDescription, sizeof(ev->cDescription), "%s %s %s %s", timestamp, session, sensor, action);

    printf("Event: %s\n", event);
    printf("Source: %s:%d\n", src_ip, src_port);
    printf("Dest:   %s:%d\n", dst_ip, dst_port);
    printf("Severity: %d\n", severity);

    cJSON_Delete(json);
    return 1;
}






/*
 * Very lightweight syslog decoder.
 *
 * Handles:
 *   RFC3164-ish:
 *     <34>Oct 11 22:14:15 firewall1 sshd: Failed password for root
 *
 *   RFC5424-ish:
 *     <34>1 2026-04-09T08:33:12Z firewall1 appname 1234 ID47 message text
 */
static void decodeSocSyslog(const char *lpRaw, SOC_SYSLOG_MSG *pOut)
{
    const char *p = NULL;
    int pri = -1;

    if (!parseSyslogPri(lpRaw, &pri, &p))
    {
        snprintf(pOut->szMessage, sizeof(pOut->szMessage), "%s", lpRaw);
        return;
    }

    pOut->bIsSyslog = 1;
    pOut->nPri = pri;
    pOut->nFacility = pri / 8;
    pOut->nSeverity = pri % 8;

    /* ---------- Try RFC5424-ish first ---------- */
    if (isdigit((unsigned char)*p))
    {
        char szVersion[16]   = {0};
        char szTimestamp[64] = {0};
        char szHostname[256] = {0};
        char szApp[128]      = {0};
        char szProcId[64]    = {0};
        char szMsgId[64]     = {0};
        char szRest[2048]    = {0};

        int n = sscanf(p,
                       "%15s %63s %255s %127s %63s %63s %2047[^\n]",
                       szVersion,
                       szTimestamp,
                       szHostname,
                       szApp,
                       szProcId,
                       szMsgId,
                       szRest);

        if (n >= 6)
        {
            snprintf(pOut->szHostname, sizeof(pOut->szHostname), "%s", szHostname);
            snprintf(pOut->szTag, sizeof(pOut->szTag), "%s", szApp);

            if (n == 7)
                snprintf(pOut->szMessage, sizeof(pOut->szMessage), "%s", szRest);

            return;
        }
    }

    /* ---------- Fallback RFC3164-ish ---------- */
    {
        char szCopy[MAX_SOC_MSG];
        snprintf(szCopy, sizeof(szCopy), "%s", p);

        char *lpCursor = szCopy;

        while (*lpCursor == ' ')
            lpCursor++;

        /*
         * Skip standard RFC3164 timestamp "Oct 11 22:14:15"
         * which is normally 15 chars.
         */
        if (strlen(lpCursor) > 15)
        {
            lpCursor += 15;
            while (*lpCursor == ' ')
                lpCursor++;
        }

        /* hostname */
        char *lpHost = lpCursor;
        while (*lpCursor && *lpCursor != ' ')
            lpCursor++;

        if (*lpCursor)
        {
            *lpCursor = '\0';
            copy_trunc(pOut->szHostname, sizeof(pOut->szHostname), lpHost);
            lpCursor++;
        }

        while (*lpCursor == ' ')
            lpCursor++;

        /* tag/app up to ':' or space */
        char *lpTag = lpCursor;
        while (*lpCursor && *lpCursor != ':' && *lpCursor != ' ')
            lpCursor++;

        char cSaved = *lpCursor;
        *lpCursor = '\0';
        copy_trunc(pOut->szTag, sizeof(pOut->szTag), lpTag);
        *lpCursor = cSaved;

        while (*lpCursor && *lpCursor != ':')
            lpCursor++;

        if (*lpCursor == ':')
            lpCursor++;

        while (*lpCursor == ' ')
            lpCursor++;

        copy_trunc(pOut->szMessage, sizeof(pOut->szMessage), lpCursor);
    }
}

static void printSocSyslogMsg(const SOC_SYSLOG_MSG *pMsg)
{
}


/* --------------------------------------------------------- */
/* Main handler                                              */
/* --------------------------------------------------------- */

void handle_soc_syslogmsg(int soc_fd)
{
    char szBuf[MAX_SOC_MSG];
    struct sockaddr_in srcAddr;
    socklen_t nSrcLen = sizeof(srcAddr);

    ssize_t nRead = recvfrom(soc_fd,
                             szBuf,
                             sizeof(szBuf) - 1,
                             0,
                             (struct sockaddr *)&srcAddr,
                             &nSrcLen);

    if (nRead < 0)
    {
        if (errno != EINTR)
            perror("recvfrom(syslog)");
        return;
    }

    szBuf[nRead] = '\0';
    trimCrlf(szBuf);
	//printf("Received: %s\n", szBuf);

    SOC_SYSLOG_MSG msg;
    initSocSyslogMsg(&msg);

    inet_ntop(AF_INET, &srcAddr.sin_addr, msg.szSenderIp, sizeof(msg.szSenderIp));
    msg.nSenderPort = ntohs(srcAddr.sin_port);

    snprintf(msg.szRaw, sizeof(msg.szRaw), "%s", szBuf);

    decodeSocSyslog(szBuf, &msg);

    printSocSyslogMsg(&msg);


    struct _AttackEvent ev;
    memset(&ev, 0, sizeof(ev));
	char *lpIdentifiedAs = NULL;

    if (parse_cisco(szBuf, &ev))
		lpIdentifiedAs = "cisco";
    else 
        if (parse_fortinet(szBuf, &ev))
			lpIdentifiedAs = "fortinet";
		else
			if (parse_iptables(szBuf, &ev))
				lpIdentifiedAs = "iptables";
            else
                if (parse_cowrie_JSON(szBuf, &ev))
    				lpIdentifiedAs = "cowrie";
                else
    				lpIdentifiedAs = "other";

//select syslogThreatId, L.syslogId, L.created, owner_id, unit_id, inet_ntoa(src_ip) as source, inet_ntoa(dst_ip) as dest, protocol, service, description, CAST(handled AS UNSIGNED) as handled, dst_ip, rawmessage from syslogThreat T join syslog L on L.syslogId = T.syslogId where rawmessage like '%cowrie%' order by syslogThreatId desc limit 3;
//select syslogId, rawmessage from syslog order by syslogId desc limit 20;
//select created, syslogId, rawmessage from syslog where substr(rawmessage,1,4) <> 'DROP' order by syslogId desc limit 20;
//select syslogThreatId, created, service, description from syslogThreat order by syslogThreatId desc limit 5;

//Select of crontask handling them: 
//select syslogId, src_ip, inet_ntoa(src_ip) as src, src_port, dst_ip, inet_ntoa(dst_ip) as dst, dst_port, protocol, service from syslogThreat where handled is null limit 1000


    MYSQL *conn = getConnection();

   	MYSQL_STMT *stmt = mysql_stmt_init(conn);
    MYSQL_BIND param[3];
    memset(param, 0, sizeof(param));

	char *sql = "insert into syslog (senderIp, senderPort, rawmessage) values (?, ?, ?)";

    if (mysql_stmt_prepare(stmt, sql, strlen(sql)) != 0) {
   	    printf("prepare failed: %s\n", mysql_stmt_error(stmt));
       	return;
   	}

   	unsigned int ip = inet_addr(ev.src_ip);//szSenderIp);   // or your actual value
	unsigned short port = ev.src_port;//nSenderPort;
   	//unsigned short port = sPort;
   	unsigned int sentByIp = ip;
   	unsigned long cMsgLen = strlen(szBuf);//ev.szRaw);

   	/* ---- BIND ---- */
   	param[0].buffer_type = MYSQL_TYPE_LONG;
   	param[0].buffer = &ip;
   	param[0].is_unsigned = 1;

   	param[1].buffer_type = MYSQL_TYPE_SHORT;
   	param[1].buffer = &port;
   	param[1].is_unsigned = 1;

   	param[2].buffer_type   = MYSQL_TYPE_STRING;
   	param[2].buffer        = (void *)szBuf;//ev.szRaw;
   	param[2].buffer_length = sizeof(szBuf);//ev.szRaw);
   	param[2].length        = &cMsgLen;

    /* ---- BIND PARAMS ---- */
   	if (mysql_stmt_bind_param(stmt, param) != 0) {
       	printf("bind failed: %s\n", mysql_stmt_error(stmt));
        return;
   	}

    /* ---- EXECUTE ---- */
   	if (mysql_stmt_execute(stmt) != 0) {
       	printf("execute failed: %s\n", mysql_stmt_error(stmt));
       	return;
   	}   

	mysql_stmt_close(stmt);

	printf("Successfully saved to syslog table\n");
	printf("\n[SOC SYSLOG] ----------------------------------------\n");

 	if (lpIdentifiedAs)
	{
        printf("[SOC SYSLOG] Idendified as %s message: attack=%d action=%s proto=%s src=%s:%d dst=%s:%d\n",
				lpIdentifiedAs, 
            	ev.is_attack,
            	ev.action,
            	ev.protocol,
            	ev.src_ip, ev.src_port,
                ev.dst_ip, ev.dst_port);

	   	MYSQL_STMT *stmt = mysql_stmt_init(conn);
	    MYSQL_BIND param[8];
	    memset(param, 0, sizeof(param));

		char *sql = "insert into syslogThreat(syslogId, protocol, src_ip, src_port, dst_ip, dst_port, service, description) values (?, ?, ?, ?, ?, ?, ?, ?)";

	    if (mysql_stmt_prepare(stmt, sql, strlen(sql)) != 0) {
   		    printf("prepare failed: %s\n", mysql_stmt_error(stmt));
       		return;
	   	}

		unsigned int syslogId = mysql_insert_id(conn);
   		//unsigned short port = sPort;
   		unsigned int sentByIp = ip;
   		unsigned long cMsgLen = strlen(szBuf);//pMsg->szRaw);

   		/* ---- BIND ---- */
		//syslogId
   		param[0].buffer_type = MYSQL_TYPE_LONG;
   		param[0].buffer = &syslogId;
   		param[0].is_unsigned = 1;

		//protocol
        char cProtocol[20];
        strcpy(cProtocol, ev.protocol);
       	unsigned long cProtocolLen = strlen(cProtocol);
       	param[1].buffer_type   = MYSQL_TYPE_STRING;
   	    param[1].buffer        = cProtocol;
   	    param[1].buffer_length = sizeof(cProtocol);
   	    param[1].length        = &cProtocolLen;

		//src_ip
	   	unsigned int srcIp = ntohl(inet_addr(ev.src_ip));   // or your actual value
   		param[2].buffer_type = MYSQL_TYPE_LONG;
   		param[2].buffer = &srcIp;
   		param[2].is_unsigned = 1;

	    //src_port smallint unsigned not null,
		param[3].buffer_type = MYSQL_TYPE_SHORT;
		unsigned short srcPort = ev.src_port;
   		param[3].buffer = &srcPort;
   		param[3].is_unsigned = 1;

		//dst_ip int unsigned not null, 
	   	unsigned int dstIp = ntohl(inet_addr(ev.dst_ip));   // or your actual value
   		param[4].buffer_type = MYSQL_TYPE_LONG;
   		param[4].buffer = &dstIp;
   		param[4].is_unsigned = 1;

        printf("Converted IPs: %s (%u) -> %s (%u)\n", ev.src_ip, srcIp, ev.dst_ip, dstIp);
    
		//dst_port int unsigned not null,
		param[5].buffer_type = MYSQL_TYPE_SHORT;
		unsigned short dstPort = ev.dst_port;
   		param[5].buffer = &dstPort;
   		param[5].is_unsigned = 1;

        //service
        char cService[20];
        strcpy(cService, lpIdentifiedAs);
       	unsigned long cServiceLen = strlen(cService);
       	param[6].buffer_type   = MYSQL_TYPE_STRING;
   	    param[6].buffer        = cService;
   	    param[6].buffer_length = sizeof(cService);
   	    param[6].length        = &cServiceLen;

        //description
		unsigned long cDescriptionLen = strlen(ev.cDescription);
		param[7].buffer_type   = MYSQL_TYPE_STRING;
		param[7].buffer        = ev.cDescription;
		param[7].buffer_length = sizeof(ev.cDescription);
		param[7].length        = &cDescriptionLen;

		printf("Added to syslogThreat: %s(%u):%u -> %s(%u):%u\n", ev.src_ip, srcIp, srcPort, ev.dst_ip, dstIp, dstPort);

		/* ---- BIND PARAMS ---- */
		if (mysql_stmt_bind_param(stmt, param) != 0) {
			printf("bind failed: %s\n", mysql_stmt_error(stmt));
			return;
		}

	    /* ---- EXECUTE ---- */
   		if (mysql_stmt_execute(stmt) != 0) {
       		printf("execute failed: %s\n", mysql_stmt_error(stmt));
       		return;
   		}   

		mysql_stmt_close(stmt);

	}

   	mysql_close(conn);

    printf("[SOC SYSLOG] From      : %s:%u\n", ev.src_ip, ev.src_port);//pMsg->szSenderIp, pMsg->nSenderPort);
    printf("[SOC SYSLOG] IsSyslog  : %s\n", msg.bIsSyslog ? "yes" : "no");
    printf("[SOC SYSLOG] PRI       : %d\n", msg.nPri);
    printf("[SOC SYSLOG] Facility  : %d\n", msg.nFacility);
    printf("[SOC SYSLOG] Severity  : %d\n", msg.nSeverity);
    printf("[SOC SYSLOG] Hostname  : %s\n", msg.szHostname[0] ? msg.szHostname : "(none)");
    printf("[SOC SYSLOG] Tag       : %s\n", msg.szTag[0] ? msg.szTag : "(none)");
    printf("[SOC SYSLOG] Message   : %s\n", msg.szMessage[0] ? msg.szMessage : "(none)");
    printf("[SOC SYSLOG] Raw       : %s\n", msg.szRaw);



    /*
     * Hook 1: Save to DB
     *
     * Example:
     * insertSocSyslog(conn,
     *                 msg.szSenderIp,
     *                 msg.nSenderPort,
     *                 msg.nPri,
     *                 msg.nFacility,
     *                 msg.nSeverity,
     *                 msg.szHostname,
     *                 msg.szTag,
     *                 msg.szMessage,
     *                 msg.szRaw);
     */

    /*
     * Hook 2: Decide whether to notify kernel
     *
     * Example:
     * if (strstr(msg.szMessage, "blocked") || strstr(msg.szMessage, "attack"))
     * {
     *     char szKernelMsg[512];
     *     snprintf(szKernelMsg, sizeof(szKernelMsg),
     *              "SOC_LOG %s PRI=%d MSG=%s",
     *              msg.szSenderIp, msg.nPri, msg.szMessage);
     *
     *     send_to_kernel(g_nl_fd, szKernelMsg, strlen(szKernelMsg) + 1);
     * }
     */
}