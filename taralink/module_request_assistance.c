//module_request_assistance.c

#include <curl/curl.h>
#include <ctype.h>

/*  This function is called by the timer function (module_time.c), scans the table assistanceRequest and sends
    request to central database with request when the server load is too high.
    Check the misc/checkload.pl script for putting the request record in the database */

size_t wget_write_callback(char *ptr, size_t size, size_t nmemb,
                      void *userdata)
{
        size_t bytes = size * nmemb;

        //Copy to temporary buffer because the data is not null terminated.
        char *lpBuff = malloc(bytes + 1);
        if (!lpBuff)
              return 0;

        memcpy(lpBuff, ptr, bytes);
        lpBuff[bytes] = 0;

        printf("Webpage downloaded successfully: %s\n", lpBuff);
        free(lpBuff);
        return bytes;  //libcurl expects the total number of bytes consumed.
}

char *wget(char *lpUrl, char *szBuff, int nBuffSize)
{
        curl_global_init(CURL_GLOBAL_ALL);

        CURL *myHandle;
        CURLcode setop_result;

        if((myHandle = curl_easy_init()) == NULL)
        {
                perror("****** Error curl_easy_init() - ABORTING\n");
                addWarningRecord("***** ERROR in wget - curl_easy_init(). Aborting");
                return "";
        }

        if((setop_result = curl_easy_setopt(myHandle, CURLOPT_URL, lpUrl)) != CURLE_OK)
        {
                perror("****** Error curl_easy_setopt() - ABORTING\n");
                addWarningRecord("***** ERROR in wget - curl_easy_setopt(). Aborting");
                curl_easy_cleanup(myHandle);
                return "";
        }

        if((setop_result = curl_easy_setopt(myHandle, CURLOPT_WRITEFUNCTION, wget_write_callback)) != CURLE_OK)
        {
                perror("***** Error curl_easy_setopt CURLOPT_WRITEFUNCTION - ABORTING\n");
                addWarningRecord("**** ERROR in wget CURLOPT_WRITEFUNCTION");
                curl_easy_cleanup(myHandle);
                return "";
        }

        if((setop_result = curl_easy_perform(myHandle)) != CURLE_OK)
        {
                char cMsg[256];
                snprintf(cMsg, sizeof(cMsg),
                        "**** Error **** curl_easy_perform (code %d) (still trying to resume)\n",
                        setop_result);
                perror(cMsg);
                printf("\n-%s-\n", lpUrl);
        }

        curl_easy_cleanup(myHandle);
        return 0;
}

/* Encode a query-string component.  Assistance categories are normally simple words,
   but they come from the database and must not be able to inject '&', '=' or other
   query syntax into a control request. */
static int urlEncodeComponent(const char *src, char *dst, size_t dstSize)
{
        static const char hex[] = "0123456789ABCDEF";
        size_t out = 0;

        if (!src || !dst || dstSize == 0)
                return 0;

        while (*src)
        {
                unsigned char c = (unsigned char)*src++;
                int safe = isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~';

                if (safe)
                {
                        if (out + 1 >= dstSize)
                                return 0;
                        dst[out++] = (char)c;
                }
                else
                {
                        if (out + 3 >= dstSize)
                                return 0;
                        dst[out++] = '%';
                        dst[out++] = hex[(c >> 4) & 0x0F];
                        dst[out++] = hex[c & 0x0F];
                }
        }

        dst[out] = 0;
        return 1;
}

