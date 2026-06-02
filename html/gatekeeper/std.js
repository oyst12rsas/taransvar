
function loaded(nJSVer)
{
    if (nJSVer > 7)
    {
        var szMsg = '<br>Vi har gjort omfattende endringer. Scripts og formattering bør lastes på nytt. <a href="index.php?f=reloadHelp">Se her for hvordan</a>.';
        var cDiv = document.getElementById("topWarn");
        cDiv.innerHTML = szMsg;
        cDiv.style.display = 'block';

    }

   if (typeof(adjustMenu) == "function")
        adjustMenu();


   if (typeof(zoom) == "function")
        zoom();

   if (typeof(initDiv) == "function")
        initDiv();

   if (typeof(initTimer) == "function")
        initTimer();

    return true;
}

function ajax_request_func()
{
    if (typeof cAjaxServer === 'undefined')
        return "ajax.php";
    else
        return cAjaxServer;
}

/*RSS-feed*/

function rssfeedsetup(szTheme, nThemeId, szUrl, szTitle, nFeedId, bFollowsFeed)
{
    bFollowsFeed = parseInt(bFollowsFeed);
    //First check if theme is inserted...
    var szDiv = "rss_theme_"+nThemeId;
    var cTheme = document.getElementById(szDiv);
    
    if (!cTheme)
    {
        cTheme = document.createElement("div");
        cTheme.id = szDiv;
        var cRss = document.getElementById("rss");
        if (cRss)
            cRss.appendChild(cTheme);
        else
            return;
    }
    
    if (!cTheme.innerHTML.length)
    {
        var szHtml = '<h3>'+szTheme;

        if (szUrl.length)
            szHtml += '<a href="javascript:menu(\'rss:closeTheme\',\'id='+nThemeId+'\')"><img src="pics/stop.png" width="16" height="16" title="Lukk '+szTheme+' teamet"></a></h3>';
            //szHtml += '<a href="javascript:confirmMenu(\'rss:closeTheme\',\'id='+nThemeId+'\',\'Er du sikker på at du ønsker å lukke alle kildene for dette temaet?\')"><img src="pics/stop.png" width="16" height="16" title="Lukk '+szTheme+' teamet"></a></h3>';
        else
            szHtml += '<a href="javascript:menu(\'rss:openTheme\',\'id='+nThemeId+'\')"><img src="pics/add.png" width="16" height="16" title="Se aktuelle kilder for '+szTheme+'"></a></h3>';
            
        cTheme.innerHTML = szHtml;
    }
    
    //var feedcontainer = false;
    var szHtml = "";

    if (szTitle.length)
    {
        //feedcontainer=document.getElementById("rss_theme_"+nThemeId);
        szHtml = '<b>'+szTitle+"</b>";

        if (bFollowsFeed)
            szHtml += '<a href="javascript:menu(\'rss:closeFeed\',\'id='+nFeedId+'\')"><img src="pics/stop.png" width="16" height="16" title="Lukk '+szTitle+'"></a>';
        else
            szHtml += '<a href="javascript:menu(\'rss:openFeed\',\'id='+nFeedId+'\')"><img src="pics/add.png" width="16" height="16" title="Se aktuelle artikler hos '+szTitle+'"></a>';
        
        szHtml += '<div id="rssf'+nFeedId+'"></div>';

        cTheme.innerHTML += szHtml;
    }
    
    if (szUrl.length && bFollowsFeed) 
    {
        cGlobalRssArray.push(new Array(szUrl, szTitle, nFeedId, nThemeId)); //141201 new Array
        var feedpointer=new google.feeds.Feed(szUrl); //Google Feed API method
        feedlimit=10;
        feedpointer.setNumEntries(feedlimit) //Google Feed API method
        feedpointer.load(displayfeed) //Google Feed API method
    }
}

function displayfeed(result)
{
    if (!result.error)
    {
        var szUrl = result.feed.feedUrl;
        var szHead = "NOT FOUND!";
        var nThemeId = 0;
        var nFeedId = 0;
        
        for (var n = 0; n<cGlobalRssArray.length; n++)
        {
            var cElem = cGlobalRssArray[n];
            if (!szUrl.localeCompare(cElem[0]))
            {
                //alert("Found "+szUrl+", :"+cElem[1]);
                szHead = cElem[1];
                nThemeId = cElem[3];
                nFeedId = cElem[2];
                break;
            }
        }
        
        //var a = document.createElement('a');
        //a.href = szUrl;
        
        rssoutput='<ul>';
        var thefeeds=result.feed.entries;
        
        for (var i=0; i<thefeeds.length; i++)
            rssoutput+="<li><a href='" + thefeeds[i].link + "' target='_blank'>" + thefeeds[i].title + "</a></li>"
            
        rssoutput+="</ul>";
        //var feedcontainer=document.getElementById("rss_theme_"+nThemeId);
        var feedcontainer=document.getElementById("rssf"+nFeedId);
        if (feedcontainer)
            feedcontainer.innerHTML = rssoutput;
        else
            alert("Feed container not found!");
    }
    else
        alert("Error fetching feeds!");
}

function loadRss()
{
    //google.load("feeds", "1");
    //var cTbl = document.getElementById("contenttbl");
    //var cRow = cTbl.insertRow(cTbl.rows.length);
    //cRow.innerHTML = '<td><div class="new_box"><div id="feeddiv"></div></div></td>';
    
//NOTE! Global vars.. RSS-feed
//feedurl="http://rss.slashdot.org/Slashdot/slashdot"
//feedurl=""
//feedcontainer=document.getElementById("feeddiv")
//feedurl="http://www.amnesty.org/en/news-and-updates/all/all/feed"
    cGlobalRssArray = new Array();
//  OBSOLETE!  rssfeedsetup("http://www.amnesty.org/en/news-and-updates/all/all/feed","Amnesty International",1);
//  OBSOLETE!  rssfeedsetup("http://www.klartale.no/rss/","Klar Tale",2);
//  OBSOLETE!  rssfeedsetup("http://www.aftenposten.no/rss/","Aftenposten",3);
    requestData("rss:initRssFeeds","");
}

function preventDefault(event) 
{
    event.stopPropagation();
    event.preventDefault();
}

