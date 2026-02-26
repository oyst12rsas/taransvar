//Taken from https://stackoverflow.com/questions/15215865/netlink-sockets-in-c-using-the-3-x-linux-kernel?lq=1

//Will disable the netlink communication with Taransvar kernel module to test memory leak...
//#define MEMORY_LEAK

#define USE_MYSQL
#ifdef USE_MYSQL
//#include "mysql/mysql.h"
#include "mariadb/mysql.h"
#endif

#include <sys/socket.h>
#include <linux/netlink.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <stdbool.h>

#include <unistd.h>
#include <sys/syscall.h>

//The below header files are for finding IP address 
#include <unistd.h>
#include <errno.h>
#include <netdb.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>

#define C_BUFF_SIZE 4090
/* maximum payload size*/
#define MAX_PAYLOAD 1024 

#define NETLINK_USER 31

#define CONFIG_FILENAME "configfile.txt"

static clock_t lastPing = 0;
static char szWgetBuff[2000];

int configFileExists(void);
#ifdef USE_MYSQL
MYSQL *getConnection();
#endif

struct _SocketData {
  int sock_fd;
  struct msghdr msg;
  struct sockaddr_nl src_addr, dest_addr;
  struct nlmsghdr *nlh;
  struct iovec iov;
};

enum et_wgetCategories {e_wget_assistanceRequest, e_wget_other};
typedef enum et_wgetCategories et_wgetCategories;
#define IPADDRESS(addr) \
	((unsigned char *)&addr)[3], \
	((unsigned char *)&addr)[2], \
	((unsigned char *)&addr)[1], \
	((unsigned char *)&addr)[0]

#include "../tarakernel/module_globals.h" 

struct _SocketData *getSockData();
void getKernelSocket(struct _SocketData *pSockData);
void sendMessage(struct _SocketData *pSockData, char *lpMsg);
int isConfigurationRequest(char *lpPayload, int *lpSequenceNumber);
void checkRequestAssistance(void);
void checkHackReports(void);
unsigned long minutesSincePing();
void setPing();
char *wget(char *lpUrl, char *szBuff, int nBuffSize);
void checkReportStatus(char *lpPayload);
void handleTrafficReportFromKernel(char *lpPayload, int nDataLength);
void addWarningRecord(char *szWarning); //Defined in module_functions.c
int addPendingWgetOk(et_wgetCategories eCategory, char *lpUrl, int nRegardingId);

//Modules contributed by development partners
#include "module_send_configuration.c"
#include "module_timer.c"
#include "module_request_assistance.c"
#include "module_hack_reports.c"
#include "module_traffic_report.c"
#include "module_msg_from_kernel.c"
#include "module_functions.c"

int configFileExists(void)
{
	FILE *file;

	if ((file = fopen(CONFIG_FILENAME, "r")))
	{
		fclose(file);
		return 1;
	}
	return 0;
}

unsigned long minutesSincePing()
{
  //  return 0;
        if (!lastPing)
            return 1000;
            
        unsigned long nSecSincePing = time(NULL) - lastPing;
        return (unsigned long) (nSecSincePing / 60);//(1000*60)); //(1000*1000*60));
}

void setPing()
{
      lastPing = time(NULL);//clock();
}

#ifdef USE_MYSQL
MYSQL *getConnection()
{
      MYSQL *conn;
      conn = mysql_init(0);
	char *server ="localhost";
	char *user = "scriptUsrAces3f3";
	char *password = "rErte8Oi98e-2_#";
	char *database = "taransvar";
	  conn = mysql_init(NULL);

        if (configFileExists())
          return 0;

	  /* Connect to database */
	if (!mysql_real_connect(conn, server, user, password, database, 0, NULL, 0)) {
		fprintf(stderr, "%s\n", mysql_error(conn));
		
	    printf("Unable to connect to DB. Aborting... \n");
	    exit(1);
	}
	return conn;
}
#endif

void insertLog(char * lpLog)
{
#ifdef USE_MYSQL
        MYSQL *conn;

        if (configFileExists())
          return;
          
	conn = getConnection();
	char *lpSQL = "insert into logEntry (fromIP, toIP, protocol, action, comment) values ('now frm userserver','to db','mysql','test','%s');";
	char cBuffer[256];
	sprintf(cBuffer, lpSQL, lpLog);
	//char *lpSQL = "show tables";
	if (mysql_query(conn, cBuffer)) {
	    fprintf(stderr, "%s\n", mysql_error(conn));
	    addWarningRecord("**** ERROR ****** Error logging in on database..");
	    return;
	}

	mysql_close(conn);
#endif
}

