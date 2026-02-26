
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <signal.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>

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
    // Initiates the times. The timer_callback() is what's being called on timers. See below.

    int res = 0;
    timer_t timerId = 0;

    struct t_eventData eventData = { .myData = 0 };


    /*  sigevent specifies behaviour on expiration  */
    struct sigevent sev = { 0 };

    /* specify start delay and interval
     * it_value and it_interval must not be zero */

    struct itimerspec its = {   .it_value.tv_sec  = C_TIMER_INTERVAL_SECONDS,   //Number of seconds before first timer.. (defined in ../tarakernel/module_globals.h)
                                .it_value.tv_nsec = 0,
                                .it_interval.tv_sec  = C_TIMER_INTERVAL_SECONDS,  //Then number of secongs between timers
                                .it_interval.tv_nsec = 0
                            };

    printf("Simple Threading Timer - thread-id: %d\n", gettid());

    sev.sigev_notify = SIGEV_THREAD;
    sev.sigev_notify_function = &timer_callback;
    sev.sigev_value.sival_ptr = &eventData;


    /* create timer */
    res = timer_create(CLOCK_REALTIME, &sev, &timerId);


    if (res != 0){
        fprintf(stderr, "Error timer_create: %s\n", strerror(errno));
        addWarningRecord("****** ERROR ***** Taralink couldn't create timer and has stopped (T005).");
        exit(-1);
    }

    /* start timer */
    res = timer_settime(timerId, 0, &its, NULL);

    if (res != 0){
        fprintf(stderr, "Error timer_settime: %s\n", strerror(errno));
        addWarningRecord("****** ERROR ***** Taralink failed to run timer_settime() and has stopped (T006).");
        exit(-1);
    }

/*    printf("Press ETNER Key to Exit\n");
    while(getchar()!='\n'){}
    return 0;*/
}

void timer_callback(union sigval timer_data)
{
	char *lpPayload;
	//struct t_eventData *data = timer_data.sival_ptr;
	printf("Timer fired (here) - thread-id: %d\n", gettid());

        struct _SocketData *pSockData = 0;
        
	
	//Check if there's unhandled requests for assistance (under d-dos or brute force attack)
	//Check assistanceRequest mysql table (misc/install.sql) 
	//printf("About to check for requests for assistance..\n");
	checkRequestAssistance();
	//printf("Finished checking for requests for assistance..\n");
                
        checkHackReports();   //Checks if there's reported attacks by units in our network  (module_hack_reports.c)              
                
#ifndef MEMORY_LEAK
	pSockData = getSockData();
	getKernelSocket(pSockData);
#endif	
	int nSequenceNumber, bIsInbound, bReadChangesOnly;
	int nRetval = sentConfiguration(pSockData, nSequenceNumber=0, bIsInbound=0, bReadChangesOnly=1); 
	//printf("After sentConfiguration\n");

#ifndef MEMORY_LEAK
        if (!nRetval)
        {
                //NOTE! Reply to messages sent here is picked up by recvmsg called by main() function (see abmonitor.c)... That's why code below is commented out. 
		sendMessage(pSockData, "request_tarakernel_status");
	}

//*************** NOTE! Try to comment out to fix big time memory leak.....
/*        


	//printf("Waiting for message from kernel\n");

	// Read message from kernel 
       	memset(pSockData->nlh, 0, NLMSG_SPACE(MAX_PAYLOAD)); //Initialize the buffer, otherwise previous msg will remain at end of string.
	printf("About to call recvmsg() - but never gets any reply..\n");

        recvmsg(pSockData->sock_fd, &pSockData->msg, 0);
	printf("******** ERROR - Never gets here.. check recvmsg() call in main()\n");
	lpPayload = (char *)NLMSG_DATA(pSockData->nlh);
	printf("Received message: %s\n", lpPayload);
	
       	if (isConfigurationRequest(lpPayload, &nSequenceNumber))
	{
		sentConfiguration(pSockData, nSequenceNumber, bIsInbound=0, bReadChangesOnly=0);
	}
    */
//************ Comment out until here.... (never gets here anyway....)


	close (pSockData->sock_fd);
	free(pSockData->nlh);
	free(pSockData);
#endif
}
    
 