function handleDrop(cDiv, event, nId) 
{
    event.stopPropagation();
    event.preventDefault();
    var szFunc = "sendFile";
//    var nId = 0;
    var szParams = "";

//    alert("File received to orgig func! Id: "+nId);
//    return;
    
    var filesArray = event.dataTransfer.files;
    for (var i=0; i<filesArray.length; i++) 
	{
        sendFile(filesArray[i], szFunc, nId);
	}
}

function handleDropRegarding(cDiv, event, szWhat, nId) 
{
    if (event)
    {
        event.stopPropagation();
        event.preventDefault();
    }
    var szParams = "";

    var filesArray = event.dataTransfer.files;
    for (var i=0; i<filesArray.length; i++) 
	{
        var uri = "uploader.php?f=regarding&what="+szWhat+"&id="+nId; //"upltest.php";//
        var xhr = new XMLHttpRequest();
        var fd = new FormData();
        
        xhr.open("POST", uri, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                // Handle response.
                //alert("From upltest: "+xhr.responseText); // handle response.
                var reply = xhr.responseText;
                handleReceivedAjaxText(reply);
            }
        };
        
        //alert("About to send: "+file);
        
        fd.append('uploadedfile', filesArray[i]);
        // Initiate a multipart/form-data upload
        xhr.send(fd);          
	}
}

function handleSelectedRegarding(szFormId, szWhat, nId, bNewComment) 
{
    var form = document.getElementById(szFormId);
    var formData = new FormData(form);

    var xhr = new XMLHttpRequest();
    // Add any event handlers here...
    var szParam = 'uploader.php?what='+szWhat+'&id='+nId+'&newcmnt='+bNewComment;
    xhr.open('POST', szParam, true);
    xhr.send(formData);
    alert("Filen er lastet opp. (..du må nok trykke refresh for å se den i listen)");
    if (bNewComment)
    {
        setTimeout(requestData, 1000, "cheatLink","wt="+szWhat+"&id="+nId);
    }
  //  location.reload();
}


function uploadSubmitted(cThis) 
{
    var cInput = cThis.uploadedfile;
    
    if (!userConfirmed("Do you want to upload the selected file?"))
        return;

    szFunc = "sendFile";
    nId = 0;
    
    var filesArray = cInput.files; /* now you can work with the file list */
    for (var i=0; i<filesArray.length; i++) 
    {
        //alert("Sending "+filesArray[i]);
        sendFile(filesArray[i], szFunc, nId);
    }
}

function sendFile(file, szFunc, nId) 
{
    var uri = "uploader.php?f="+szFunc+"&id="+nId; //"upltest.php";//
    var xhr = new XMLHttpRequest();
    var fd = new FormData();
    //alert(uri);
    
    xhr.open("POST", uri, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            // Handle response.
            //alert("From upltest: "+xhr.responseText); // handle response.
            var reply = xhr.responseText;
            handleReceivedAjaxText(reply);
            
        }
    };
    
    //alert("About to send: "+file);
    
    fd.append('uploadedfile', file);
    // Initiate a multipart/form-data upload
    xhr.send(fd);          
}

  
function loadRequests(szRequests)
{
    var bFound = false;
    var cReqFld = document.getElementById(szRequests);
//alert(szRequests); 170130
    if (cReqFld)
    {
        var szRequests = cReqFld.innerHTML.replace(/&amp;/g, "&");
        var cRequests = szRequests.split("#");
        //alert("Requests: "+cReqFld.innerHTML);
        for (var n=0; n<cRequests.length; n++)
            if (cRequests[n].length)
            {
                //alert("Request found: "+cRequests[n]);
                if (cRequests[n].indexOf("classTimer:") == 0)
                {
                    alert("Timer found..."+cRequests[n]);
                    var cParts = cRequests[n].split(":");
                    
                    setInterval(function(){classTimer(cParts[1])},10000);   150518
                }
                else
                {
                    //alert(cRequests[n]);
                    
                    //140529 - DEGUGGING.... dropping timer... commented below.
                    requestData(cRequests[n],"");
                    bFound = true;
                }
            }
    }
    return bFound;
}

function chosen(cFld, nSelected)
{
    //alert(cFld.id+", chosen: "+nSelected);
    classRequest("chosen", "id="+cFld.id+"&chosen="+nSelected);
}

function regNewBtn(cFld, nSelected)
{
    //alert(cFld.id+", chosen: "+nSelected);
    var cEditFld = document.getElementById(cFld.id);
    //alert("Surprise: "+cEditFld.value);
    var szVal = cEditFld.value;
    classRequest("regNew", "id="+cFld.id+"&val="+szVal);
}

function userConfirmed(szMsg)
{
    var ask=confirm(szMsg);
    return ask;
}

function LeadingZero(Time)   //Searchkey: zeropad
{
    return (Time < 10) ? "0" + Time : + Time;
}

function trimmed(s)
{
    return s.replace(/^\s+|\s+$/g, "");
}