void logAccessRequest(char *lpFromIP, char *lpFromPort, char *lpToIP, char *lpToPort, char *lpReply)
{
#ifdef USE_MYSQL
	MYSQL *conn;

        if (configFileExists())
          return;

        conn = getConnection();
	char *lpSQL = "insert into logEntry (fromIP, toIP, protocol, action, comment) values ('%s','%s','prot?','%s','handled');";
	char cBuffer[256];
	sprintf(cBuffer, lpSQL, lpFromIP, lpFromPort, lpToIP, lpToPort, lpReply);

	if (mysql_query(conn, cBuffer)) {
	    fprintf(stderr, "%s\n", mysql_error(conn));
	    addWarningRecord("**** ERROR ****** Logging access request..");
	    return;
	    
	    
	}

	mysql_close(conn);
#endif
}

//ot:static int sock_fd;
static int processId = 0; //The result of getpid() but not working when using gettid() from threads... supposed to be the same?
//ot:struct msghdr msg;

void getKernelSocket(struct _SocketData *pSockData)
{
  //ot:struct sockaddr_nl src_addr;
  int count=0;
  //int socket_fd;
  while ((pSockData->sock_fd=socket(PF_NETLINK, SOCK_RAW, NETLINK_USER)) < 0)
  {
    printf("Unable to open socket... You should confirm that Taransvar kernel module (\"tarakernel\") is running (\"sudo lsmod | grep tarakernel\").\nWaiting (%d)....\n", ++count);
    sleep(10);
  }

  memset(&pSockData->src_addr, 0, sizeof(pSockData->src_addr));
  pSockData->src_addr.nl_family = AF_NETLINK;
  pSockData->src_addr.nl_pid = getpid(); /* self pid */

  bind(pSockData->sock_fd, (struct sockaddr*)&pSockData->src_addr, sizeof(pSockData->src_addr));
}

struct _SocketData *getSockData()
{
  struct _SocketData *pSockData = malloc(sizeof(struct _SocketData));
  //NOTE! Check if can have structure in _SocketData and not just the pointer...
  pSockData->nlh = (struct nlmsghdr *)malloc(NLMSG_SPACE(MAX_PAYLOAD));
  return pSockData;
}

void sendMessage(struct _SocketData *pSockData, char *lpMsg)
{
#ifndef MEMORY_LEAK
  memset(&pSockData->dest_addr, 0, sizeof(pSockData->dest_addr));
  pSockData->dest_addr.nl_family = AF_NETLINK;
  pSockData->dest_addr.nl_pid = 0; /* For Linux Kernel */
  pSockData->dest_addr.nl_groups = 0; /* unicast */

  memset(pSockData->nlh, 0, NLMSG_SPACE(MAX_PAYLOAD));
  pSockData->nlh->nlmsg_len = NLMSG_SPACE(MAX_PAYLOAD);
  pSockData->nlh->nlmsg_pid = processId; //pid;//getpid();  Use gettid() when called in timer thread otherwise getpid()
  pSockData->nlh->nlmsg_flags = 0;

  strcpy(NLMSG_DATA(pSockData->nlh), lpMsg);

  pSockData->iov.iov_base = (void *)pSockData->nlh;
  pSockData->iov.iov_len = pSockData->nlh->nlmsg_len;
  pSockData->msg.msg_name = (void *)&pSockData->dest_addr;
  pSockData->msg.msg_namelen = sizeof(pSockData->dest_addr);
  pSockData->msg.msg_iov = &pSockData->iov;
  pSockData->msg.msg_iovlen = 1;

  //printf("Sending message to kernel: %s\n", lpMsg);
  sendmsg(pSockData->sock_fd,&pSockData->msg,0);
  #endif
}//sendMessage()


int isConfigurationRequest(char *lpPayload, int *lpSequenceNumber)
{
	char *lpSeparator;  
	char *lpSearchKey = "configuration ";

        //printf("Checking if conf request: %s\n", lpPayload);  

	//Check if it's module requesting configuration at format:
	//	configuration <batch number>?...
	lpSeparator = strstr(lpPayload, lpSearchKey);
	if (lpSeparator == lpPayload && *(lpPayload + strlen(lpPayload)-1) == '?')
	{
		//	#READ_CONFIGURATION
		//cReply[strlen(lpPayload)-1] = 0;
		*lpSequenceNumber = atoi(lpPayload+strlen(lpSearchKey));
		return 1;
	}
	return 0;
}	  

