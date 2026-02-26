
void checkIpAddresses(char *lpMessage, int nDataLength)
{
      //partner?:3232248420^partner?:1089053372^partner?:0^
      char *lpElement;
      for (lpElement = strtok(lpMessage, "^"); lpElement; lpElement = strtok(NULL, "^"))
      {
            char * lpSeparator = strchr(lpElement, ':');
            if (!lpSeparator)
            {
                  printf("****** ERROR **** interpreting element: %s", lpElement);
            }
            else
            {
                  *lpSeparator = 0;
                  if (!strcmp(lpElement, "partner?"))
                  {
                        //Check if this is a partner ip address (in decimal form)... NOTE! tarakernel will send both ip addresses.. so check first if it's router's ip
                        char cBuf[100];
                        unsigned int nIp = atol(lpSeparator+1);
          	        sprintf(cBuf, "%u.%u.%u.%u", IPADDRESS(nIp));
                        
                        printf("Found element: %s(%u)\n", cBuf, nIp);
                  }
                  else
                  {
                        printf("******* ERROR ****** unknown keyword in msg from kernel: %s\n", lpElement);
                  }
            }
      }
}

