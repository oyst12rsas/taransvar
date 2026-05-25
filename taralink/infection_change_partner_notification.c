//infection_change_partner_notification.c

struct _InfectionSpecification {
	__be32 ipAddress;
	__be32 ipNettmask;
	unsigned int nInfectionId, nSeverity, nBotnetId;
	char *lpInfo;
	union 
	{
		struct _Tag		cTag;
		uint16_t		nTag;
	};
	int bHandled : 1;			//Set to 1 if infection info is changed... will send info to all partners who's been communicating...
	
//	union _TagUnion	cUnion;
};


#include <pthread.h>
#include <arpa/inet.h>

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <regex.h>

#include <ctype.h>

#define CT_STRSZ 64
#define CT_LINESZ 4096



struct conntrack_tuple {
	char src[CT_STRSZ];
	char dst[CT_STRSZ];
	int  sport;
	int  dport;
};

struct conntrack_record {
	char proto[16];
	char state[32];
	struct conntrack_tuple orig;
	struct conntrack_tuple reply;
};



static int extract_conntrack_tuple(const char *line,
				char *src, size_t src_sz,
				char *dst, size_t dst_sz,
				char *sport, size_t sport_sz,
				char *dport, size_t dport_sz)
{
	regex_t regex;
	regmatch_t matches[5];

	const char *pattern =
		"src=([^ ]+) dst=([^ ]+) sport=([^ ]+) dport=([^ ]+)";

	if (regcomp(&regex, pattern, REG_EXTENDED) != 0) {
		fprintf(stderr, "regcomp failed\n");
		return -1;
	}

	if (regexec(&regex, line, 5, matches, 0) != 0) {
		regfree(&regex);
		return 1;   /* no match */
	}

    #define COPY_MATCH(dstbuf, dstbufsz, idx) do { \
        int len = matches[idx].rm_eo - matches[idx].rm_so; \
        if ((size_t)len >= (dstbufsz)) len = (dstbufsz) - 1; \
        memcpy((dstbuf), line + matches[idx].rm_so, len); \
        (dstbuf)[len] = '\0'; \
    } while (0)

    COPY_MATCH(src,   src_sz,   1);
    COPY_MATCH(dst,   dst_sz,   2);
    COPY_MATCH(sport, sport_sz, 3);
    COPY_MATCH(dport, dport_sz, 4);

    regfree(&regex);
    return 0;
}

static void init_tuple(struct conntrack_tuple *t)
{
    if (!t)
        return;

    t->src[0] = '\0';
    t->dst[0] = '\0';
    t->sport = -1;
    t->dport = -1;
}

static void init_record(struct conntrack_record *r)
{
    if (!r)
        return;

    r->proto[0] = '\0';
    r->state[0] = '\0';
    init_tuple(&r->orig);
    init_tuple(&r->reply);
}

static int is_number_string(const char *s)
{
    if (!s || !*s)
        return 0;

    while (*s) {
        if (!isdigit((unsigned char)*s))
            return 0;
        s++;
    }
    return 1;
}

static void safe_copy(char *dst, size_t dst_sz, const char *src)
{
    if (!dst || dst_sz == 0)
        return;

    if (!src) {
        dst[0] = '\0';
        return;
    }

    strncpy(dst, src, dst_sz - 1);
    dst[dst_sz - 1] = '\0';
}

