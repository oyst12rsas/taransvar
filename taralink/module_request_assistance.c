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
        char *lpBuff = malloc(bytes + 1);
        if (!lpBuff)
              return 0;
        memcpy(lpBuff, ptr, bytes);
        lpBuff[bytes] = 0;
        printf("Webpage downloaded successfully: %s\n", lpBuff);
        free(lpBuff);
        return bytes;
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
                snprintf(cMsg, sizeof(cMsg), "**** Error **** curl_easy_perform (code %d) (still trying to resume)\n", setop_result);
                perror(cMsg);
                printf("\n-%s-\n", lpUrl);
        }
        curl_easy_cleanup(myHandle);
        return 0;
}

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
                        if (out + 1 >= dstSize) return 0;
                        dst[out++] = (char)c;
                }
                else
                {
                        if (out + 3 >= dstSize) return 0;
                        dst[out++] = '%';
                        dst[out++] = hex[(c >> 4) & 0x0F];
                        dst[out++] = hex[c & 0x0F];
                }
        }
        dst[out] = 0;
        return 1;
}

/*
 * The minute-oriented pendingWget worker can delay a Demo 3 release after the
 * DB timer has fired. Deliver to gateways currently participating in this
 * session immediately; keep the queued request as a retry if delivery fails.
 * partnerRequest.php authenticates the DB peer and is idempotent by rid.
 */
struct demo3FastReply {
        char body[16];
        size_t used;
};

static size_t demo3FastReplyWrite(char *data, size_t size, size_t count, void *context)
{
        struct demo3FastReply *reply = context;
        size_t bytes = size * count;
        size_t copy = bytes;
        if (copy > sizeof(reply->body) - reply->used - 1)
                copy = sizeof(reply->body) - reply->used - 1;
        memcpy(reply->body + reply->used, data, copy);
        reply->used += copy;
        reply->body[reply->used] = 0;
        return bytes;
}

static int deliverDemo3ReleaseNow(MYSQL *conn, const char *url,
                                   const char *partnerIp, unsigned long requestId)
{
        CURL *curl = curl_easy_init();
        CURLcode result;
        long status = 0;
        struct demo3FastReply reply = {{0}, 0};
        MYSQL_STMT *stmt;
        MYSQL_BIND bind[2];
        unsigned long long requestIdArg = requestId;
        unsigned long urlLength = strlen(url);

        if (!curl)
                return 0;
        curl_easy_setopt(curl, CURLOPT_URL, url);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT_MS, 500L);
        curl_easy_setopt(curl, CURLOPT_TIMEOUT_MS, 1500L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
        curl_easy_setopt(curl, CURLOPT_PROXY, "");
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, demo3FastReplyWrite);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, &reply);
        result = curl_easy_perform(curl);
        if (result == CURLE_OK)
                curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &status);
        curl_easy_cleanup(curl);

        if (result != CURLE_OK || status != 200 || strcmp(reply.body, "ok") != 0)
        {
                fprintf(stderr, "Demo 3 immediate release %lu to %s failed (curl=%d HTTP=%ld); queued retry retained\n",
                        requestId, partnerIp, (int)result, status);
                return 0;
        }

        /* The old worker need not send this successful delivery again. If this
         * update fails, its queued duplicate is harmless and remains retryable. */
        stmt = mysql_stmt_init(conn);
        if (stmt)
        {
                const char *sql =
                        "UPDATE pendingWget SET handled=UTC_TIMESTAMP(),"
                        "reply='ok (immediate Demo 3 release)' "
                        "WHERE regardingId=? AND url=? AND handled IS NULL";
                memset(bind, 0, sizeof(bind));
                bind[0].buffer_type = MYSQL_TYPE_LONGLONG;
                bind[0].buffer = &requestIdArg;
                bind[0].is_unsigned = 1;
                bind[1].buffer_type = MYSQL_TYPE_STRING;
                bind[1].buffer = (void *)url;
                bind[1].buffer_length = urlLength;
                bind[1].length = &urlLength;
                if (mysql_stmt_prepare(stmt, sql, strlen(sql)) ||
                    mysql_stmt_bind_param(stmt, bind) ||
                    mysql_stmt_execute(stmt))
                        fprintf(stderr, "Demo 3 immediate release %lu reached %s, but queue acknowledgement failed: %s\n",
                                requestId, partnerIp, mysql_stmt_error(stmt));
                mysql_stmt_close(stmt);
        }
        printf("Demo 3 immediate release %lu delivered to %s\n", requestId, partnerIp);
        return 1;
}

