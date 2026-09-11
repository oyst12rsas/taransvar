
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <signal.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>

#include "../tarakernel/module_globals.h" 

/*
 * Demo 3 uses the normal Assistance Request transport, but its countdown must
 * remain authoritative even if every participant app loses connectivity.  Run
 * the due-session transition from taralink's existing timer so containment and
 * release do not depend on an Android poll reaching appDemoAssistance.php.
 *
 * The demo tables are optional.  Error 1146 simply means Demo 3 has not been
 * installed on this node; in that case normal taralink operation continues.
 */
static void checkDemo3AssistanceTimer(void)
{
    MYSQL *conn = getConnection();
    MYSQL_RES *res = NULL;
    MYSQL_ROW row;

    if (!conn)
        return;

    if (mysql_query(conn,
        "SELECT sessionId,targetIp,threshold,containmentSeconds "
        "FROM demoAssistanceSession "
        "WHERE state='active' AND blockAt<=UTC_TIMESTAMP() AND assistanceRequestId IS NULL "
        "ORDER BY blockAt,sessionId LIMIT 20"))
    {
        if (mysql_errno(conn) != 1146)
            fprintf(stderr, "taralink Demo 3 start check: %s\n", mysql_error(conn));
        mysql_close(conn);
        return;
    }

    res = mysql_store_result(conn);
    if (res)
    {
        while ((row = mysql_fetch_row(res)) != NULL)
        {
            unsigned long sid = strtoul(row[0] ? row[0] : "0", NULL, 10);
            const char *targetIp = row[1] ? row[1] : "";
            unsigned int threshold = row[2] ? (unsigned int)atoi(row[2]) : 0;
            unsigned int containment = row[3] ? (unsigned int)atoi(row[3]) : 120;
            char category[64];
            char comment[128];
            char sql[1024];

            if (!sid || !*targetIp)
                continue;
            if (containment < 15)
                containment = 15;
            if (containment > 600)
                containment = 600;

            snprintf(category, sizeof(category), "demo3_%lu", sid);
            snprintf(comment, sizeof(comment), "DEMO3 start %lu", sid);

            /* Claim the session first. The conditional update prevents the PHP
               fallback and this timer from creating two real requests. */
            snprintf(sql, sizeof(sql),
                "UPDATE demoAssistanceSession SET state='contained',assistanceIssuedAt=UTC_TIMESTAMP(),"
                "releaseAt=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %u SECOND) "
                "WHERE sessionId=%lu AND state='active' AND assistanceRequestId IS NULL",
                containment, sid);

            if (mysql_query(conn, sql) || mysql_affected_rows(conn) != 1)
                continue;

            MYSQL_STMT *stmt = mysql_stmt_init(conn);
            if (!stmt)
                continue;

            const char *insertSql =
                "INSERT INTO assistanceRequest "
                "(purpose,ip,port,category,comment,requestQuality,wantSpoofed,active) "
                "VALUES ('forDistribution',INET_ATON(?),0,?,?,?,b'0',b'1')";

            if (mysql_stmt_prepare(stmt, insertSql, strlen(insertSql)) == 0)
            {
                MYSQL_BIND bind[4];
                memset(bind, 0, sizeof(bind));
                unsigned long targetLen = strlen(targetIp);
                unsigned long categoryLen = strlen(category);
                unsigned long commentLen = strlen(comment);
                unsigned int quality = threshold;

                bind[0].buffer_type = MYSQL_TYPE_STRING;
                bind[0].buffer = (void *)targetIp;
                bind[0].buffer_length = targetLen;
                bind[0].length = &targetLen;
                bind[1].buffer_type = MYSQL_TYPE_STRING;
                bind[1].buffer = category;
                bind[1].buffer_length = categoryLen;
                bind[1].length = &categoryLen;
                bind[2].buffer_type = MYSQL_TYPE_STRING;
                bind[2].buffer = comment;
                bind[2].buffer_length = commentLen;
                bind[2].length = &commentLen;
                bind[3].buffer_type = MYSQL_TYPE_LONG;
                bind[3].buffer = &quality;
                bind[3].is_unsigned = 1;

                if (mysql_stmt_bind_param(stmt, bind) == 0 && mysql_stmt_execute(stmt) == 0)
                {
                    unsigned long requestId = (unsigned long)mysql_stmt_insert_id(stmt);
                    snprintf(sql, sizeof(sql),
                        "UPDATE demoAssistanceSession SET assistanceRequestId=%lu WHERE sessionId=%lu",
                        requestId, sid);
                    mysql_query(conn, sql);
                    printf("Demo 3 %lu: queued real Assistance Request %lu (threshold %u)\n",
                           sid, requestId, threshold);
                }
                else
                {
                    /* Put the session back so the next timer tick can retry. */
                    snprintf(sql, sizeof(sql),
                        "UPDATE demoAssistanceSession SET state='active',assistanceIssuedAt=NULL,releaseAt=NULL "
                        "WHERE sessionId=%lu AND assistanceRequestId IS NULL", sid);
                    mysql_query(conn, sql);
                }
            }
            mysql_stmt_close(stmt);
        }
        mysql_free_result(res);
    }

    if (mysql_query(conn,
        "SELECT sessionId,targetIp,threshold FROM demoAssistanceSession "
        "WHERE state='contained' AND releaseAt<=UTC_TIMESTAMP() AND releaseRequestId IS NULL "
        "ORDER BY releaseAt,sessionId LIMIT 20") == 0)
    {
        res = mysql_store_result(conn);
        if (res)
        {
            while ((row = mysql_fetch_row(res)) != NULL)
            {
                unsigned long sid = strtoul(row[0] ? row[0] : "0", NULL, 10);
                const char *targetIp = row[1] ? row[1] : "";
                unsigned int threshold = row[2] ? (unsigned int)atoi(row[2]) : 0;
                char category[64];
                char comment[128];
                char sql[1024];

                if (!sid || !*targetIp)
                    continue;

                snprintf(category, sizeof(category), "demo3_%lu", sid);
                snprintf(comment, sizeof(comment), "DEMO3 release %lu", sid);

                snprintf(sql, sizeof(sql),
                    "UPDATE demoAssistanceSession SET state='releasing' "
                    "WHERE sessionId=%lu AND state='contained' AND releaseRequestId IS NULL", sid);
                if (mysql_query(conn, sql) || mysql_affected_rows(conn) != 1)
                    continue;

                MYSQL_STMT *stmt = mysql_stmt_init(conn);
                if (!stmt)
                    continue;

                const char *insertSql =
                    "INSERT INTO assistanceRequest "
                    "(purpose,ip,port,category,comment,requestQuality,wantSpoofed,active) "
                    "VALUES ('forDistribution',INET_ATON(?),0,?,?,?,b'0',b'0')";

                if (mysql_stmt_prepare(stmt, insertSql, strlen(insertSql)) == 0)
                {
                    MYSQL_BIND bind[4];
                    memset(bind, 0, sizeof(bind));
                    unsigned long targetLen = strlen(targetIp);
                    unsigned long categoryLen = strlen(category);
                    unsigned long commentLen = strlen(comment);
                    unsigned int quality = threshold;

                    bind[0].buffer_type = MYSQL_TYPE_STRING;
                    bind[0].buffer = (void *)targetIp;
                    bind[0].buffer_length = targetLen;
                    bind[0].length = &targetLen;
                    bind[1].buffer_type = MYSQL_TYPE_STRING;
                    bind[1].buffer = category;
                    bind[1].buffer_length = categoryLen;
                    bind[1].length = &categoryLen;
                    bind[2].buffer_type = MYSQL_TYPE_STRING;
                    bind[2].buffer = comment;
                    bind[2].buffer_length = commentLen;
                    bind[2].length = &commentLen;
                    bind[3].buffer_type = MYSQL_TYPE_LONG;
                    bind[3].buffer = &quality;
                    bind[3].is_unsigned = 1;

                    if (mysql_stmt_bind_param(stmt, bind) == 0 && mysql_stmt_execute(stmt) == 0)
                    {
                        unsigned long requestId = (unsigned long)mysql_stmt_insert_id(stmt);
                        snprintf(sql, sizeof(sql),
                            "UPDATE demoAssistanceSession SET releaseRequestId=%lu,closedAt=UTC_TIMESTAMP() "
                            "WHERE sessionId=%lu", requestId, sid);
                        mysql_query(conn, sql);
                        printf("Demo 3 %lu: queued real Assistance Request release %lu\n",
                               sid, requestId);
                    }
                    else
                    {
                        snprintf(sql, sizeof(sql),
                            "UPDATE demoAssistanceSession SET state='contained' "
                            "WHERE sessionId=%lu AND releaseRequestId IS NULL", sid);
                        mysql_query(conn, sql);
                    }
                }
                mysql_stmt_close(stmt);
            }
            mysql_free_result(res);
        }
    }
    else if (mysql_errno(conn) != 1146)
    {
        fprintf(stderr, "taralink Demo 3 release check: %s\n", mysql_error(conn));
    }

    mysql_close(conn);
}