static int parse_conntrack_line(const char *line, struct conntrack_record *rec)
{
    char buf[CT_LINESZ];
    char *saveptr = NULL;
    char *tok;
    int tuple_index = 0;      /* 0 = orig, 1 = reply */
    int tuple_fields = 0;     /* count src/dst/sport/dport seen in current tuple */
    int saw_proto = 0;
    int saw_state = 0;

    if (!line || !rec)
        return -1;

    init_record(rec);

    safe_copy(buf, sizeof(buf), line);

    tok = strtok_r(buf, " \t\r\n", &saveptr);
    while (tok) {
        struct conntrack_tuple *cur =
            (tuple_index == 0) ? &rec->orig : &rec->reply;

        if (!saw_proto) {
            /*
             * First token is usually protocol, e.g. tcp / udp / icmp
             */
            safe_copy(rec->proto, sizeof(rec->proto), tok);
            saw_proto = 1;
            tok = strtok_r(NULL, " \t\r\n", &saveptr);
            continue;
        }

        if (!saw_state) {
            /*
             * Typical format starts:
             * tcp 6 431999 ESTABLISHED ...
             * udp 17 29 ...
             *
             * So skip numeric tokens until first non-numeric token
             * that is not key=value.
             */
            if (!strchr(tok, '=') && !is_number_string(tok)) {
                safe_copy(rec->state, sizeof(rec->state), tok);
                saw_state = 1;
                tok = strtok_r(NULL, " \t\r\n", &saveptr);
                continue;
            }
        }

        if (strncmp(tok, "src=", 4) == 0) {
            safe_copy(cur->src, sizeof(cur->src), tok + 4);
            tuple_fields++;
        } else if (strncmp(tok, "dst=", 4) == 0) {
            safe_copy(cur->dst, sizeof(cur->dst), tok + 4);
            tuple_fields++;
        } else if (strncmp(tok, "sport=", 6) == 0) {
            cur->sport = atoi(tok + 6);
            tuple_fields++;
        } else if (strncmp(tok, "dport=", 6) == 0) {
            cur->dport = atoi(tok + 6);
            tuple_fields++;

            /*
             * After a full src/dst/sport/dport set, move to reply tuple.
             */
            if (tuple_index == 0 && tuple_fields >= 4) {
                tuple_index = 1;
                tuple_fields = 0;
            }
        }

        tok = strtok_r(NULL, " \t\r\n", &saveptr);
    }

    /*
     * Require at least an original tuple to be present.
     */
    if (rec->orig.src[0] == '\0' ||
        rec->orig.dst[0] == '\0' ||
        rec->orig.sport < 0 ||
        rec->orig.dport < 0) {
        return 1;
    }

    return 0;
}


static int record_matches_client_nat(const struct conntrack_record *rec,
                                     const char *client_ip,
                                     const char *public_ip)
{
    if (!rec || !client_ip || !public_ip)
        return 0;

    //Traffic is from client and it's not to me (NOTE! Should check partner)
    if ((strcmp(rec->orig.src, client_ip) == 0) && (strcmp(rec->orig.src, public_ip) != 0))
        return 1;

    return 0;
}


static int record_matches_client_nat_260511(const struct conntrack_record *rec,
                                     const char *client_ip,
                                     const char *public_ip)
{
    if (!rec || !client_ip || !public_ip)
        return 0;

    if (strcmp(rec->orig.src, client_ip) != 0)
        return 0;

    /*
     * Confirm reply tuple points back to our public/NAT IP.
     */
    if (rec->reply.dst[0] == '\0')
        return 0;

    if (strcmp(rec->reply.dst, public_ip) != 0)
        return 0;

    /*
     * Optional consistency checks.
     */
    if (rec->reply.src[0] != '\0' &&
        strcmp(rec->reply.src, rec->orig.dst) != 0)
        return 0;

    if (rec->reply.sport >= 0 &&
        rec->reply.sport != rec->orig.dport)
        return 0;

    return 1;
}

static void print_record(const struct conntrack_record *rec)
{
    if (!rec)
        return;

    printf("proto=%s\n", rec->proto);
    printf("state=%s\n", rec->state[0] ? rec->state : "(none)");

    printf("orig:  src=%s dst=%s sport=%d dport=%d\n",
           rec->orig.src, rec->orig.dst,
           rec->orig.sport, rec->orig.dport);

    printf("reply: src=%s dst=%s sport=%d dport=%d\n",
           rec->reply.src, rec->reply.dst,
           rec->reply.sport, rec->reply.dport);
}


/*
static int record_matches_client_nat(const struct conntrack_record *rec,
                                     const char *client_ip,
                                     const char *public_ip)
{
    if (strcmp(rec->orig.src, client_ip) != 0)
        return 0;

    if (strcmp(rec->reply.dst, public_ip) != 0)
        return 0;

    if (strcmp(rec->reply.src, rec->orig.dst) != 0)
        return 0;

    if (rec->reply.sport != rec->orig.dport)
        return 0;

    return 1;
}*/