void checkReportStatus(char *lpPayload)
{
	if (strstr(lpPayload, "Prerout:"))
	{
		//Received statistics from tarakernel .. send it to global server every 15 minutes(?)
		unsigned long nMinutes = minutesSincePing(); 
		if (nMinutes >= 15)
		{
                        MYSQL *conn;
			MYSQL_RES *res = 0;
			MYSQL_ROW row;
			char cNickName[255];	
			int nThreadId;
			int bFoundData = 0;
			int bSetupOk = 1;

			conn = getConnection();
	      
			char *lpSQL = "select coalesce(systemNick, inet_ntoa('adminIP')), inet_ntoa(adminIP), inet_ntoa(globalDb1ip), inet_ntoa(globalDb2ip), inet_ntoa(globalDb3ip) from setup";
		
			if (mysql_query(conn, lpSQL)) {
			        lpSQL = "select systemNick from inet_ntoa(adminIP) from setup";
				if (mysql_query(conn, lpSQL)) {
			        	strcpy(cNickName, "Unable to read setup");
			        	bSetupOk = 0; //Don't read the result below....
			        } else {
			        
				      fprintf(stderr, "taralink: %s\n", mysql_error(conn));
            	                      addWarningRecord("***** ERROR ***** taralink couldn't read setup");
            	                      return;
				}
			}
			
			if (bSetupOk)
			{
				res = mysql_use_result(conn);
				if ((row = mysql_fetch_row(res)) != NULL)
				{
			        	strcpy(cNickName, row[0]);
				} else {
			        	strcpy(cNickName, "Unable to read setup");
				}
  		                mysql_free_result(res);
			}
	      
	              char *lpFound;
	              while ((lpFound = strchr(lpPayload, '\t')))
	                      *lpFound = '_';
	              while ((lpFound = strchr(lpPayload, '\r')))
	                      *lpFound = '_';
	              while ((lpFound = strchr(lpPayload, '\n')))
	                      *lpFound = '_';
	              while ((lpFound = strchr(lpPayload, ' ')))
	                      *lpFound = '_';
	              setPing();
    	              char szUrl[255];
    	              
                      CURL *payloadCurl = curl_easy_init();
                      if(!payloadCurl) {
                            printf("******* ERROR: curl_easy_init() returned false in checkReportStatus().. Aborting..\n");
                            return;
                      }
                      
                      char *lpPayloadEncoded = curl_easy_escape(payloadCurl, lpPayload, strlen(lpPayload));
                      if(!lpPayloadEncoded) {
                            printf("******* ERROR: curl_easy_init() returned false in checkReportStatus().. Aborting..\n");
                            return;
                      }

                      CURL *nickNamecurl = curl_easy_init();
                      if(!nickNamecurl) {
                            printf("******* ERROR: curl_easy_init() returned false in checkReportStatus().. Aborting..\n");
                            return;
                      }
                      
                      char *lpNickNameUrlEncoded = curl_easy_escape(nickNamecurl, cNickName, strlen(cNickName));
                      if(!lpNickNameUrlEncoded) {
                            printf("******* ERROR: curl_easy_init() returned false in checkReportStatus().. Aborting..\n");
                            return;
                      }

                      printf("\nSending status to global DB servers:\n");
                      for (int n = 0; n < 3; n++)
                      {
                            char *lpGlobalDbIp = row[n+2];
                            if (lpGlobalDbIp && strlen(lpGlobalDbIp) > 7)
                            {
  		                  sprintf(szUrl, "http://%s/script/config_update.php?f=ping&nick=%s&status=%s", lpGlobalDbIp, lpNickNameUrlEncoded, lpPayloadEncoded);
  		                  *szWgetBuff = 0;
		                  wget(szUrl, szWgetBuff, sizeof(szWgetBuff));  //Using global static buffers because reply doesn't come immediately.
		                  printf("%s\n", szUrl);
		            } else {
		                  char szBuf[256];
		                  printf("****** Skipping wrong IP address for global DB server: %s\n", lpGlobalDbIp);
      	                          //addWarningRecord(conn, szBuf);
      	                    }
		                  
                      }
		      mysql_close(conn);
		      
		      curl_free(lpPayloadEncoded);
                      curl_easy_cleanup(payloadCurl);
		      curl_free(lpNickNameUrlEncoded);
                      curl_easy_cleanup(nickNamecurl);

	     }
 	    printf("Minutes (status): %lu (%s)\n", nMinutes, szWgetBuff);
	}
	else {
	          addWarningRecord("Trying to send status to DB server but it has wrong format...");
	}
}

