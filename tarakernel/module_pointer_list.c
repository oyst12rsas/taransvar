//module_pointer_list.c

//NOTE! Read this about memory allocation: https://docs.kernel.org/core-api/memory-allocation.html

static _Node *pPointerList = NULL;

_Node *getLast(_Node *pPointer)
{
	while (pPointer->pNext)
		pPointer = pPointer->pNext;
	return pPointer;
}

void *memAlloc(size_t nSize)
{
	//_Node *pNewElement = kzalloc(nNodeSize, GFP_KERNEL); //Same as the standard kmalloc() but sets to zero
	//_Node *pNewElement = kvmalloc(nSize, GFP_KERNEL); //Calls first kmalloc(). If that fails, tries vmalloc()
	_Node *pNewElement = kvmalloc(nSize, GFP_ATOMIC);
	if (pNewElement)
		memset(pNewElement, 0, nSize);
	return pNewElement;  
}

_Node *getNewBefore(_Node *pPointer, int nStructSize)
{
	//int nNodeSize = sizeof(pPointer) + nStructSize;
	size_t nNodeSize = sizeof(_Node *) + nStructSize;	

	_Node *pNewElement = NULL; //memAlloc(nNodeSize);


	pr_info("nStructSize=%zu total=%zu in_atomic=%d irqs_disabled=%d\n",
        (size_t)nStructSize,
        sizeof(_Node *) + nStructSize,
        in_atomic(),
        irqs_disabled());

	//if (!pNewElement)
		pNewElement = kzalloc(nNodeSize, GFP_ATOMIC);

	if (!pNewElement)
	{
		pr_warn("tarakernel: ***** ERROR from getNewBefore() - unable to allocate %zu byte\n", nNodeSize);
		return NULL;
	}
    
	if (pPointer)
		pNewElement->pNext = pPointer; 
    
	return pNewElement;
}


_Node *getNewAfter(_Node *pPointer, int nStructSize)
{
	int nNodeSize = sizeof(pPointer) + nStructSize;
	_Node *pNewElement = memAlloc(nNodeSize);
  
	if (!pNewElement)
    	return NULL;
    
	if (pPointer)
    	pPointer->pNext = pNewElement;
    
	return pNewElement;
}

int countNodes(_Node *pNode);
int countNodes(_Node *pNode)
{
	int n = 0;
	while (pNode)
	{
    	n++;
    	pNode = pNode->pNext;
  	}
  return n;
}

_Node *makeList(void);
_Node *makeList(void)
{
	_Node *pPointerlist = NULL;
	_Node *pLast = NULL;
	for (int n= 0; n< nElementsInList; n++)
	{
    	if (!pPointerlist)
    	{
            pPointerlist = pLast = getNewAfter(NULL, sizeof(struct _InfectionSpecification));
    	}
    	else
      	{
            pLast = getNewAfter(pLast, sizeof(struct _InfectionSpecification));
            
            if (!pLast)
            {
                nElementsInList = nElementsInList-1;
                break;
            }
      	}
            
      	//struct _InfectionSpecification *pInfection = (struct _InfectionSpecification *)(pLast+sizeof(pLast));
      	//(struct _InfectionSpecification *)(pLast->cInfection).ipAddress = n;
      	pLast->cInfection.ipAddress = n;
    }
    return pPointerlist;
}
void printThem(_Node *pPointerlist);
void printThem(_Node *pPointerlist)
{
    int nElementSize = (nElementsInList > 1000?5:(nElementsInList>100?4:3));
    int nBuffSize = nElementsInList * nElementSize + 20;
   //char * lpBuff = memAlloc(nBuffSize);
   int nCount = 0;

    for (_Node *pPoint = pPointerlist; pPoint != NULL; pPoint = pPoint->pNext)
    {
      nCount++;
      //struct _InfectionSpecification *pInfection = (struct _InfectionSpecification *)(pPoint+sizeof(pPoint));
      //sprintf(lpBuff + strlen(lpBuff), "%d,", pInfection->ipAddress);
    }
    //pr_info("tarakernel: List: (appx %d bytes) %s\n", (nCount * sizeof(struct _Node)), lpBuff);
    //Print meta data only...
    pr_info("tarakernel: #%d: Pointer list generated..: %d elements, appx %ld bytes, intended print buf size: %d\n", nIteration, nCount, (nCount * sizeof(struct _Node)), nBuffSize);
    //kfree(lpBuff);
}

void deleteList(_Node *pNode);
void deleteList(_Node *pNode)
{
  while (pNode)
  {
    _Node *pNext = pNode->pNext; 
    kfree(pNode);
    pNode = pNext;
  }
}

void doPointerTest(void);
void doPointerTest(void)
{
  nIteration++;
  //Called from trafficReportToTaralinkFound() in module_timed_operations.c
  /*
  nElementsInList = nElementsInList + 1;//(nElementsInList<20?2:(nElementsInList<50?5:(nElementsInList<100?10:15)));
  _Node *pPointerlist = makeList();
  printThem(pPointerlist);
  deleteList(pPointerlist);
  */
  _Node *pLast = getLast(pPointerList);
  for (int n = 0;n<10;n++)
  {
      pLast = getNewAfter(pLast, sizeof(struct _InfectionSpecification));
            
      if (!pLast)
            break;
            
      //struct _InfectionSpecification *pInfection = (struct _InfectionSpecification *)(pLast+sizeof(pLast));
      //(struct _InfectionSpecification *)(pLast->cInfection).ipAddress = n;
      pLast->cInfection.ipAddress = n;
  }

  printThem(pPointerList);
}

void doInfectionsPointerListTest(void)
{
    pr_info("tarakernel: Adding infections (testing).\n");

  int nCount = countNodes(pSetup->pConfigurationPointerList[BLOCK_DESCRIPTIOR_INFECTIONS]);
  int n;

  for (n=nCount+1; n < nCount+100; n++)
  {
    storeInfectionInPointerList(n, 255, "testing testing");
  }

/*  for (int n=0; n < 100; n++)
  {
    removeInfectionFromPointerList(n, 255, 50000);
  }*/


/*    listInfectionsPointerList();

    if (isInfectedPointerList(2))
      pr_info("tarakernel: 2 is infected.....\n");
    else
      pr_info("tarakernel: 2 is NOT infected.....\n");

    if (isInfectedPointerList(3))
      pr_info("tarakernel: 3 is infected.....\n");
    else
      pr_info("tarakernel: 3 is NOT infected.....\n");
*/
//    pr_info("tarakernel: Stored (and some removed?). %d nodes in list.\n", n);
    pr_info("tarakernel: Stored. %d nodes in list.\n", n);
    
}