void checkRequestAssistance()
{
        /* Handling requests for assistance on tackling brute force/DOS attack. This table serves 3 purposes:
        - 1: Request from a unit in our network for assistance... For now this means this router/IP address.
        - 2: ABMonitor will send such request from us to global DB Servers as listed in setup table.
        - 3: Global DB server(s) forwards this message to all partners.
        - 4: ABMonitor sends information from the table to taransvar kernel module (tarakernel) that will block presumed infected traffic to routers who has requested such blocking.

        purpose values:
        - internalRequest
        - forDistribution
        - fromPartner
        */

        MYSQL *conn, *setupConn;
        MYSQL_RES *res, *setupRes;
        MYSQL_ROW row, setupRow;

        conn = getConnection();
        setupConn = NULL;
        setupRes = NULL;
        setupRow = NULL;

        char *szSQL = "select hex(ip) as ip, port, category, comment, coalesce(requestQuality,0) as requestQuality, wantSpoofed, requestId, senderIp, hex(senderIp) as senderIpHex, purpose from assistanceRequest where sentPartners = b'0'";

        if (mysql_query(conn, szSQL))
        {
                fprintf(stderr, "%s\n", mysql_error(conn));
                printf("Exiting (mysql_query error)...\n");
                addWarningRecord("***** ERROR ***** selecting requests for assistance..");
                mysql_close(conn);
                return;
        }

        res = mysql_use_result(conn);
        if (!res)
        {
                addWarningRecord("***** ERROR ***** reading requests for assistance..");
                mysql_close(conn);
                return;
        }

        while ((row = mysql_fetch_row(res)) != NULL)
        {
                char *lpRequestId = row[6];
                char *lpPurpose = row[9];
                int bHandled = 0;

                if (!lpRequestId)
                {
                        addWarningRecord("***** ERROR ***** assistanceRequest without requestId");
                        continue;
                }

                if (lpPurpose && !strcmp(lpPurpose, "internalRequest"))
                {
                        printf("Handling internal request.. %s\n", lpRequestId);

                        if (!setupConn)
                        {
                                setupConn = getConnection();

                                if (mysql_query(setupConn, "select inet_ntoa(globalDb1ip) as ip1, inet_ntoa(globalDb2ip) as ip2, inet_ntoa(globalDb3ip) as ip3, inet_ntoa(adminIP) as adminIP from setup"))
                                {
                                        fprintf(stderr, "%s\n", mysql_error(setupConn));
                                        addWarningRecord("***** ERROR ***** selecting internal requests for assistance..");
                                        break;
                                }

                                setupRes = mysql_use_result(setupConn);
                                if (!setupRes)
                                {
                                        addWarningRecord("***** ERROR ***** reading setup..");
                                        break;
                                }

                                setupRow = mysql_fetch_row(setupRes);
                                if (!setupRow)
                                {
                                        addWarningRecord("***** ERROR ***** reading setup row..");
                                        break;
                                }
                        }

                        /* A request is considered queued only if it was queued for every configured
                           global DB server. Previously bHandled was overwritten on every iteration,
                           so the result for only the last server decided the state of the whole request. */
                        bHandled = 1;
                        int destinations = 0;

                        for (int n = 0; n < 3; n++)
                        {
                                if (setupRow[n] != NULL && setupRow[n][0] != 0)
                                {
                                        destinations++;

                                        char szUrl[512];
                                        char encodedCategory[256];
                                        char *lpMyIp = (row[0] ? row[0] : setupRow[3]);
                                        char *lpCategory = (row[2] ? row[2] : "other");
                                        int nPort = (row[1] ? atoi(row[1]) : 0);
                                        short nWantSpoofed = (row[5] ? atoi(row[5]) : 0);

                                        if (!lpMyIp || !urlEncodeComponent(lpCategory, encodedCategory, sizeof(encodedCategory)))
                                        {
                                                addWarningRecord("***** ERROR ***** invalid Request for Assistance data");
                                                bHandled = 0;
                                                continue;
                                        }

                                        int written = snprintf(szUrl, sizeof(szUrl),
                                                "http://%s/script/requestAssistance.php?f=request&ip=%s&port=%d&cat=%s&qual=%s&sp=%d",
                                                setupRow[n], lpMyIp, nPort, encodedCategory,
                                                (row[4] ? row[4] : "0"), nWantSpoofed);

                                        if (written < 0 || (size_t)written >= sizeof(szUrl))
                                        {
                                                addWarningRecord("***** ERROR ***** Request for Assistance URL too long");
                                                bHandled = 0;
                                                continue;
                                        }

                                        printf("Placing request for assistance in queue (pendingWget): %s\n", szUrl);

                                        if (!addPendingWgetOk(e_wget_assistanceRequest, szUrl, atoi(lpRequestId)))
                                                bHandled = 0;
                                }
                                else
                                {
                                        printf("Global server %d not specified. Skipping.\n", n + 1);
                                }
                        }

                        if (destinations == 0)
                        {
                                addWarningRecord("***** ERROR ***** no global DB server configured for Request for Assistance");
                                bHandled = 0;
                        }
                }
                else if (lpPurpose && !strcmp(lpPurpose, "forDistribution"))
                {
                        MYSQL *partnerConn;
                        MYSQL_RES *partnerRes;
                        MYSQL_ROW partnerRow;

                        printf("Unhandled assistanceRequest for distribution found\n");
                        partnerConn = getConnection();

                        if (mysql_query(partnerConn, "select inet_ntoa(ip) as ip from partnerRouter"))
                        {
                                fprintf(stderr, "%s\n", mysql_error(partnerConn));
                                addWarningRecord("***** ERROR ***** fetching partners for assistance distribution");
                                mysql_close(partnerConn);
                                continue;
                        }

                        partnerRes = mysql_use_result(partnerConn);
                        if (!partnerRes)
                        {
                                addWarningRecord("***** ERROR ***** reading partners for assistance distribution");
                                mysql_close(partnerConn);
                                continue;
                        }

                        bHandled = 1;
                        int destinations = 0;

                        while ((partnerRow = mysql_fetch_row(partnerRes)) != NULL)
                        {
                                if (!partnerRow[0] || partnerRow[0][0] == 0)
                                        continue;

                                destinations++;

                                char cUrl[512];
                                char encodedCategory[256];
                                char *lpRequesterIp = (row[0] ? row[0] : row[8]);
                                char *lpCategory = (row[2] ? row[2] : "other");
                                int nPort = (row[1] ? atoi(row[1]) : 0);
                                short nQuality = (row[4] ? atoi(row[4]) : 0);
                                short nWantSpoofed = (row[5] ? atoi(row[5]) : 0);

                                if (!lpRequesterIp || !urlEncodeComponent(lpCategory, encodedCategory, sizeof(encodedCategory)))
                                {
                                        addWarningRecord("***** ERROR ***** invalid distributed assistance data");
                                        bHandled = 0;
                                        continue;
                                }

                                int written = snprintf(cUrl, sizeof(cUrl),
                                        "http://%s/script/partnerRequest.php?f=assistance&ip=%s&port=%d&cat=%s&qual=%d&sp=%d",
                                        partnerRow[0], lpRequesterIp, nPort, encodedCategory,
                                        nQuality, nWantSpoofed);

                                if (written < 0 || (size_t)written >= sizeof(cUrl))
                                {
                                        addWarningRecord("***** ERROR ***** distributed assistance URL too long");
                                        bHandled = 0;
                                        continue;
                                }

                                printf("Adding to pendingWget: %s\n", cUrl);

                                if (!addPendingWgetOk(e_wget_assistanceRequest, cUrl, atoi(lpRequestId)))
                                        bHandled = 0;
                        }

                        if (destinations == 0)
                        {
                                addWarningRecord("***** ERROR ***** no partner routers available for assistance distribution");
                                bHandled = 0;
                        }

                        mysql_free_result(partnerRes);
                        mysql_close(partnerConn);
                }
                else if (!lpPurpose)
                {
                        printf("************* ERROR - assistanceRequest.purpose was NULL... Not supposed to happen.\n");
                        bHandled = 1; //Do not let corrupt records accumulate forever.
                }
                else if (strcmp(lpPurpose, "fromPartner"))
                {
                        printf("************* ERROR - unknown assistanceRequest.purpose: %s... Not supposed to happen.\n", lpPurpose);
                        bHandled = 1;
                }

                if (bHandled)
                {
                        MYSQL *handleConn = getConnection();
                        char cSQL[300];
                        unsigned long requestId = strtoul(lpRequestId, NULL, 10);

                        int written = snprintf(cSQL, sizeof(cSQL),
                                "update assistanceRequest set sentPartners = b'1', senttime = now() where requestId = %lu",
                                requestId);

                        if (written < 0 || (size_t)written >= sizeof(cSQL) || mysql_query(handleConn, cSQL))
                        {
                                fprintf(stderr, "%s\n", mysql_error(handleConn));
                                addWarningRecord("*********** ERROR *********** Taralink couldn't update assistanceRequest sent fields.");
                        }

                        mysql_close(handleConn);
                }
                else
                {
                        addWarningRecord("***** ERROR ***** Request for Assistance not fully queued; leaving it unsent for retry");
                }
        }

        mysql_free_result(res);
        mysql_close(conn);

        if (setupConn)
        {
                if (setupRes)
                        mysql_free_result(setupRes);
                mysql_close(setupConn);
        }
}