char *getIpStringToHex(char *lpBuff, char *lpIpString)
{
    struct in_addr addr;

    if (inet_pton(AF_INET, lpIpString, &addr) != 1) {
        fprintf(stderr, "Invalid IP: %s\n", lpIpString);
        strcpy(lpBuff, "ERROR");
        return lpBuff;
    }

    unsigned char *b = (unsigned char *)&addr.s_addr;
    sprintf(lpBuff, "%02X%02X%02X%02X", b[0], b[1], b[2], b[3]);
	return lpBuff;
}



int send_UDP_message(int sock, char *lpIP, unsigned int nPort, char *lpMessage);
int send_UDP_message(int sock, char *lpIP, unsigned int nPort, char *lpMessage)
{
    struct sockaddr_in addr;

    // 2. Set destination address
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons(5551);  // destination port
    inet_pton(AF_INET, lpIP, &addr.sin_addr);

    /*  Implement this if getting problem sending...
    printf("before sendto: sock=%d\n", sock);

    if (fcntl(sock, F_GETFD) == -1) {
        perror("fcntl F_GETFD");
        return 1;
    }*/

    // 3. Send message
    if (sendto(sock, lpMessage, strlen(lpMessage), 0,
               (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        perror("sendto");
        close(sock);
        return 1;
    }

    printf("UDP message sent\n");
    return 0;
}




struct _SeenPtNode;
struct _SeenPtNode {
	char szSendToIp[100];
	char szMyIp[100];			//May handle multiple IPs later
	unsigned int nPort;
	struct _SeenPtNode *pNext;
};

void *worker(void *arg) {

	MYSQL *conn, *updateConn;
	MYSQL_RES *res;
	MYSQL_ROW row;
	struct _SeenPtNode	*pSeenPointerChain = NULL;

	struct _InfectionSpecification  *pInfection = (struct _InfectionSpecification *) arg;	

	char cInfectedIpAddr[INET_ADDRSTRLEN];

	struct in_addr addr;
    addr.s_addr = htonl(pInfection->ipAddress);   // 192.168.1.1

    if (inet_ntop(AF_INET, &addr, cInfectedIpAddr, sizeof(cInfectedIpAddr)) == NULL) {
        perror("inet_ntop");
        return NULL;
    }

    printf("\nThreat info changed (informing active connections) about %s - (infId: %u, severity: %u, botnet: %u) json: %s\n\n", cInfectedIpAddr, pInfection->nInfectionId, pInfection->nSeverity, pInfection->nBotnetId, pInfection->lpInfo);

	//*** Read internal- and external IP Address from setup */
	char *lpSQL = "select adminIp, internalIP, inet_ntoa(adminIp), inet_ntoa(internalIP), nettmask from setup";
	printf("About to read setup\n");
	conn = getConnection();
	printf("Connection opened\n");
		
	if (mysql_query(conn, lpSQL)) {
		fprintf(stderr, "taralink: %s\n", mysql_error(conn));
			reportErrorReadin("setup");
        return NULL;
	}
	printf("Query executed\n");

	res = mysql_use_result(conn);
	printf("mysql_use_result called\n");
		
	if ((row = mysql_fetch_row(res)) == NULL)
	{
		printf("*********** ERROR ********** Reading IP addresses from setup");
        return NULL;
	}
	
	printf("Setup row fetched\n");

	char cInternalIp[100];
	char cExternalIp[100];
	strcpy(cInternalIp, row[3]);
	strcpy(cExternalIp, row[2]);
    u_int32_t nNettmask = (row[4]?atoi(row[4]):0);
    u_int32_t nAdminIp = (row[0]?atoi(row[0]):0);
    u_int32_t nInternalIp = (row[1]?atoi(row[1]):0);


    char bMeOrMine = (nAdminIp & nNettmask) == (addr.s_addr & nNettmask);

	printf("InternalIP: %s, external IP: %s\n", cInternalIp, cExternalIp);

	mysql_free_result(res);

    int sock;

    // 1. Create socket
    sock = socket(AF_INET, SOCK_DGRAM, 0);
    if (sock < 0) {
        perror("socket");
        return NULL;
    }

	//conntrack -L | grep 'src=192.168.50.104 '
	FILE *fp = popen("conntrack -L", "r");

	if (!fp) {
		perror("popen");
		return NULL;
    }

    char line[512];
    int nActiveConnectionsFound = 0;

    while (fgets(line, sizeof(line), fp)) {
		printf("Handling: %s\n", line);

/*  	const char *line =
        	"tcp 6 431999 ESTABLISHED "
        	"src=192.168.50.104 dst=1.2.3.4 sport=54321 dport=443 "
        	"src=1.2.3.4 dst=192.168.50.104 sport=443 dport=54321 [ASSURED] mark=0 use=1";

		char src[64], dst[64], sport[32], dport[32];

    	int rc = extract_conntrack_tuple(line, src, sizeof(src),
                                     dst, sizeof(dst),
                                     sport, sizeof(sport),
                                     dport, sizeof(dport));
    	if (rc == 0) {
        	printf("src=%s\n", src);
        	printf("dst=%s\n", dst);
        	printf("sport=%s\n", sport);
        	printf("dport=%s\n", dport);

			if (!strcmp(src, cInternalIp))

    	} else {
        	printf("No match\n");
    	}
			*/


/*    const char *line =
        "tcp 6 431999 ESTABLISHED "
        "src=192.168.50.104 dst=8.8.8.8 sport=54321 dport=443 "
        "src=8.8.8.8 dst=203.0.113.5 sport=443 dport=54321 "
        "[ASSURED] mark=0 use=1";
*/
	    struct conntrack_record rec;
    	int rc;

        printf("Calling parse_conntrack_line()\n");
	    rc = parse_conntrack_line(line, &rec);
    	if (rc != 0) {
        	printf("******** parse failed ** rc=%d: %s\n", rc, line);
	        //printf("Line read: %s", line);
		}
		else
        {
            printf("Calling record_matches_client_nat()\n");
		    if (record_matches_client_nat(&rec, cInfectedIpAddr, cExternalIp))	//cInternalIp - is the gateway...
			{
				if (!strcmp(rec.proto, "tcp"))
				{
                    nActiveConnectionsFound++;
				    //print_record(&rec);

					char *lpSendToIp = rec.orig.dst;
					//char *lpFromIp = cExternalIp; //NOTE! This is connect if NAT
					char *lpFromIp = cInfectedIpAddr;//NOTE! Correct if not NAT

                    if (!strcmp(lpSendToIp, cInternalIp) || !strcmp(lpSendToIp, cExternalIp))
                    {
                        printf("****** Preventing sending to myself: %s (external: %s, internal: %s)\n", lpSendToIp, cInternalIp, cExternalIp);
                    }
                    else
                    {
                        printf("Checking if port should be set to 0\n");
    					unsigned int sport = rec.orig.sport;        //How sure are we that this is the right port number? (probably doesn't matter until NAT)

                        if (bMeOrMine && (nAdminIp != nInternalIp))
                        {
                            printf("No NAT for %u. Setting port to 0 - indicating that is applies to all ports on this IP\n", addr.s_addr);
                            sport = 0;
                        }

	    				printf("Send to (if partner and only once for ip/port match...) %s: %s:%d is infected... info: %s\n", lpSendToIp, lpFromIp, sport, pInfection->lpInfo);	
				
		    			struct _SeenPtNode	*pFound;
			    		for (pFound = pSeenPointerChain; pFound; pFound = pFound->pNext)
				    	{
					    	bool bFoundNow = (!strcmp(pFound->szSendToIp, lpSendToIp) && pFound->nPort == sport);
						    printf("%s Comparing %s & %s --and -- %d & %d\n", (!bFoundNow?"not match":"**MATCHING**"), pFound->szSendToIp, lpSendToIp, pFound->nPort, sport);
    						if (bFoundNow)
	    						break;
		    			}

			    		if (pFound)
				    		printf("Already in the list: %s - %s:%d\n", lpSendToIp, lpFromIp, sport);
					    else
    					{
                            printf("Making new node\n");
	    					struct _SeenPtNode	*pNew = malloc(sizeof(struct _SeenPtNode));
		    				strcpy(pNew->szSendToIp, lpSendToIp);
			    			strcpy(pNew->szMyIp, lpFromIp);	
				    		pNew->nPort = sport;
					    	pNew->pNext = pSeenPointerChain;
						    pSeenPointerChain = pNew;
    						printf("New element put in list (NOTE! ASSUMING NOT NAT): %s - %s:%d\n", lpSendToIp, lpFromIp, sport);  //**** IF NAT: Check char *lpFromIp above....
	    				}
		    		}
                }
				else
					printf("\nSkipping protocol: %s\n\n", rec.proto);
			}
           // else
           //     printf("Other unit: %s\n", line);
        }
	}

    if (!nActiveConnectionsFound)
        printf("No active connections found for this unit.\n");

    pclose(fp);

    printf("Finished scanning conntrack\n");

	MYSQL_STMT *stmt = NULL;
	MYSQL_BIND param[1];
	MYSQL_BIND result[1];
	unsigned long ip_len;
	int nRouterId;

	stmt = mysql_stmt_init(conn);
	if (!stmt) {
   		printf("mysql_stmt_init failed\n");
   		return NULL;
	}

	const char *lpSql =
    		"SELECT R.routerId "
    		"FROM partnerRouter R "
    		"JOIN partner P ON P.partnerId = R.partnerId "
    		"WHERE R.ip = INET_ATON(?)";

	if (mysql_stmt_prepare(stmt, lpSql, strlen(lpSql)) != 0) {
   		printf("prepare failed: %s\n", mysql_stmt_error(stmt));
   		mysql_stmt_close(stmt);
   		return NULL;
	}

	memset(param, 0, sizeof(param));

	char cSearchPartnerIpAddress[100];

	param[0].buffer_type   = MYSQL_TYPE_STRING;
	param[0].buffer        = cSearchPartnerIpAddress;
	param[0].buffer_length = sizeof(cSearchPartnerIpAddress);
	param[0].length        = &ip_len;

	if (mysql_stmt_bind_param(stmt, param) != 0) {
   		printf("bind_param failed: %s\n", mysql_stmt_error(stmt));
   		mysql_stmt_close(stmt);
   		return NULL;
	}

    printf("About to send\n");


	//Send the messages...
	struct _SeenPtNode	*pFound;
	struct _SeenPtNode	*pNext = NULL;
	for (pFound = pSeenPointerChain; pFound; pFound = pNext)
	{
/*
		MYSQL_STMT *stmt;
		MYSQL_BIND param[1], result[1];

		stmt = mysql_stmt_init(conn);

		char *lpSql = "SELECT routerId from partnerRouter R join partner P on P.partnerId = R.partnerId where ip = inet_aton(?)";
		mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));

		memset(param, 0, sizeof(param));

		param[0].buffer_type = MYSQL_TYPE_STRING;
		param[0].buffer = pFound->szSendToIp;

		mysql_stmt_bind_param(stmt, param);
		mysql_stmt_execute(stmt);

		memset(result, 0, sizeof(result));

		int nRouterId;
		result[0].buffer_type = MYSQL_TYPE_LONG;
		result[0].buffer = &nRouterId;

		mysql_stmt_bind_result(stmt, result);
		bool bFound = false;

		while (mysql_stmt_fetch(stmt) == 0) {
				//If string:
    			//ip[ip_len] = '\0';

				printf("RouterId: %d\n", nRouterId);
				bFound = true;
		}
				*/

		strcpy(cSearchPartnerIpAddress, pFound->szSendToIp);
		ip_len = strlen(pFound->szSendToIp);


		if (mysql_stmt_execute(stmt) != 0) {
    		printf("execute failed: %s\n", mysql_stmt_error(stmt));
    		mysql_stmt_close(stmt);
    		return NULL;
		}

		memset(result, 0, sizeof(result));

		result[0].buffer_type = MYSQL_TYPE_LONG;
		result[0].buffer      = &nRouterId;

		if (mysql_stmt_bind_result(stmt, result) != 0) {
    		printf("bind_result failed: %s\n", mysql_stmt_error(stmt));
    		mysql_stmt_close(stmt);
    		return NULL;
		}

		bool bFound = false;

		while (1) {
    		int rc = mysql_stmt_fetch(stmt);

		    if (rc == 0) {
        		printf("RouterId: %d\n", nRouterId);
		        bFound = true;
    		} else if (rc == MYSQL_NO_DATA) {
        		break;
    		} else {
        		printf("fetch failed: %s\n", mysql_stmt_error(stmt));
        		break;
    		}
		}

		printf("Router %s is%s a partner.\n",		
       		pFound->szSendToIp,
       		bFound ? "" : " NOT");

		/*printf("Might send to: %s - %s:%d %s\n",
		    pFound->szSendToIp,
		    pFound->szMyIp,
       		pFound->nPort,
       		bFound ? "- and would if could 'coz it's a partner"
            	  : "- but it's NOT a partner");
				  */

		if (bFound) 
        {
            //************************ WARNING **************************** */
            //Exchanging threat info happens several places (here when threat info is changed - send info to open connections). Search for THREAT_INFO_EXCHANGE
            //Here is about sending updated threat info to open connections when it's changed at source (e.g notified by DBserver)
	        char cHexIp[20];
			getIpStringToHex(cHexIp, pFound->szMyIp);
			char cMessage[400];
			//sprintf(cMessage, "%s %s:%u^%u^%u^%u asdfasdf is infected (message from owner): ?????", INFECTION_CHANGED_PREFIX, pFound->szMyIp, pFound->nPort);
            sprintf(cMessage, "%s %s:%u^%u^%u^%u^%u^%u^%u^%s", 
					INFECTION_CHANGED_PREFIX, 
					cHexIp, 
					pFound->nPort, 
					1,  //version number
					1, //presumed_infected
					0,  //owners_id
					pInfection->nInfectionId, //owners_id
					pInfection->nSeverity, 
					pInfection->nBotnetId, 
					(pInfection->lpInfo?pInfection->lpInfo:"NULL"));
            printf("Trying to send to %s:%d - %s\n (severiry: %u)", pFound->szSendToIp, TARALINK_LISTENING_TO_PORT, cMessage, pInfection->nSeverity);
       		
			send_UDP_message(sock, pFound->szSendToIp, TARALINK_LISTENING_TO_PORT, cMessage);
		}

		pNext = pFound->pNext;
		free(pFound);
	}

    printf("Cleaning up\n");


	//Clean up
	free(pInfection->lpInfo);
	free(pInfection);
	
	mysql_stmt_close(stmt);
	mysql_close(conn);
    close(sock);


    return NULL;
}

void init_background_infecton_change_partner_notification(unsigned int ip, unsigned int nett, char *lpActive, unsigned int nStatus, unsigned int nInfectionId, unsigned int nSeverity, unsigned int nBotnetId, char *lpInfo)
{
    printf("\n\n*********** ABOUT TO SCHEDULING NEW THREAD ******************\n\n");
	struct _InfectionSpecification  *pInfection = malloc(sizeof(struct _InfectionSpecification));
	memset(pInfection, sizeof(struct _InfectionSpecification), 0);
	pInfection->ipAddress = ip;
	pInfection->ipNettmask = nett;
	pInfection->lpInfo = malloc(strlen(lpInfo)+1);
	strcpy(pInfection->lpInfo, lpInfo);

    pInfection->nBotnetId = nBotnetId;
    pInfection->nSeverity = nSeverity;
    pInfection->nInfectionId = nInfectionId;

	pthread_t t;
    pthread_create(&t, NULL, worker, pInfection);
    pthread_join(t, NULL);

    printf("\n\n*********** SCHEDULING NEW THREAD ******************\n\n");

}