/*
  This function is deactivated because not able to receive the result.. The reply from tarakernel is the same as sent by abmonitor (request_absecurity_status)
  */

void timer_callback(union sigval timer_data);

pid_t gettid(void);

struct t_eventData{
    int myData;
};

int init_timer()
{
    int res = 0;
    timer_t timerId = 0;
    struct t_eventData eventData = { .myData = 0 };
    struct sigevent sev = { 0 };
    struct itimerspec its = {   .it_value.tv_sec  = C_TIMER_INTERVAL_SECONDS,
                                .it_value.tv_nsec = C_TIMER_INTERVAL_MILLISECONDS * 1000000,
                                .it_interval.tv_sec  = C_TIMER_INTERVAL_SECONDS,
                                .it_interval.tv_nsec = C_TIMER_INTERVAL_MILLISECONDS * 1000000
                            };

    printf("Simple Threading Timer - thread-id: %d\n", gettid());
    sev.sigev_notify = SIGEV_THREAD;
    sev.sigev_notify_function = &timer_callback;
    sev.sigev_value.sival_ptr = &eventData;
    res = timer_create(CLOCK_REALTIME, &sev, &timerId);
    if (res != 0){
        fprintf(stderr, "Error timer_create: %s\n", strerror(errno));
        addWarningRecord("****** ERROR ***** Taralink couldn't create timer and has stopped (T005).");
        exit(-1);
    }
    res = timer_settime(timerId, 0, &its, NULL);
    if (res != 0){
        fprintf(stderr, "Error timer_settime: %s\n", strerror(errno));
        addWarningRecord("****** ERROR ***** Taralink failed to run timer_settime() and has stopped (T006).");
        exit(-1);
    }
    return 0;
}

void timer_callback(union sigval timer_data)
{
    char *lpPayload;
    time_t rawtime;
    time(&rawtime);
    printf("Timer fired: %s", ctime(&rawtime));

    /* Trigger/release Demo 3 before normal assistance processing so a due
       request can enter the normal TaraSec distribution queue this same tick. */
    checkDemo3AssistanceTimer();

    #ifdef DO_REQUEST_ASSISTANCE
        checkRequestAssistance();
    #endif

    #ifdef DO_CHECK_HACK_REPORTS
        checkHackReports();
    #endif

    #ifdef DO_SETUP_CHECK
        int nSequenceNumber, bIsInbound, bReadChangesOnly;
        int nRetval = sentConfiguration(nSequenceNumber=0, bIsInbound=0, bReadChangesOnly=1);

        #ifdef DO_REQUEST_STATUS
        if (!nRetval)
        {
            char *lpMsg = "request_tarakernel_status";
            send_to_kernel(fd, lpMsg, strlen(lpMsg));
        }
        #endif
    #endif
}
