//module_traffic_report.c
#include <inttypes.h>

static int test_stmt_error(MYSQL_STMT *stmt, int status)
{
    if (status) {
        char cBuf[200];
        sprintf(cBuf, "***** Error: %s (errno: %d)", mysql_stmt_error(stmt), mysql_stmt_errno(stmt));
        fprintf(stderr, "%s\n", cBuf);
        addWarningRecord(cBuf);
    }
    return status;
}

char *bufferToHex(char *lpBuffer, int len, char *lpTarget, int nBufSize)
{
    int i;
    if (len * 3 >= nBufSize)
        len = nBufSize / 3 - 1;

    for (i = 0; i < len; i++)
        sprintf(lpTarget + i * 3, "%02X ", lpBuffer[i]);

    lpTarget[i * 3] = 0;
    return lpTarget;
}

void checkUpdateHackReport(MYSQL *conn, char *lpIpHex, char *lpPortHex, char *lpTagHex)
{
    int status;
    MYSQL_BIND queryParams[2];

    MYSQL_STMT *stmt = mysql_stmt_init(conn);
    if (stmt == NULL) {
        printf("************ ERROR ********** Could not initialize statement\n");
        return;
    }

    char *lpSql = "select reportId, severity from hackReport where ip = CONV(?,16,10) and port = CONV(?,16,10) order by coalesce(lastSeen, created) desc limit 1";

    status = mysql_stmt_prepare(stmt, lpSql, strlen(lpSql));
    if (test_stmt_error(stmt, status)) {
        mysql_stmt_close(stmt);
        return;
    }

    memset(queryParams, 0, sizeof(queryParams));
    unsigned long nIpLen = strlen(lpIpHex);
    unsigned long nPortLen = strlen(lpPortHex);

    queryParams[0].buffer_type = MYSQL_TYPE_VAR_STRING;
    queryParams[0].buffer_length = 100;
    queryParams[0].is_unsigned = 1;
    queryParams[0].is_null = 0;
    queryParams[0].buffer = lpIpHex;
    queryParams[0].length = &nIpLen;

    queryParams[1].buffer_type = MYSQL_TYPE_VAR_STRING;
    queryParams[1].buffer_length = 100;
    queryParams[1].is_unsigned = 1;
    queryParams[1].is_null = 0;
    queryParams[1].buffer = lpPortHex;
    queryParams[1].length = &nPortLen;

    if (test_stmt_error(stmt, mysql_stmt_bind_param(stmt, queryParams)) ||
        test_stmt_error(stmt, mysql_stmt_execute(stmt))) {
        mysql_stmt_close(stmt);
        return;
    }

    MYSQL_BIND rec[2];
    memset(rec, 0, sizeof(rec));

    unsigned int nReportId = 0;
    int severity = 0;

    rec[0].buffer_type = MYSQL_TYPE_LONG;
    rec[0].buffer = (char *)&nReportId;
    rec[0].buffer_length = sizeof(unsigned int);
    rec[0].is_unsigned = 1;

    rec[1].buffer_type = MYSQL_TYPE_LONG;
    rec[1].buffer = (char *)&severity;
    rec[1].buffer_length = sizeof(unsigned int);

    if (test_stmt_error(stmt, mysql_stmt_bind_result(stmt, rec))) {
        mysql_stmt_close(stmt);
        return;
    }

    status = mysql_stmt_fetch(stmt);
    unsigned int nTag = strtoul(lpTagHex, NULL, 16);
    bool bInsertHackReport = false;

    if (status == MYSQL_NO_DATA) {
        if (nTag)
            bInsertHackReport = true;
    } else if (status == 0 || status == MYSQL_DATA_TRUNCATED) {
        bool bHackReportSaysInfected = (severity > 0);
        bool bTrafficSaysInfected = (nTag > 0);
        if (bHackReportSaysInfected != bTrafficSaysInfected)
            bInsertHackReport = true;
    } else {
        test_stmt_error(stmt, status);
    }

    mysql_stmt_close(stmt);

    if (bInsertHackReport) {
        unsigned int nIp = strtoul(lpIpHex, NULL, 16);
        unsigned short nPort = (unsigned short)strtoul(lpPortHex, NULL, 16);
        char *lpInfo = "From traffic report.";

        union _TagUnion cUnion;
        cUnion.nTag = nTag;

        insertHackReport(conn, nIp, nPort, 0 /*nSenderIp*/, "tagged_traffic", lpInfo,
                         cUnion.cTag.owners_id, 0 /*nInfectionId*/,
                         cUnion.cTag.presumed_infected, 0 /*nBotnetId*/);
    }
}