void testingTesting(char *lpPayload, int nSize)
{
        char cBuf[200];
        bufferToHex(lpPayload, nSize, cBuf, 200);
        printf("**** Received: %s\n", lpPayload);
        printf("%s\n", cBuf);
}

int main()
{
	char *lpPayload;

  init_timer();

  char *lpMsg = "Hello kernel...";
  char buffer[1024] = { 0 };
  int new_socket;
  processId = getpid();
  printf("Saving process id: %d\n", processId);
  addWarningRecord("Taralink starting...");
  struct _SocketData *pSockData = getSockData();

  getKernelSocket(pSockData);

  printf("Sending message to kernel: %s\n", lpMsg);
  sendMessage(pSockData, lpMsg);

  while (1)
  {
        //struct nlmsghdr *nlh = NULL;

	printf("Waiting for message from kernel\n");

	// Read message from kernel 
        //nlh = (struct nlmsghdr *)malloc(NLMSG_SPACE(MAX_PAYLOAD));
        memset(pSockData->nlh, 0, NLMSG_SPACE(MAX_PAYLOAD)); //Initialize the buffer, otherwise previous msg will remain at end of string.
	int nDataLength = recvmsg(pSockData->sock_fd, &pSockData->msg, 0);
	lpPayload = (char *)NLMSG_DATA(pSockData->nlh);
	//printf("Received message: %s\n", lpPayload);

	//char *lpSeparator;  
	//insertLog(lpPayload);
	int nSequenceNumber = -1;

        if (isConfigurationRequest(lpPayload, &nSequenceNumber))
        {
      		int bReadChangesOnly;

		printf("Configuration requested..\n");
		sentConfiguration(pSockData, nSequenceNumber, 1, bReadChangesOnly = 0);
	} 
	else
	{
	        //This is better way to find keyword at start....
	        char *lpSeparator = strchr(lpPayload, '|');
	        char cKeyword[20];
	        if (lpSeparator && lpSeparator - lpPayload < sizeof(cKeyword))
	        {
	              strncpy(cKeyword, lpPayload, lpSeparator - lpPayload);
	              cKeyword[lpSeparator - lpPayload] = 0;  //Terminate the string.
	        }
	        else
	              *cKeyword = 0;
	        
	        //Check if it's status report from Taransvar kernel module
	        //char *lpSearchKey = "status|";
		//lpSeparator = strstr(lpPayload, lpSearchKey);
		//if (lpSeparator == lpPayload)
		if (!strcmp(cKeyword, "status"))
		{
		        char *lpStatus = lpSeparator+1;//lpPayload+strlen(lpSearchKey); 
			printf("%s\n", lpStatus);
			
			checkReportStatus(lpStatus);

		}
		else
		{
		        if (!strcmp(cKeyword, "TRAFFIC"))
		        {
                              char cBuf[200];
                              int nTrafficLen = strlen(lpSeparator+1); 
                              //bufferToHex((char*)lpPayload, (nDataLength>60?60:nDataLength), cBuf, 200);
                              //printf("**** Traffic hex dump: %s\n", cBuf);
                              strncpy(cBuf, lpSeparator+1, (nTrafficLen>50?50:nTrafficLen+1));
                              if (nTrafficLen > 50)
                              {
                                    sprintf(cBuf+50," *** truncated, len: %d *** ", nTrafficLen);
                                    strcpy(cBuf+strlen(cBuf), lpSeparator+1+nTrafficLen-50);
                              }

                              printf("**** Traffic received: %s\n", cBuf);//lpSeparator+1);
		        
		              //handleTrafficReportFromKernel(lpPayload+strlen(lpSearchKey), nDataLength - strlen(lpSearchKey));
		              handleTrafficReportFromKernel(lpSeparator+1, nDataLength - (strlen(cKeyword)+1));
		        }
    		        else if (!strcmp(cKeyword, "CHECK"))
    		        {
    		              checkIpAddresses(lpSeparator+1, nDataLength - (strlen(cKeyword)+1));
    		        }
    		        else if (!strcmp(cKeyword, "DUMMY"))
    		        {
    		              testingTesting(lpSeparator+1, nDataLength - (strlen(cKeyword)+1));
    		        }
		        else
		              printf("Unhandled msg (keyword: %s) from kernel (%d bytes): %s\n", cKeyword, nDataLength, lpPayload);
		}
	}	
    }

//OT 1109
//  close(pSockData->sock_fd); //  Gives compiler error for some reason...?????
//  free(pSockData->nlh);
//  free(pSockData);
}