void checkRequestAssistance()
{
        MYSQL *conn, *setupConn;
        MYSQL_RES *res, *setupRes;
        MYSQL_ROW row, setupRow;

        conn = getConnection();
        setupConn = NULL;
        setupRes = NULL;
        setupRow = NULL;

        /* fromPartner rows have already completed distribution and must never
         * occupy this outbound work scan. Bound each timer pass as well, so a
         * historical assistance backlog cannot starve hack-report delivery. */
        char *szSQL = "select hex(ip) as ip, port, category, comment, coalesce(requestQuality,0) as requestQuality, wantSpoofed, requestId, senderIp, hex(senderIp) as senderIpHex, purpose, CAST(active AS UNSIGNED) as active, CAST(isDemo AS UNSIGNED) as isDemo from assistanceRequest where sentPartners = b'0' and (purpose is null or purpose <> 'fromPartner') order by requestId limit 100";

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
                int nActive = row[10] ? atoi(row[10]) : 1;
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
                                                "http://%s/script/requestAssistance.php?f=request&ip=%s&port=%d&cat=%s&qual=%s&sp=%d&active=%d",
                                                setupRow[n], lpMyIp, nPort, encodedCategory,
                                                (row[4] ? row[4] : "0"), nWantSpoofed, nActive);
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
                        MYSQL *fastConn = NULL;
                        MYSQL_RES *partnerRes;
                        MYSQL_RES *participants = NULL;
                        MYSQL_ROW partnerRow;
                        unsigned long demoSid = 0;
                        char trailing;
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

                        /* Only an inactive demo-owned release may use the fast
                         * path. A participant's observed address must also be
                         * an explicitly registered partner destination below. */
                        if (row[11] && atoi(row[11]) == 1 && !nActive &&
                            row[2] && sscanf(row[2], "demo3_%lu%c", &demoSid, &trailing) == 1 &&
                            demoSid > 0)
                        {
                                char participantsSql[256];
                                fastConn = getConnection();
                                if (fastConn)
                                {
                                        snprintf(participantsSql, sizeof(participantsSql),
                                                "SELECT DISTINCT observedIp FROM demoAssistanceParticipant "
                                                "WHERE sessionId=%lu", demoSid);
                                        if (mysql_query(fastConn, participantsSql) == 0)
                                                participants = mysql_store_result(fastConn);
                                        if (!participants)
                                                fprintf(stderr, "Demo 3 release %s: participant lookup failed; queued delivery retained\n",
                                                        lpRequestId);
                                }
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
                                        "http://%s/script/partnerRequest.php?f=assistance&ip=%s&port=%d&cat=%s&qual=%d&sp=%d&active=%d&rid=%s",
                                        partnerRow[0], lpRequesterIp, nPort, encodedCategory,
                                        nQuality, nWantSpoofed, nActive, lpRequestId);
                                if (written < 0 || (size_t)written >= sizeof(cUrl))
                                {
                                        addWarningRecord("***** ERROR ***** distributed assistance URL too long");
                                        bHandled = 0;
                                        continue;
                                }
                                printf("Adding to pendingWget: %s\n", cUrl);
                                if (!addPendingWgetOk(e_wget_assistanceRequest, cUrl, atoi(lpRequestId)))
                                        bHandled = 0;
                                else if (participants && fastConn)
                                {
                                        MYSQL_ROW participant;
                                        mysql_data_seek(participants, 0);
                                        while ((participant = mysql_fetch_row(participants)) != NULL)
                                        {
                                                if (participant[0] && !strcmp(participant[0], partnerRow[0]))
                                                {
                                                        deliverDemo3ReleaseNow(fastConn, cUrl,
                                                                               partnerRow[0],
                                                                               strtoul(lpRequestId, NULL, 10));
                                                        break;
                                                }
                                        }
                                }
                        }

                        if (destinations == 0)
                        {
                                addWarningRecord("***** ERROR ***** no partner routers available for assistance distribution");
                                bHandled = 0;
                        }
                        mysql_free_result(partnerRes);
                        mysql_close(partnerConn);
                        if (participants)
                                mysql_free_result(participants);
                        if (fastConn)
                                mysql_close(fastConn);
                }
                else if (!lpPurpose)
                {
                        printf("************* ERROR - assistanceRequest.purpose was NULL... Not supposed to happen.\n");
                        bHandled = 1;
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
                if (setupRes) mysql_free_result(setupRes);
                mysql_close(setupConn);
        }
}