static int parseTrafficField(const char *value, unsigned long *out, unsigned long maxValue)
{
    char *end = NULL;
    unsigned long parsed;

    if (!value || !*value)
        return 0;

    parsed = strtoul(value, &end, 16);
    if (!end || *end != '\0' || parsed > maxValue)
        return 0;

    *out = parsed;
    return 1;
}

static const char *trafficRejectReasonName(unsigned long reason)
{
    switch (reason) {
        case e_TrafficRejectAssistanceThresholdExceeded:
            return "TARAKERNEL_REJECTED_ASSISTANCE_THRESHOLD_EXCEEDED";
        case e_TrafficRejectSshThresholdExceeded:
            return "TARAKERNEL_REJECTED_SSH_THRESHOLD_EXCEEDED";
        default:
            return "TARAKERNEL_REJECTED";
    }
}

static void queueRejectedHackReport(MYSQL *conn,
                                    unsigned long ipFrom,
                                    unsigned long portFrom,
                                    unsigned long ipTo,
                                    unsigned long portTo,
                                    unsigned long tag,
                                    unsigned long reason,
                                    unsigned long decisionSeverity,
                                    unsigned long decisionThreshold)
{
    union _TagUnion cUnion;
    char info[320];
    struct in_addr destination;
    char destinationIp[INET_ADDRSTRLEN] = "unknown";

    cUnion.nTag = (uint16_t)tag;
    destination.s_addr = htonl((uint32_t)ipTo);
    inet_ntop(AF_INET, &destination, destinationIp, sizeof(destinationIp));

    /*
     * The reason is factual and comes directly from tarakernel. It is
     * intentionally not DEMO:. Dbserver owns demo correlation.
     */
    snprintf(info, sizeof(info),
             "%s: destination=%s:%lu severity=%lu threshold=%lu tag_presumed_infected=%u",
             trafficRejectReasonName(reason), destinationIp, portTo,
             decisionSeverity, decisionThreshold,
             cUnion.cTag.presumed_infected);

    insertHackReport(conn,
                     (uint32_t)ipFrom,
                     (unsigned short)portFrom,
                     0 /* nSenderIp */,
                     "iptables",
                     info,
                     cUnion.cTag.owners_id,
                     0 /* nInfectionId */,
                     (int)decisionSeverity,
                     0 /* nBotnetId */);
}