function std_ajax_reply(reply)
{
        var cArr = reply.split("#");
        
        if (cArr[0] == "debug") //|| cArr.length <3) 
        {    //Probably run-time error in content.php
                document.getElementById("content").innerHTML = reply;
                return;
        }
        
        var i;
        for(i=0; i<cArr.length; i++) 
        {
            var szDiv = cArr[i];
            
            //alert("Found: "+szDiv);
            var cElement = szDiv.split("|");
            //alert("Splitted..");
            //alert("Elements: "+cElement.length);

            var szTag = cElement[0];
            
            if (szTag.length > 35 || cElement.length < 2)   //Object ID is 20 chars... (21 for lite i test...)  Now using: "module^<object tag(20char)>¤<fieldId>".
            {
                if (trimmed(szTag).length)
                {
                    //if (szTag.search("select")>=0|| szTag.search("update")>= 0)
                    //    alert("Some sql error occurred. Please contact webmaster."+szTag);
                    //else
                    //alert("Probably error: "+szTag);

                    if (szTag.search("<xml><commands></commands></xml>") < 0)       //260205 - not properly tested.....
                    {
                        if (szTag.search("Data base connection failed")!= -1)
                            handleDbError(reply);
                        else
                        {
                         /*   var div = document.createElement("div");
                            div.innerHTML = szTag;
                            var szStripped = div.textContent || div.innerText || "";

                            if (!szStripped.length)
                                szStripped = szTag;
                        */

                            if (debugging())
                            {
                                var cDebug = document.getElementById("debug");
                                if (cDebug)
                                    cDebug.innerHTML = szTag.replace("<","&lt;");
                                else
                                    alert("Probably error: "+szTag.replace("<","&lt;"));
                            }

                            reportError("", "", "Used to display these: "+szTag);                       
                        }
                        //alert("We are sorry, but some error occurred and has been reported to our support center.");
                    }
                    //else
                    //    alert("dropping empty xml");
                }
                return;
            }
            
            
            //alert("Tag: "+szTag);
            szTag = trimmed(szTag);
            var szVal = trimmed(cElement[1]);
            
            //alert(szVal + ": "+szTag);
                                
            if (szTag.length < 4 || szTag.length > 35)
            {    //Probably error in content.php script... Dump all to content field and return.
                document.getElementById("debug").innerHTML = reply;
                return;
            }
            else if (szTag == "alert")
                alert(szVal);
            else if (szTag == "refresh")
                window.location.reload(true);
            else if (szTag == "openurl")
            {
                window.open(szVal,'_self');
            }
            else
            {
                //************ DEBUG NOTE! If ends up in debug, then check the test on tag length > 20 above....
                var cArrCheckAdd = szTag.split("^");
                
                if (cArrCheckAdd.length >= 2)
                {
                    if (cArrCheckAdd[0] == "add")
                    {
                        //Add to div-field can be specified by "add^degub"... to add to debug field...
                        //alert("Add tag found...");
                        var fld = document.getElementById(cArrCheckAdd[1]);
                        fld.innerHTML += szVal;
                    }
                    else if (cArrCheckAdd[0] == "insert")
                    {
                        //Add to div-field can be specified by "add^degub"... to add to debug field...
                        //alert("Add tag found...");
                        var fld = document.getElementById(cArrCheckAdd[1]);
                        fld.innerHTML = szVal + fld.innerHTML;
                    }
                    else     if (cArrCheckAdd[0] == "val")
                    {
                        //Set value attribute instead of innerHTML
                        var fld = document.getElementById(cArrCheckAdd[1]);
                        fld.value = szVal;
                    }
                    else if (cArrCheckAdd[0] == "module")
                    {
                        if (typeof(module_ajax_reply) == "function")
                            module_ajax_reply(cArrCheckAdd, szVal);
                        else
                            alert("Module ajax handler is not defined..");
                    }
                    else if (cArrCheckAdd[0] == "style")
                    {
                        var cFld = document.getElementById(cArrCheckAdd[1]);
                        cFld.style = szVal;
                        
                    }
                }
                else
                {
                    var fld = document.getElementById(szTag);
            
                    if (fld !== undefined)
                    {
                        //alert(szTag+" is found: "+cElement[1]);
                        cFld = document.getElementById(szTag);
                        
                        if (cFld)
                            cFld.innerHTML = szVal; //"set by javascropt!!!";
                    }
                    else
                        alert(szTag+" not found!");
                }
            };
        }
}



function getTable(cFld)
{
    //cFld is input field.. Return table element for this field...
    //alert("about to look for table");
    //alert("Finding table");
    
    while (cFld && cFld.tagName != "TABLE")
        cFld = cFld.parentNode;
        
    return cFld;
    
/*    var cDiv = cFld.parentNode;
    var cTr;
    if (cDiv.tagName == "TR")
        var cTr = cDiv;
    else
    {
        var cTd;
        if (cDiv.tagName == "TD")
            cTd = cDiv;
        else
            cTd = cDiv.parentNode;
    
        if (cTd.tagName != "TD")
        {
            //alert("Not a TD: "+ cTd.tagName);
            return 0;
        }
        cTr = cTd.parentNode;
    }
    //alert("Td found: "+cTd.innerHTML);
    //alert("Tr found: "+cTr.innerHTML);
    var cTBody = cTr.parentNode;
    
    var cTbl = cTBody.parentNode;


    //alert("Table found!");
    return cTbl;
*/
}

function getFieldWithTagAtRow(cRow, szFieldId, szFldType)
{
    var cInputs = cRow.getElementsByTagName(szFldType);

    for (var n = 0; n < cInputs.length; n++)
    {
        if (cInputs[n].id == szFieldId)
            return cInputs[n];
    }
    return false;
}

function getFieldSameRow(cFld, szFieldId, szFldType)
{
    //NOTE! Better use getFieldSameRowAllTypes(
    //Not working if there's several fields
    if (!cFld)
        return false;
    var cTd = cFld.parentNode;
    var cRow;

    switch (cTd.tagName)
    {
        case "DIV":
            cTd = cTd.parentNode;   //True for team select drop list
            cRow = cTd.parentNode;
            break;
        case "TBODY":
            cRow = cFld;
            break;
        default:
            cRow = cTd.parentNode;
    }    

    if (cRow.tagName != "TR")
    {
        userWarning("Row tag not found: "+cRow.tagName);
        return;
    }

    return getFieldWithTagAtRow(cRow, szFieldId, szFldType);    
}
    
function getFieldAtRow(cRow, szFieldId) //keyword: RowField
{
    if (!cRow)
        return false;
            
    if (cRow.tagName != "TR")
        alert("Row tag not found: "+cRow.tagName);
    
    var cFlds = new Array(3);
    cFlds[0] = cRow.getElementsByTagName("INPUT");
    cFlds[1] =  cRow.getElementsByTagName("select");
    cFlds[2] =  cRow.getElementsByTagName("DIV");
    //cFlds.concat(cInputs);
    
    //cFlds.concat(cDrops);

    for (var m=0;m<cFlds.length;m++)
        for (var n = 0; n < cFlds[m].length; n++)
        {
            if (cFlds[m][n].id == szFieldId)
                return cFlds[m][n];
        }
    return false;
}

