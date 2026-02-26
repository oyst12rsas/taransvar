
int configuraton_init(void);
u32 swappedEndian(u32 nUInt32Val);
void listServers(void);
void listInspections(int nThelist);
void listHoneyports(void);
void listInfections(void);
void listColored(int nBlockDescriptor);
int isListedForInspection(volatile uint32_t ipAddress);
int isPartner(volatile uint32_t ipAddress);
void listPartners(void);
void storeColoredIp(int nBlockDescriptor, struct _ColoredIpSpecification *pElementsArray, volatile uint32_t ipAddress);
void storeColoredListElement(int nBlockDescriptor, volatile uint32_t ipAddress);
int blackListed(__be32 ipAddress);
void storeServerInfo(int port, char *lpQuality);
void storeInfection(volatile uint32_t ipAddress, volatile uint32_t ipNettmask, char *lpQuality);
void storePartner(char *lpIP, char *lpNettmask);
void storeInspectionDirective(int nBlockDescriptor, char *lpIP, char *lpNettmask);
void storeHoneyport(char *lpPort, char *lpHandling);
void storeInstruction(int nBlockDescriptor, volatile uint32_t ipAddress, volatile uint32_t ipNettmask, int port, char *lpQuality);
void releaseConfiguration(void);
bool portForwarded(unsigned int nCheckIfPortForwarding);