void handleTrafficReportFromKernel(char *lpPayload, int nDataLength)
{
    MYSQL *conn = getConnection();
    if (!conn)
        return;

    int nInserts = 0;
    int nUpdates = 0;
    char *saveRecord = NULL;
    char *record = strtok_r(lpPayload, "^", &saveRecord);

    (void)nDataLength;

    while (record && strcmp(record, "EOF")) {
        char backup[260];
        char *fields[11] = {0};
        int nFields = 0;
        char *saveField = NULL;
        char *field;

        snprintf(backup, sizeof(backup), "%s", record);
        field = strtok_r(record, "-", &saveField);
        while (field && nFields < (int)(sizeof(fields) / sizeof(fields[0]))) {
            fields[nFields++] = field;
            field = strtok_r(NULL, "-", &saveField);
        }

        /*
         * Legacy format (6 fields):
         *   ipFrom-portFrom-ipTo-portTo-count-tag
         * Previous extended format (7 fields):
         *   ipFrom-portFrom-ipTo-portTo-count-tag-action
         * Current format (10 fields):
         *   ipFrom-portFrom-ipTo-portTo-count-tag-action-reason-severity-threshold
         */
        if (nFields != 6 && nFields != 7 && nFields != 10) {
            fprintf(stderr,
                    "***** ERROR ****** Traffic record has %d fields; expected 6, 7 or 10. Skipping: %s\n",
                    nFields, backup);
            record = strtok_r(NULL, "^", &saveRecord);
            continue;
        }

        unsigned long ipFrom, portFrom, ipTo, portTo, count, tag;
        unsigned long action = e_TrafficObserved;
        unsigned long reason = e_TrafficRejectNone;
        unsigned long decisionSeverity = 0;
        unsigned long decisionThreshold = 0;

        if (!parseTrafficField(fields[0], &ipFrom, 0xffffffffUL) ||
            !parseTrafficField(fields[1], &portFrom, 0xffffUL) ||
            !parseTrafficField(fields[2], &ipTo, 0xffffffffUL) ||
            !parseTrafficField(fields[3], &portTo, 0xffffUL) ||
            !parseTrafficField(fields[4], &count, 0xffffUL) ||
            !parseTrafficField(fields[5], &tag, 0xffffUL) ||
            (nFields >= 7 && !parseTrafficField(fields[6], &action, 0xffUL)) ||
            (nFields == 10 && !parseTrafficField(fields[7], &reason, 0xffUL)) ||
            (nFields == 10 && !parseTrafficField(fields[8], &decisionSeverity, 0xffffUL)) ||
            (nFields == 10 && !parseTrafficField(fields[9], &decisionThreshold, 0xffffUL))) {
            fprintf(stderr, "***** ERROR ****** Invalid traffic record. Skipping: %s\n", backup);
            record = strtok_r(NULL, "^", &saveRecord);
            continue;
        }

        if (action != e_TrafficObserved && action != e_TrafficRejected) {
            fprintf(stderr, "***** ERROR ****** Unknown traffic action %lu. Skipping: %s\n", action, backup);
            record = strtok_r(NULL, "^", &saveRecord);
            continue;
        }

        if (action == e_TrafficRejected && nFields == 10 &&
            reason != e_TrafficRejectAssistanceThresholdExceeded &&
            reason != e_TrafficRejectSshThresholdExceeded) {
            fprintf(stderr, "***** ERROR ****** Unknown rejection reason %lu. Skipping: %s\n", reason, backup);
            record = strtok_r(NULL, "^", &saveRecord);
            continue;
        }

        char sql[900];
        snprintf(sql, sizeof(sql),
                 "SELECT trafficId FROM traffic WHERE ipFrom=%lu AND portFrom=%lu "
                 "AND ipTo=%lu AND portTo=%lu AND tag=%lu AND rejected=%lu "
                 "AND (lastSeen IS NULL OR lastSeen > NOW() - INTERVAL 1 MINUTE) "
                 "ORDER BY COALESCE(lastSeen,created) DESC LIMIT 1",
                 ipFrom, portFrom, ipTo, portTo, tag,
                 action == e_TrafficRejected ? 1UL : 0UL);

        unsigned long trafficId = 0;
        if (mysql_query(conn, sql) == 0) {
            MYSQL_RES *res = mysql_store_result(conn);
            if (res) {
                MYSQL_ROW row = mysql_fetch_row(res);
                if (row && row[0])
                    trafficId = strtoul(row[0], NULL, 10);
                mysql_free_result(res);
            }
        } else {
            fprintf(stderr, "MySQL error selecting traffic record: %s\nSQL: %s\n",
                    mysql_error(conn), sql);
        }

        if (trafficId) {
            snprintf(sql, sizeof(sql),
                     "UPDATE traffic SET count=count+%lu,lastSeen=NOW() WHERE trafficId=%lu",
                     count, trafficId);
            if (mysql_query(conn, sql) == 0)
                nUpdates++;
            else
                fprintf(stderr, "MySQL error updating traffic record: %s\nSQL: %s\n",
                        mysql_error(conn), sql);
        } else {
            snprintf(sql, sizeof(sql),
                     "INSERT INTO traffic (ipFrom,portFrom,ipTo,portTo,count,tag,rejected,lastSeen) "
                     "VALUES (%lu,%lu,%lu,%lu,%lu,%lu,%lu,NOW())",
                     ipFrom, portFrom, ipTo, portTo, count, tag,
                     action == e_TrafficRejected ? 1UL : 0UL);
            if (mysql_query(conn, sql) == 0)
                nInserts++;
            else
                fprintf(stderr, "MySQL error inserting traffic record: %s\nSQL: %s\n",
                        mysql_error(conn), sql);
        }

        if (action == e_TrafficRejected) {
            queueRejectedHackReport(conn, ipFrom, portFrom, ipTo, portTo, tag,
                                    reason, decisionSeverity, decisionThreshold);
        } else {
            /* Preserve the existing tag -> hackReport consistency check. */
            checkUpdateHackReport(conn, fields[0], fields[1], fields[5]);
        }

        /* Preserve internal infection lastSeen maintenance. */
        snprintf(sql, sizeof(sql),
                 "UPDATE internalInfections SET lastSeen=NOW() WHERE ip=%lu",
                 ipFrom);
        if (mysql_query(conn, sql) != 0)
            fprintf(stderr, "MySQL error updating internalInfections.lastSeen: %s\n",
                    mysql_error(conn));

        record = strtok_r(NULL, "^", &saveRecord);
    }

    printf("%d records inserted, %d updated in traffic table.\n", nInserts, nUpdates);
    mysql_close(conn);
}