function getFieldSameRowAllTypes(cFld, szFieldId)
{
    while (cFld && cFld.tagName.localeCompare("TR"))
        cFld = cFld.parentNode;
        
    //Not working if there's several fields
    //var cTd = cFld.parentNode;
    
    //if (cTd.tagName == "DIV")    //True for team select drop list
    //    cTd = cTd.parentNode;    
    //var cRow = cTd.parentNode;

    return getFieldAtRow(cFld /*cRow*/, szFieldId);
}

function rowNumber(cFld)
{
    var cRow = cFld;
    while (cRow.tagName != "TR")
        cRow = cRow.parentNode;

    //return cRow.rowIndex; - for some reason rowIndex was -1
        
    var  cTbl = getTable(cFld);   
    if (!cTbl)
        return -1;
        
    for (var y=0;y<cTbl.rows.length; y++)
    {
        if (cTbl.rows[y] == cRow)
            return y;
    }
    return -1; 
}

function rowNumberWidFldtype(cFld, szFldType)
{
    var cTbl = getTable(cFld);
    if (!cTbl)
    {
        //alert("Table not found!!!");
        return 0;
    }
    for (var y=0;y<cTbl.rows.length; y++)
    {
        var cRow = cTbl.rows[y];
        var cInputs = cRow.getElementsByTagName(szFldType);
        
        for (var x=0; x < cInputs.length; x++)
            if (cInputs[x] == cFld)
                return y+1;
    }    

    alert("Cell not found!!");
    return -1;
}

function rowNumberOld(cFld)
{
    //if (nRow = rowNumberNew(cFld) != rowNumberWidFldtype(cFld, "INPUT"))
    //    alert("Error in function that calculates row number...");
        
    return rowNumber(cFld); //nRow;
}

function NB_OLD_fillSubdivList()
{
    var xmlhttp;
    var url;
    var me;

    if (window.XMLHttpRequest)
      {// code for IE7+, Firefox, Chrome, Opera, Safari
      xmlhttp=new XMLHttpRequest();
      }
    else
      {// code for IE6, IE5
      xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
    xmlhttp.onreadystatechange=function()
      {
      if (xmlhttp.readyState==4 && xmlhttp.status==200)
        {
        document.getElementById("subdivdiv").innerHTML=xmlhttp.responseText;
        }
      }

    var fld = document.getElementById("municipality");
    var muni;
    if (fld)
        muni =  fld.value;
    else
        muni = document.getElementById("munidefault").value;

    var szCountry = document.getElementById("country").value;
    url = "munidrop.php?municipality="+muni+"&country="+szCountry;
    xmlhttp.open("GET",url,true);
    xmlhttp.send();

    var cCl = document.getElementById("cl");
    if (cCl)
    {
        var szParam = "cl="+cCl.innerHTML+"&muni="+muni;
        requestData("fillsubdivlist", szParam);
        //alert("Sent....:"+szParam);
    }

}

function NB_OLD_fillMunicipalityList()
{

    var xmlhttp;
    var url;
    var me;

    if (window.XMLHttpRequest)
      {// code for IE7+, Firefox, Chrome, Opera, Safari
      xmlhttp=new XMLHttpRequest();
      }
    else
      {// code for IE6, IE5
      xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
      }
    xmlhttp.onreadystatechange=function()
      {
      if (xmlhttp.readyState==4 && xmlhttp.status==200)
        {
        document.getElementById("municipalitydrop").innerHTML=xmlhttp.responseText;
        }
      }

    var fld = document.getElementById("county")
    var county;

    if (fld)
        county = fld.value;
    else
    {
        muni = document.getElementById("munidefault").value;
        county = muni;
    }    
    var szCountry = document.getElementById("country").value;
    url = "munidrop.php?county="+county+"&country="+szCountry;
    //alert(url);
    xmlhttp.open("GET",url,true);
    xmlhttp.send();

    var cCl = document.getElementById("cl");
    if (cCl)
    {
        var szParam = "cl="+cCl.innerHTML+"&county="+county;
        requestData("fillmunilist", szParam);
    }
}

function subdivAdded(cEdit,bFillNow)
{
    //alert("About to add: "+cEdit.value);
    var szCountry = document.getElementById("country").value;
    var szMuni = document.getElementById("muni").value;
    requestData('tools:regSubdiv','co='+szCountry+'&muni='+szMuni+'&new='+cEdit.value);
    
    //Remove the script from header
    //var pHead = window.document.getElementsByTagName('head')[0];
    
    //if (pHead)
    //    alert("Head found...");
    //pHead.removeChild(objScript);
    
    var szFuncName = "subDivArr_"+szCountry+"_"+szMuni;
    var szVarForFunc = "func_"+ szFuncName;

    if (typeof window[szVarForFunc] !== undefined)
        window[szVarForFunc] = undefined;
    
    if (false)//typeof window[szFuncName] === 'function')
    {
        alert("Function existed");
        delete window[szFuncName];
        //window[szFuncName] = null;
    }

    //if (typeof window[szFuncName] === 'function')
    //    alert("Function still exist");
    //else
    //    alert("Function no longer exist");

    
    setTimeout(function () 
                {
                    fillSubdivList();   //Wait until registrered.. Then fill list again
                }, 2000);

    //alert("Ny bydel registrert: "+cEdit.value);
}

function addSubdivEdits()
{
    var cDiv = document.getElementById("subdivdiv");
    var szHtml = 'Registrer ny bydel/område: <input type="text" onchange="subdivAdded(this)">';
    cDiv.innerHTML = szHtml;
}

function fillSubdivList()
{
    var szMuni = document.getElementById("muni").value;
    var cSubDivDrop = getLocSelDrop("subdiv", szMuni);

    cSubDivDiv = document.getElementById("subdivdiv");            

    cSubDivDiv.innerHTML = "";
    cSubDivDiv.appendChild(cSubDivDrop);
    
    cSubDivDrop.onchange = function(cEvent)
        {
              //140705
              //alert('Subdiv selected.. not yet learned to change to static text...');  
              var nChosen = cEvent.srcElement.value;
              if (nChosen == -1)
                addSubdivEdits();
              else
                setStaticTxtForSelected(cEvent.srcElement);
        };
    
    
}

function fillMunicipalityList()
{
    var cCounty = document.getElementById("county");
    if (cCounty)
    {
        var szCounty = cCounty.value;
        var cMuniDrop = getLocSelDrop("muni", szCounty);
        cMuniDiv = document.getElementById("munidiv");            

        cMuniDiv.innerHTML = "";
        cMuniDiv.appendChild(cMuniDrop);
        
        cMuniDrop.onchange = function(cEvent)
                {
                    //140705
                  fillSubdivList();
                  setStaticTxtForSelected(cEvent.srcElement);
                };
        
        document.getElementById("subdivdiv").innerHTML = '<input type="hidden" id="subdiv" value="0">';
    }
    else
        fillCountyList(document.getElementById("countydiv"));
}

function fillSomethingForLocationSelector(cBtn)
{
    //Figure out what kind of info
    var cDiv = cBtn.parentNode; //e.g. countydiv
    //cDiv.innerHTML = ""; Can't blank bzoc contains hidden field...
    
    switch(cDiv.id)
    {
        case "countrydiv":
            fillCountryList();
            clearLowerLevelDivs("country");
            break;
            
        case "countydiv":
            fillCountyList(cDiv);
            clearLowerLevelDivs("county");
            break;

        case "munidiv":
            fillMunicipalityList();
            clearLowerLevelDivs("muni");
            break;
            
        case "subdivdiv":
            fillSubdivList(cDiv);
            break;

        default:
            alert("Can't handle "+cDiv.id);
    }
    //alert("It was "+cDiv.id);
}

function setStaticLocationSelectorTxt(szDropId, szSelected, szNewTxt)
{
    var cDiv = document.getElementById(szDropId+"div");
    var szHtml = '<input type="hidden" id="'+szDropId+'" value="'+szSelected+'">'+szNewTxt;
    szHtml += '<img src="pics/edit.png" border="0" width="16" height="16" alt="Klikk for å endre" onclick="fillSomethingForLocationSelector(this)">';
    cDiv.innerHTML = szHtml;
}

function setStaticTxtForSelected(cDrop)
{
    var szNewTxt = "Not found..";
    var szSelected = cDrop.value;
    for (var n = 0; n < cDrop.options.length; n++)
    if (!cDrop.options[n].value.localeCompare(szSelected))
    {
        szNewTxt = cDrop.options[n].text;
        break;
    }
    
    var szDropId = cDrop.id;

    setStaticLocationSelectorTxt(szDropId, szSelected, szNewTxt);
}

function clearLowerLevelDivs(szCurLevel)
{
    switch (szCurLevel)
    {
        case "country":
            document.getElementById("countydiv").innerHTML = '<input type="hidden" id="county" value="0">';
        case "county":
            document.getElementById("munidiv").innerHTML = '<input type="hidden" id="muni" value="0">';
        case "muni":
            document.getElementById("subdivdiv").innerHTML = '<input type="hidden" id="subdiv" value="0">';
            break;
        default: 
            alert("Not yet learned how to blank for "+szCurLevel);
    }
    
}

function fillCountryList(cCountryDiv)
{
    var szCountryCode = document.getElementById("country").value;;

    //$szTxt .= "County drop: ";
    //szOnChange = "municipalitySearched(this)";
    //NOTE Not saving any county code.. So has to send country code
    var cCountryDrop = getLocSelDrop("country", szCountryCode);

    if (!cCountryDiv)    //NOTE! Not tested...            
        cCountryDiv = document.getElementById("countrydiv");            

    cCountryDiv.innerHTML = "";        
    cCountryDiv.appendChild(cCountryDrop);
    
    cCountryDrop.onchange = function(cEvent)
            {
              setStaticTxtForSelected(cEvent.srcElement);
              fillCountyList();
            };

    if (document.getElementById("munidefault"))
        document.getElementById("munidefault").value = "0";

    if (document.getElementById("subdivdefault"))
        document.getElementById("subdivdefault").value = "0";
}

function fillCountyList(cCountyDiv)
{
    var szCountryCode = document.getElementById("country").value;;

    //$szTxt .= "County drop: ";
    szOnChange = "municipalitySearched(this)";
    //NOTE Not saving any county code.. So has to send country code
    var cCountyDrop = getLocSelDrop("county", szCountryCode);

    if (!cCountyDiv)    //NOTE! Not tested...            
        cCountyDiv = document.getElementById("countydiv");            

    cCountyDiv.appendChild(cCountyDrop);
    
    cCountyDrop.onchange = function(cEvent)
            {
              setStaticTxtForSelected(cEvent.srcElement);
              fillMunicipalityList();
            };

    if (document.getElementById("munidefault"))
        document.getElementById("munidefault").value = "0";

    if (document.getElementById("subdivdefault"))
        document.getElementById("subdivdefault").value = "0";
        
    document.getElementById("munidiv").innerHTML = '<input type="hidden" id="muni" value="0">';
    document.getElementById("subdivdiv").innerHTML = '<input type="hidden" id="subdiv" value="0">';
}

var tooltip=function(){
 var id = 'tt';
 var top = 3;
 var left = 3;
 var maxw = 300;
 var speed = 10;
 var timer = 20;
 var endalpha = 95;
 var alpha = 0;
 var tt,t,c,b,h;
 var ie = document.all ? true : false;
 return{
  show:function(v,w){
   if(tt == null){
    tt = document.createElement('div');
    tt.setAttribute('id',id);
    t = document.createElement('div');
    t.setAttribute('id',id + 'top');
    c = document.createElement('div');
    c.setAttribute('id',id + 'cont');
    b = document.createElement('div');
    b.setAttribute('id',id + 'bot');
    tt.appendChild(t);
    tt.appendChild(c);
    tt.appendChild(b);
    document.body.appendChild(tt);
    tt.style.opacity = 0;
    tt.style.filter = 'alpha(opacity=0)';
    document.onmousemove = this.pos;
   }
   tt.style.display = 'block';
   c.innerHTML = v;
   tt.style.width = w ? w + 'px' : 'auto';
   if(!w && ie){
    t.style.display = 'none';
    b.style.display = 'none';
    tt.style.width = tt.offsetWidth;
    t.style.display = 'block';
    b.style.display = 'block';
   }
  if(tt.offsetWidth > maxw){tt.style.width = maxw + 'px'}
  h = parseInt(tt.offsetHeight) + top;
  clearInterval(tt.timer);
  tt.timer = setInterval(function(){tooltip.fade(1)},timer);
  },
  pos:function(e){
   var u = ie ? event.clientY + document.documentElement.scrollTop : e.pageY;
   var l = ie ? event.clientX + document.documentElement.scrollLeft : e.pageX;
   tt.style.top = (u - h) + 'px';
   tt.style.left = (l + left) + 'px';
  },
  fade:function(d){
   var a = alpha;
   if((a != endalpha && d == 1) || (a != 0 && d == -1)){
    var i = speed;
   if(endalpha - a < speed && d == 1){
    i = endalpha - a;
   }else if(alpha < speed && d == -1){
     i = a;
   }
   alpha = a + (i * d);
   tt.style.opacity = alpha * .01;
   tt.style.filter = 'alpha(opacity=' + alpha + ')';
  }else{
    clearInterval(tt.timer);
     if(d == -1){tt.style.display = 'none'}
  }
 },
 hide:function(){
  clearInterval(tt.timer);
   tt.timer = setInterval(function(){tooltip.fade(-1)},timer);
  }
 };
}();


function toggle_visibility(id) 
{
       var e = document.getElementById(id);
       if(e.style.display == 'block')
          e.style.display = 'none';
       else
          e.style.display = 'block';
    
    return e.style.display;
}


function findForm(cFld) //keyword: getForm
{
    if (cFld.form)
        return cFld.form;

    var cForms = document.forms;
    
    for (var y = 0; y < cForms.length; y++)
    {
        cForm = cForms[y];
        
        for (var cCheck = cFld.parentElement; cCheck; cCheck = cCheck.parentElement)
        {
            if (1== 0)
                alert("feil");
                
            if (cCheck == cForm)
                return cForm;
        }
    }
    
    return false;
}

function clickEdit(cFld)
{
    var szId = cFld.id;
    
    var bEditing;
    var cView;
    switch (szId.substring(0, 1))
    {
        case "d":
            cView = getNextSibling(cFld, true);       
            break;
        case "b":
            //Save button pressed
            cEdit = getNextSibling(cFld, false);       
            formClassRequest(cFld, "saveClkEd", "id="+szId+"&val="+cEdit.value);
            cFld = cFld.parentNode; //Otherwise btn disappears for good...
            cView = getNextSibling(cFld, false);       
            cView.innerHTML = cEdit.value;
            //alert(cEdit.value);
            break;
            
        case "e": 
            break;
        default:
            cView = getNextSibling(cFld, false);       








            //alert(szId);
    }        

    if (cView)
    {
        cView.style.display = "inline";
        cFld.style.display = 'none';
        cView.focus();
    }
}

function getNextSibling(cFld, bDirection)

{
    var cParent = cFld.parentNode;

    for (var i = 0; i < cParent.children.length; i++) 
    {
        if (cParent.children[i] == cFld)
            if (bDirection)
                return cParent.children[i+1];   //Note! error if last...
            else
                return cParent.children[i-1];
    }
}

function openNextDiv(cFld)
{
    //alert("her");
    cDiv = getNextSibling(cFld, true);
    //alert(cDiv.className);
    cDiv.style.display = "inline";
}

function checkMatchField(szSearch, cFld)
{
    if (cFld === undefined)
        return "";
        
    var nFound = cFld.value.toLowerCase().indexOf(szSearch);
        
    if (nFound == -1)
        return "";
    else
        return cFld.value;
}        

function quickSearchChanged(cFld)
{
    var cLinks = document.getElementById("links");
    var bShowAll = false;

    if (cLinks) //False if in other page than Phone Book
        if (cFld.value.length < 3)
        {
            cLinks.style.display = "inline";
            bShowAll = true;
        }
        else
            cLinks.style.display = "none";
            
    var cRes = document.getElementById("quickSrchResult");
    var formsCollection = document.getElementsByTagName("form");
    var szSearch = cFld.value.toLowerCase();
    var szTxt = "";//cFld.value+": ";
    
    for(var i=0;i<formsCollection.length;i++)
    {
        var cForm = formsCollection[i];
        
        if (!cForm || cForm.name.indexOf("formTxt")== -1)
            continue;
        
        if (!bShowAll)
        {
            var szMatch = checkMatchField(szSearch, cForm.nm);
            
            if (!szMatch.length)
                szMatch = checkMatchField(szSearch, cForm.fb);
            
            if (!szMatch.length)
                szMatch = checkMatchField(szSearch, cForm.ym);
            
            if (!szMatch.length)
                szMatch = checkMatchField(szSearch, cForm.im);

            if (szMatch.length)    
            {
                //if (szTxt.length)
                //    szTxt +=", ";
                    
                //szTxt += '<a href="index.php?func=show&id='+cForm.nId.value+'">'+szMatch+'</a>';
                szTxt += '<div class="soslink" onclick="followPerson('+cForm.id.substr(7)+')">'+szMatch+'</div>';
                cForm.style.display = "inline";
            }
            else
                cForm.style.display = "none";
        }
        else
            cForm.style.display = "inline";
    }  
    
    if (szTxt.length)
        szTxt = '<h2>Quick search result:</h2>'+szTxt;  
    cRes.innerHTML = szTxt;
    //140722 
    moveToTop("quickSrchResult");
}

//document.onkeyup = quickSearchKeyPressed;

function quickSearchKeyPressed(event)
{
   var KeyID = (window.event) ? window.event.keyCode : event.keyCode;
   switch(KeyID)
   {
      case 18:
      var cRes = document.getElementById("quickSrchResult");
      if (cRes)
        cRes.innerHTML = "Alt key pressed (quick select not ready..)!";
      break; 

      case 17:
      //alert("Ctrl pressed...");
      
      case '1':
      var cRes = document.getElementById("quickSrchResult");
      if (cRes)
        cRes.innerHTML = "1 pressed!";
      return false; 
      
      break;
   }
    return true;    
}


function parseXmlReply(szReply)
{
    if (window.DOMParser)
       {
       parser=new DOMParser();
       xmlDoc=parser.parseFromString(szReply,"text/xml");
       }
     else // Internet Explorer
       {
       xmlDoc=new ActiveXObject("Microsoft.XMLDOM");
       xmlDoc.async=false;
       xmlDoc.loadXML(szReply); 
       }    
       
     alert(xmlDoc);
}


function xmlText()
{
     requestData("testXML", "");   
}

function emailLogin()
{
    var cEMail = document.getElementById("txtUserName");
    var cPass =  document.getElementById("txtPassword");
    requestData("emailLogin", "c=tools&email="+cEMail.value+"&pass="+cPass.value);   
}


function loginBtn()
{
    document.getElementById("loginMsg").innerHTML = ""; //Clear old msg.. 
    var szFunc = document.getElementById("func").value;
    var szEMail = document.getElementById("email").value;
    var szPass =  document.getElementById("password").value;
    //alert("Hi, about to log in or so....");
    
    var szCat = document.getElementById("cat").value;
    switch(szCat)
    {
        case "confirmresetpass":
        case "changepass":
            var szRetypePass = document.getElementById("repassword").value;
            var szCode = document.getElementById("code").value;
            //alert("Change password. Code = "+szCode);
            var szParam = "c=login&email="+szEMail+"&pass="+szPass+"&repass="+szRetypePass+"&code="+szCode;
            //prompt("Request:", "func=confchangepsw&"+szParam);
            requestData("confchangepsw",szParam);
            return false;
        case "":
            break;
        default:
            alert("Unknown login category: "+szCat);
    }
    
    switch (szFunc)
    {
        case "login":
            //alert("Login...");
            requestData("login", "c=tools&email="+szEMail+"&pass="+szPass);   
            break;
        case "register":
            //alert("Register...");
            var szRetypeEMail = document.getElementById("reemail").value;
            var szRetypePass =  document.getElementById("repassword").value;
            var szMsg = "";
            
            if (szRetypeEMail != szEMail)
                szMsg = "E-mail address ("+szRetypeEMail+"/"+szEMail+")";
            else
                if (szRetypePass != szPass)
                    szMsg = "Password";
                    
            if (szMsg.length)
            {
                var cLoginMsg  =  document.getElementById("loginMsg");
                cLoginMsg.innerHTML = '<font color="red"></b>'+szMsg+' retype mismatch.... Aborting.</b></font>';
                
                return false;
            }
            requestData("register", "c=tools&email="+szEMail+"&pass="+szPass+"&reemail="+szRetypeEMail+"&repass="+szRetypePass);
            break;
            
        default:
        //    alert("Func: "+szFunc);
            alert("Unknown login function...: "+szFunc);
    }
    //alert("after process...");
    //alert("Mail: "+cEMail.value+", pass: "+cPass.value);
    return false;
}

/*function sendLoginEmlBtn()
{
    var cEMail = document.getElementById("email");
    var cPass =  document.getElementById("password");
    
    if (!cEMail.value.length)
    {
        alert("E-mail address is not specified!");
        return;
    }
    
    if (cPass && cPass.value.length)
    {
        if (!userConfirmed("Password is specified! Press login button to login.. Sure you want to send email to login without password?"))
            return;  
    }
    else
        if (!userConfirmed("Do you want to send email to login without password?"))
            return;
    
    setInnerHtml("login_all", "<br><br><br><h2>Sending email with login details</h2>");
    
    requestData("emailLogin", "c=tools&email="+cEMail.value);   
}
*/

function action()
{
    alert("In the action");
}


function getWrapped(szTag, szParam)
{
    return "<"+szTag+">"+szParam+"</"+szTag+">";
}

function sendXml(szClass, szFunc, szXmlParams)
{
    var url = ajax_request_func()+"?c="+szClass+"&f="+szFunc+"&xml="+getWrapped("xml",szXmlParams);
    //alert("url: "+url);    
    var xmlhttp = getXML();
    xmlhttp.open("GET",url,true);
    xmlhttp.send();
    return false;
}
 

function test()
{
    
    var szThePointer = "test45456";//+"ikkedef";
    
    if (typeof window[szThePointer] === "undefined")
        alert("Var ikke definert!");
    else
        alert("Var definert");
    
    
    return;

    alert("About to initiate...");
    scheduler.config.xml_date="%Y-%m-%d %H:%i";
    scheduler.init('scheduler_here', new Date(),'month');
    alert("Initiated...");
    
var events = [
{id:1, text:"Meeting",   start_date:"04/11/2013 14:00",end_date:"04/11/2013 17:00"},
{id:2, text:"Conference",start_date:"04/15/2013 12:00",end_date:"04/18/2013 19:00"},
{id:3, text:"Interview", start_date:"04/24/2013 09:00",end_date:"04/24/2013 10:00"}
];
 
scheduler.parse(events, "json");//takes the name and format of the data source    
//    var str = "<div asdfasdf>777</div>";
//    var patt = new RegExp("/\\>(.*)\\<\\/");
    
    
//    alert("asdfasdf");
//    secretariat_test();
    //return userConfirmed("in test().... id: "+cForm.id);    
    //var szXml = getWrapped("id", 123)+getWrapped("name", "Hans")+getWrapped("størst","Hans er > enn Grethe");
    //sendXml("tools", "test", szXml);
//alert("Testing..");
}


function xmlRequest(szTxt, szFunc, szParams)
{
    //Designed for sending text back to php... check XmlCommand..
    //alert("in xmlRequest, func: "+szFunc+", params: "+szParams+", text: "+szTxt);
    requestData(szFunc, szParams+"&txt="+szTxt);
}

 

/* ********************** SECRETARIAT BELOW HERE **************************** */


var c=0;
var t;
var timer_is_on=0;
var chatid=0;
var timer_stopped=1;
var multiplyBy = parseInt(0);    //1=count, -1 count down... (timeout, break or match not started)
var szSuspendedList = "";    //#separated list


Array.prototype.remove= function(){
    var what, a= arguments, L= a.length, ax;
    while(L && this.length){
        what= a[--L];
        while((ax= this.indexOf(what))!= -1){
            this.splice(ax, 1);
        }
    }
    return this;
}

function get(szTag)
{
    var cFld = document.getElementById(szTag);
    if (cFld)
    {
        var v = cFld.innerHTML;
        return szTag+" = "+v+"<br>";
    }
    else
        return szTag+" not defined!<br>";
}


var szLoginCategory = "";
var bFormRequested = false;
var bLoggedIn = false;

function requestLogin()
{
    var szInProgress = cStoredVals["LoginInProgress"];
    
    if (szInProgress && szInProgress.localeCompare("Yes"))
    {
        alert("Login in progress.. aborting");
        return;
    }
    
    //Request login form from server
    if (bFormRequested)
    {
        //alert("Login form already requested..");
        return;
    }
    
    if (bLoggedIn)
    {
        //alert("Already logged in....");
        return;
    }
    
    bFormRequested = true;
    request("loginForm","c=login&cat="+szLoginCategory);
    //alert("Requested login ... (category: "+szLoginCategory+")");
}

function hasClass(cElem, szName) 
{
   return new RegExp('(\\s|^)'+szName+'(\\s|$)').test(cElem.className);
}
function addClass(cElem, szName)
{
   if (!hasClass(cElem, szName)) { cElem.className += (cElem.className.length ? ' ' : '') +szName; }
}
function removeClass(cElem, szName)
{
   if (hasClass(cElem, szName)) { cElem.className=cElem.className.replace(new RegExp('(\\s|^)'+szName+'(\\s|$)'),' ').replace(/^\s+|\s+$/g, '');}
}

function waitCursor(bWait)
{
    var cObj = document.body;
    //var cObj = window;
    
    cObj.style.cursor = (bWait ? 'wait' : '');      //260216 - testing

/*    if (bWait)
        addClass(cObj,"wait");
    else
        removeClass(cObj, "wait");
*/
}

//Set to initialize center of map
var nLon = 0;
var nLat = 0;
var pMap = null;
var nZoom = 0;
var szGoogleParams = "";
var szGoogleFunc = "";


function adjustImgObject(cImg)
{
    cImg.setAttribute('alt', 'na');
    cImg.setAttribute('height', '16px');
    var nScale = cImg.naturalWidth/cImg.naturalHeight; //141006
    if (isNaN(nScale))
        nScale = 1;
    var nWidth = Math.round(16 * nScale);
    cImg.setAttribute('width', ''+nWidth+'px');    
    var nBigWith = Math.round(64*nScale);
    //cImg.onmouseover = "this.width='"+nBigWith+"'; this.height='64'";
    cImg.setAttribute('onmouseover', "this.width='"+nBigWith+"'; this.height='64'");
    //cImg.onmouseout = "this.width='"+nWidth+"'; this.height='16'";
    cImg.setAttribute('onmouseout', "this.width='"+nWidth+"'; this.height='16'");
}

function getImgObject(szProfilePic)
{
    var cImg=document.createElement("img");
    cImg.setAttribute('src', szProfilePic);
    adjustImgObject(cImg);
    return cImg;
//    return '<img src="'+szProfilePic+'" '
//                + 'onmouseover="this.width=\'64\'; this.height=\'64\'" onmouseout="this.width=\'16\'; this.height=\'16\'"'
//                +'width="16" height="16">';
}

function getJsonParamArray(szJsonString)
{
    if (!szJsonString.length)
        return new Array();
        
    checkLoadJsonLibrary();    
    return window.JSON.parse(decoded(szJsonString));
}

function getJsonParamArrayFromCommand(cCommand)
{
    var szJson = getIfSet(cCommand, "json");
    return getJsonParamArray(szJson);
}

function deleteElement(szId)
{
    var cElement = document.getElementById(szId);
    cElement.parentNode.removeChild(cElement);    
}

function checkScreenRes()
{
    var nX = window.screen.availWidth;
    var nY = window.screen.availHeight;
    
    if (nX < 650)   //)//
    {
        //nX = 300;
        var cDiv = document.getElementById("leftHandSide");//"shortcutsmnu");
        cDiv.style.display = "none";
        cDiv = document.getElementById("adsarea");//"shortcutsmnu");
        cDiv.style.display = "none";
        
        var cArr = new Array("allrighthandside#0", "contenttbl#0", "tempbox#30", "chatcontainer#30", "chatCentr#30", "tempContainer#30","chatContBox#30");
        //Not yet filled: "actTableWrapper#0", 
        for (var n=0; n< cArr.length; n++)
        {
            var cFlds = cArr[n].split("#");
            cDiv = document.getElementById(cFlds[0]);
            if (cDiv)   //Not initially found for chatcontainer
            {
                var nNewWidth = nX + parseInt(cFlds[1]);
                cDiv.style.width = nNewWidth+"px";
                //alert("Changed: "+cFlds[0]);
            }
        }
        requestData('sys:initsmall','w='+nX+'&h='+nY);
    }
}

function adjustImgObj(cImg, szWorH, nPx)
{
    cImg.setAttribute('alt', 'na');
    var nScale = cImg.naturalWidth/cImg.naturalHeight; //141006
    if (isNaN(nScale))
        nScale = 1;

    var cDim = new Array();
    cDim[(!szWorH.localeCompare("W")?'width':'height')] = nPx;
    var nNew = Math.round(!szWorH.localeCompare("W")?nPx/nScale:nPx*nScale);
    cDim[(!szWorH.localeCompare("W")?'height':'width')] = nNew;
    
    cImg.setAttribute("width", ''+cDim["width"]+'px');
    cImg.setAttribute("height", ''+cDim["height"]+'px');

//    cImg.setAttribute('onmouseover', "this.width='"+cImg.naturalWidth+"'; this.height='".cImg.naturalHeight."'");
//    cImg.setAttribute('onmouseout', "this.width='"+nWidth+"'; this.height='16'");
    
}

function adjustImg(szId, szWorH, nPx)
{
    var cImg = document.getElementById(szId);
    adjustImgObj(cImg, szWorH, nPx);
}

function blankInNSecs(szDiv, nSecs)
{
    nSecs = parseInt(nSecs);
    if (nSecs)
        setTimeout(function(){blankInNSecs(szDiv,0)},nSecs*1000);   
    else
        document.getElementById(szDiv).innerHTML = "";
}

function handleDbError(reply)
{
    //Better handling should be implemented....
    reportError("", "", reply);                       
}
