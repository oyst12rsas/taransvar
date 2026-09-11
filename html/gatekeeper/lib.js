//For debugging xml content.. include ***FORCEDEBUG*** in the text...
var bDebugging = false;
var bDebugAjaxReply = false;//true; //false;    //true; //
var cStoredVals = new Array();

var szPrevCommand;  //Remove later.....
var cProfilePics = new Array();
var szReceivedError = "";

function reportError(szTable, nId, szMsg) //SystemMessage
{
//    var szParams = "c=tools&tbl="+szTable+"&id="+nId+"&msg="+szMsg;
//    requestData("reportError", szParams);


    //szMsg = szMsg.replace(/<(?:.|\n)*?>/gm, '');
    //szMsg = szMsg..replace(/<\/?(font|div|img|p...)\b[^<>]*>/g, "")
    var nNdx = szMsg.indexOf("</span>");
    if (nNdx)
    {
        szMsg = szMsg.substr(nNdx+7, 255);
    }

    szReceivedError = szReceivedError+szMsg;
}

function userWarning(szMsg)
{
    reportError("N/A",0,"Message to user: "+szMsg);
    alert(szMsg);    
}


function ajaxReplyReceived(reply)
{
    if (reply.indexOf("promo|")>0)
        return;
         
    if (reply.indexOf("<xml><commands><command><what>nothing</what></command></commands></xml>")>0)
        return;         
                   
    if (!bDebugAjaxReply) 
       return;
        
    cCheck = document.getElementById("dbgajaxifcontain");
    if (cCheck && reply.indexOf(cCheck.value))
        alert("Text found: "+cCheck.value+"-"+reply+"-"+decodeURIComponent(reply));    
    else
        alert("Not found in: -"+reply+"-"+decodeURIComponent(reply));    
        
}

function toggleDebugAjaxReply()
{
    bDebugAjaxReply = !bDebugAjaxReply;
    alert("Debug turned "+(bDebugAjaxReply ? "on" : "off"));
}

function setInnerHtml(szId, szContent)
{
    cFld = document.getElementById(szId);
    if (cFld)
        cFld.innerHTML = szContent;
    else
        if (bDebugging)
            alert("Fld not found..");
    //    else
    //        alert("Not debugging so shouldn't display....");
}

function addToInnerHtml(szId, szContent)
{
    cFld = document.getElementById(szId);
    if (cFld)
        cFld.innerHTML = cFld.innerHTML+szContent;
    else
        if (bDebugging)
            alert("Fld not found..");
    //    else
    //        alert("Not debugging so shouldn't display....");
}


function change(cFld, szFunc, szGetParams, szJson)
{
        //140911
    var cJson = getJsonParamArray(szJson);                
    cJson["newVal"] = cFld.value;
    var cParams = szGetParams.split(",");
    var cFound;
    for(var n=0;n<cParams.length;n++)
    {
        cFound = getFieldSameRowAllTypes(cFld,cParams[n]);
        
        if (!cFound)
            cFound = document.getElementById(cParams[n]);
            
        if (cFound)
        {
            var szVal;
            if (!cFound.tagName.localeCompare("INPUT"))
                szVal = cFound.value;
            else
                szVal = cFound.innerHTML;
                
            cJson[cParams[n]] = szVal;
        }
    }
    requestData(szFunc, "json="+JSON.stringify(cJson));
}

function userConfChange(cFld, szPrevVal, szFunc, szGetParams, szJson, szMsg)
{
    //140911
    if (!userConfirmed(szMsg))
    {
        cFld.value = szPrevVal;
        return;
    }

    change(cFld, szFunc, szGetParams, szJson);
}

function classTimer(szClassName)
{
   //global bGlobalPolling;
	var bGlobalPolling = globalPolling();
    //reportError("",0,"Testing testin... reportError..");
    
    if (false)//!bGlobalPolling) //Global
        //140529 - DEBUGGING
        return;
 //   else
 //       alert("Timer called");

    doRequestData("polling","");
    
    return;    
    
    //Check if notifications are disabled (140425)                                                             
    if ("notificationsHidden" in cStoredVals)
    {
        //alert("Notifications are disabled");
        return;
    }

    if (!("notiCount" in cStoredVals))
        cStoredVals["notiCount"] =0;
        
    cStoredVals["notiCount"] +=1;        
    var cJsonArr = new Array();
    
    if (cStoredVals["notiCount"] == 1)
        cJsonArr.push(new Array("cart","\"ÆØÅ\" 'æøå'"));  //141021 
    
    //Initiate by printing: var myVar=setInterval(function(){classTimer("classname")},1000);
    //alert("Timer!!!: "+szClassName);
    
    var szNotiList = szClassName+":timer";
    var szNotiParams = "";
    //requestData("timer", "c="+szClassName);
    
    //140529 - DEBUGGING...  REMOVED TIMER
    if (bGlobalPolling)//!szClassName.localeCompare("tools"))
    {
        
        //<table id="notitable"> style="display:none">
        var cTbl = document.getElementById("notitable");
        var szReq = "";
        
        //151221 May be correct... full view calendar entry...
        //if (!cTbl)
        //    return; //Calendar??
        
        if (cTbl)
        {
            for (var n=0; n<cTbl.rows.length; n++)
            {
                var sz2first = cTbl.rows[n].id.substring(0,2);
                if (!sz2first.localeCompare("sw"))
                {
                    szReq = cTbl.rows[n].id;
                    break;
                }
            }
        }
        else
            szReq="";
            
        //requestData("notis", "c=noti&cur="+szReq);
        szNotiList += "|noti:notis";
        
        szNotiParams += (szNotiParams.length?"&":"")+"cur="+szReq;
        
        cTbl = document.getElementById("freqtable"); //frndreqtable
        var szReq;
        var szCur = "";
        
        if (cTbl)
        {
            for (var n=0; n<cTbl.rows.length;n++)
            {
                var cRow = cTbl.rows[n];
                szId = cRow.children[0].innerHTML;
                var res = /<div.*>(.*?)<\/div>/.exec(szId);
                szCur = szCur + (szCur.length?",":"")+res[1];
            }
            
            szNotiList += "|friends";
            szNotiParams += (szNotiParams.length?"&":"")+"curf="+szCur;

            if (cStoredVals["notiCount"]==1)
                szNotiList += "|groups";
        }

        szNotiList += "|system";
        
        if (cStoredVals["notiCount"]==1)
            szNotiList += "|noti:initial";
        
        //requestData with no wait cursor:
        
        if (cJsonArr.length)                                     
            szNotiParams += (szNotiParams.length?"&":"")+"json="+encodeURIComponent(JSON.stringify(cJsonArr));//141021
        var szFullParamList = "notis="+szNotiList+"&"+szNotiParams;    
        doRequestData("noti:timers",szFullParamList);
//	alert(szFullParamList);
    }
	else
	alert("Not globally polling!");
        
}

function request(szFunc, szParams)
{
    //alert("Func: "+szFunc+", params: "+szParams);
    requestData(szFunc, szParams);    
}

function classFunc(cFld, szFunc)
{
    //Used by search fields...
    var szParams = "id="+cFld.id+"&val="+cFld.value;
    //alert(szParams);
    classRequest(szFunc, szParams );
    //alert("her2");
    return 1;
}

function formClassFunc(cFld, szFunc)
{
    var szCmd = "";
    //Used by search fields...
    if (szFunc.substring(0,5) == "send:")
    {
       // alert("send command discovered");
        var cFlds = szFunc.split("^");
        cForm = findForm(cFld);

        szFunc=cFlds[0].substring(5);
        
        for (var i=1;i<cFlds.length;i++)
        {
            
            szFld = cFlds[i];
            for(var f=0; f<cForm.elements.length; f++)
                if (cForm.elements[f].name== szFld)
                {
                    var szVal=cForm.elements[f].value;
                    szCmd += szFld+"="+ szVal+"&";
                }
        }
    }
    else
        szCmd = "id="+cFld.id+"&val="+cFld.value
        
   //alert("Func: "+szFunc+", Cmd: "+szCmd);
    formClassRequest(cFld, szFunc, szCmd);
    return 1;
}

function CreateMSXMLDocumentObject () 
{
    if (typeof (ActiveXObject) != "undefined") {
        var progIDs = [
                        "Msxml2.DOMDocument.6.0", 
                        "Msxml2.DOMDocument.5.0", 
                        "Msxml2.DOMDocument.4.0", 
                        "Msxml2.DOMDocument.3.0", 
                        "MSXML2.DOMDocument", 
                        "MSXML.DOMDocument"
                      ];
        for (var i = 0; i < progIDs.length; i++) {
            try { 
                return new ActiveXObject(progIDs[i]); 
            } catch(e) {};
        }
    }
    return null;
}

function BuildXMLFromString(text) 
{
    var message = "";
    if (window.DOMParser) { // all browsers, except IE before version 9
        var parser = new DOMParser();
        try {
            xmlDoc = parser.parseFromString (text, "text/xml");
        } catch (e) {
                // if text is not well-formed, 
                // it raises an exception in IE from version 9
           // alert ("XML parsing error: .-"+text+"-");  Normal with non-xml style commands...
            return false;
        };
    }
    else {  // Internet Explorer before version 9
        xmlDoc = CreateMSXMLDocumentObject ();
        if (!xmlDoc) {
            alert ("Cannot create XMLDocument object");
            return false;
        }

        xmlDoc.loadXML (text);
    }

    var errorMsg = null;
    if (xmlDoc.parseError && xmlDoc.parseError.errorCode != 0) {
        errorMsg = "XML Parsing Error: " + xmlDoc.parseError.reason
                  + " at line " + xmlDoc.parseError.line
                  + " at position " + xmlDoc.parseError.linepos;
    }
    else {
        if (xmlDoc.documentElement) {
            if (xmlDoc.documentElement.nodeName == "parsererror") {
                //Opera goes here if old format...
                //errorMsg = xmlDoc.documentElement.childNodes[0].nodeValue+"-"+text+"-";
                return false;
            }
        }
        else {
            errorMsg = "XML Parsing Error! -"+text+"-";
        }
    }

    if (errorMsg) {
        alert (errorMsg);
        return false;
    }

    //alert ("Parsing was successful!");
    return xmlDoc;
}

function handleReceivedAjaxText(reply)
{
    //if (reply.indexOf("***FORCEDEBUG***")>-1)
    //    alert("***FORCEDEBUG*** found, so: x"+reply+"x");
        
    ajaxReplyReceived(reply);
    var xmlDoc = BuildXMLFromString(reply);
    
    //160409
    /*var currentdate = new Date();
    var datetime = "Last Sync: " + currentdate.getHours() + ":"     + currentdate.getMinutes() + ":" + currentdate.getSeconds();
    var szDebug = reply.substring(0,100).replace("<","&lt;");
    szDebug = szDebug.replace(">","&gt;");
    var szTxt = datetime + szDebug;
    var cDebug = document.getElementById("debug");
    if (cDebug)
        cDebug.innerHTML = szTxt;
    */
    
    if (xmlDoc === false)
    {
        if (typeof(std_ajax_reply) == "function")
            std_ajax_reply(reply);
        else
            alert("Handling of ajax reply not implemented...")
        return;
    }

/*                if (window.DOMParser)
       {
       parser=new DOMParser();
       xmlDoc=parser.parseFromString(reply,"text/xml");
       }
     else // Internet Explorer
       {
       xmlDoc=new ActiveXObject("Microsoft.XMLDOM");
       xmlDoc.async=false;
       xmlDoc.loadXML(reply);
       }                
*/                 
    if (xmlHandled(xmlDoc))
        return;


    
/*                var n=reply.search("xml>");
    var szXmlTest = reply.substr(0,5);
    if (szXmlTest == "<xml>" || n==7)   //NOTE! no idea why it's 7....
    {
        parseXmlReply(reply);
        return;
    }*/

    if (typeof(std_ajax_reply) == "function")
        std_ajax_reply(reply);
    else
        alert("Handling of ajax reply not implemented...")
}
        
        
function getXML()
{
    var xmlhttp;
    if (window.XMLHttpRequest)
    {// code for IE7+, Firefox, Chrome, Opera, Safari
        xmlhttp=new XMLHttpRequest();
    }
    else
    {// code for IE6, IE5
        xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
    }
    xmlhttp.onreadystatechange= function ()
        {
            if (xmlhttp.readyState==4)
            {
                if (xmlhttp.status==200)
                {
 //               var xml = xmlhttp.responseXML;
                
 //               if (xmlHandled(xml))
 //                   return;
                
                    var reply = xmlhttp.responseText;
                    handleReceivedAjaxText(reply);
                }
                else
                {
                    if(xmlhttp.status == 500)   //404 = file not found, 500 = Internal server error
                    {
                        var reply = xmlhttp.responseText;
                        //alert(reply);
                        szReceivedError = reply;
                    //do something
                    }
                    else
                    {
                        //alert("Annen feil....");
                        szReceivedError = "Other error (JS, "+xmlhttp.status+"): "+xmlhttp.responseText;
                    }
                }
            }
            else
            {
                0;
                //noop();
            }
        }  
  return xmlhttp;
}

function doRequestData(szEvent, szParam)
{
    //To allow requesting data without wait cursor...

    //Add modus parameters
    cJson = new Array;
    
    //Print modus
    if (typeof(bPrintModus)!=="undefined")
        //cJson.push({printMode:bPrintModus});
        //cJson["printMode"] = bPrintModus;
        cJson = {printMode:bPrintModus};
        
    cJson["loggedId"] = (typeof cStoredVals["loggedIn"] === 'undefined'?0:cStoredVals["loggedIn"]);  //160704: Add what's in: cStoredVals["loggedIn"] = 1;

    //151221
    cParJson = Object.assign(cJsonParam,cJson);
    
    var szJson = "&modjson="+JSON.stringify(cParJson);

    //Should start using sendXml() instead.... 
    var xmlhttp = getXML();
    
    var cSysStatus = document.getElementById("sysStatus");
    var szSysStatus = "";//(cSysStatus?cSysStatus.innerHTML:"N/A"); 150215
    
   url = ajax_request_func()+"?func="+szEvent+"&"+szParam+szJson;
    //alert("url: "+url);    
    xmlhttp.open("GET",url,true);
    xmlhttp.send();
    return false;
}

function newRequestData(szEvent, szParam)    
{    
    var xmlhttp;
    if (window.XMLHttpRequest)
    {// code for IE7+, Firefox, Chrome, Opera, Safari
        xmlhttp=new XMLHttpRequest();
    }
    else
    {// code for IE6, IE5
        xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
    }
    xmlhttp.onreadystatechange= function ()
        {
            if (xmlhttp.readyState==4)
            {
                if (xmlhttp.status==200)
                {
 //               var xml = xmlhttp.responseXML;
                
 //               if (xmlHandled(xml))
 //                   return;
                
                    var reply = xmlhttp.responseText;

                    if (reply.indexOf("uncaught-exception") > 0)    //200323
                    {   
                        if (reply.indexOf("</span>") > 0)    //200323
                            reply = reply.substr(reply.indexOf("</span>")+7,255)

                        //reportError("", "", reply);
                        requestData("rptError", "msg="+encodeURI(reply)); //AN_ERROR_HAS_OCCURRED_BUT_HTML");//

                    }
                    else
                        handleReceivedAjaxText(reply);
                }
                else
                {
                    if(xmlhttp.status == 500)   //404 = file not found, 500 = Internal server error
                    {
                        var reply = xmlhttp.statusText;
                        //alert(reply);
                        szReceivedError = reply;
                    //do something
                    }
                    else
                        {
                            //php file not found gives 404
                            var reply = "Status: "+xmlhttp.status+"\nText: "+xmlhttp.statusText+"\nResponse: "+xmlhttp.responseText;
                            //alert(reply);
                            szReceivedError = "Other error (JS): "+reply;

                        }
                }
            }
        }  

    
    var szPhp = "ajaxtest.php";// ajax_request_func();
    url = szPhp+"?func="+szEvent+"&"+szParam;
    xmlhttp.open("GET",url,true);
    xmlhttp.send();
    return false;

}






function requestData(szEvent, szParam)
{
    waitCursor(1);
    doRequestData(szEvent, szParam);
    waitCursor(0);
}

function classRequest(szFunc, szParams)
{
    cClassFld = document.getElementById("cl");
    if (!cClassFld)
    {
        alert("Class not specified! Aborting");
        return;    
    }
    
    szClass = cClassFld.value;
    //alert("Class: "+szClass);
    szPar = "cl="+szClass+"&"+szParams;
    //alert(szPar);
    requestData(szFunc, szPar);
}   

function formClassRequest(cChildFld, szFunc, szParams)
{
    cForm = findForm(cChildFld);
    //cForm = cChildFld.form;
    if (!cForm)
    {
        alert("No form");
      /*  asdf
        cParent = cChildFld.parentNode;
        alert("Parent:"+cParent.id);*/
        return;
    }
    szClass = cForm.id;
    //alert("Class: "+szClass);
    szPar = "cl="+szClass+"&"+szParams;
    //alert("Func: "+szFunc+", "+szPar);
    requestData(szFunc, szPar);
}   

    
function findTable(szTablename)
{
    var cTableDiv = document.getElementById(szTablename);
    
    if (!cTableDiv)
        return false;
        
    if (cTableDiv.tagName == "div")
    {            //sjekk om tabellen allerede.... ellers finn tabell i div-tag...
        var cTables = cTableDiv.getElementsByTagName("table");
        if (cTables.length)
            alert("Table found..");
        
        return cTables[0];
    }
    else
        return cTableDiv;
}

function handlePrompt(cCommand)
{
    var szDefault = decoded(getIfSet(cCommand, "default"));
    var reply = prompt(decoded(getIfSet(cCommand, "msg")),szDefault);

    if (reply!=null)
    {
        var szFunc = getIfSet(cCommand, "requestFunc");
        if (szFunc.length)
        {
            var szJson = decoded(getIfSet(cCommand, "json"));
            var szParam = "c=tools&txt="+reply+"&json="+szJson;
            requestData(szFunc, szParam);        
        }
    }
}

function handleAddTableRow(cCommand)
{
    var szTablename = cCommand.getElementsByTagName("tablename")[0].textContent;
    var szWhere = cCommand.getElementsByTagName("where")[0].textContent;
    var szKey = cCommand.getElementsByTagName("key")[0].textContent;
    var cCode = cCommand.getElementsByTagName("code")[0];
    var cClass = cCommand.getElementsByTagName("class")[0];
    var cId = cCommand.getElementsByTagName("id")[0];
    
    var cTable = findTable(szTablename);
    
    if (!cTable)
    {
        alert("Table not found: "+szTablename);
        return;
    }
    var nRow = 0;
    var cRow = false;
    
    if (szKey.length)
    {
        for (var n = 0; n < cTable.rows.length; n++)
            if (!cTable.rows[n].id.localeCompare(szKey))
            {
                //Already exists.
                cRow = cTable.rows[n]; //ØT 260304 - was row, not rows
                cRow.innerHTML = "";
                break;
            }
    }
    
    if (!cRow)  //Key not specified or not found
    {    
        //Check if table heading first
        if (szWhere == "top")
        {
            if (cTable.rows.length)
            {
                var cFirstRow = cTable.rows[0];
                var cFirstCol = cFirstRow.children[0];

                if (cFirstCol.nodeName == "TH")
                    nRow = 1;
            }
        }
        else
            nRow = cTable.rows.length;
                
        cRow = cTable.insertRow(nRow);  

        if (cId)
            cRow.id = cId.textContent;    //140929
    
        if (cClass)
        {
            cRow.className = cClass.textContent;
        }
    }
        
    var cFields;
       
    if (cCode)     
        cFields = cCode.getElementsByTagName("td");
    else
        cFields = new Array();

    for (var n=0; n<cFields.length; n++)
   {        
        var cCell = cRow.insertCell(cRow.cells.length);
        var szTxt = cFields[n].textContent;
        szTxt = szTxt.replace('%D8', "&Oslash;");  //Generates exception in Crome..
        szTxt = szTxt.replace('%E5', "&aring;");  //Generates exception in Crome..
        
        var szDecoded
        try {
            szDecoded = decodeURIComponent(szTxt);//.replace(/\+/g,  " "));
        }
        catch (e)
        {
            reportError("", "", "decodeURIComponent() failed on "+cFields[n].textContent);
            szDecoded = "Error: "+cFields[n].textContent;
        }
        cCell.innerHTML = szDecoded;
    }
}

function handleAddTableCol(cCommand)
{
    var szTablename = cCommand.getElementsByTagName("tablename")[0].textContent;
    var nRow = cCommand.getElementsByTagName("row")[0].textContent;
    var cCode = cCommand.getElementsByTagName("code")[0].textContent;
    var szVAlign = cCommand.getElementsByTagName("valign")[0].textContent;

    if (!szVAlign.length)
        szVAlign = "top";
    
    var cTable = findTable(szTablename);
    var nRow = 0;
    var nRows;
    if (cTable.rows)
        nRows = cTable.rows.length;
    else
        nRows = 0;
    
    //Find the correct row
    if (nRow >= 0 && nRow < nRows)
    {
        var cTheRow = cTable.rows[nRow];
        var cCell = cTheRow.insertCell(cTheRow.cells.length);
        cCell.vAlign = szVAlign;
        var szDecoded;
        try {
            szDecoded = decodeURIComponent(cCode.replace(/\+/g,  " "));
        }
        catch(e)
        {
            reportError("", "", "decodeURIComponent() fail to convert: "+cCode);
            szDecoded = "Error: "+cCode;
        }
        //alert(szDecoded);
        cCell.innerHTML = szDecoded;
    }
    else
        alert("Unable to insert new column!");
}

function deleteRowFromTable(szTablename, nRow)
{
    var cTable = findTable(szTablename);
    
    if (!cTable)
        return;
    
    if (cTable.rows.length > nRow)    
    {
        cTable.deleteRow(nRow);
        return true;
    }
    else
        return false;    
}

function deleteRow(cCommand)
{
    //140930
    var szRowId = getIfSet(cCommand, "rowId");
    
    if (szRowId.length)
    {
        var cRow = document.getElementById(szRowId);
        cRow.parentNode.removeChild(cRow);
        
        //If fails: 
        //var cTable = row.parentNode;
        //while ( cTable && cTable.tagName != 'TABLE' )
        //    cTable = cTable.parentNode;
    
        //if ( !cTable )
        //    return;
        //cTable.deleteRow(row.rowIndex);
        
        return;        
    }
    
    var szTblName = cCommand.getElementsByTagName("tablename")[0].textContent;
    var nRow = cCommand.getElementsByTagName("row")[0].textContent;
    deleteRowFromTable(szTable, nRow);
    //alert("deleteRow is not yet ready");
}

function deleteColumnContainingId(cCommand)
{
    //alert("Not yet learned how to delete column containing id...");
    var szTblName = cCommand.getElementsByTagName("tablename")[0].textContent;
    var szId = cCommand.getElementsByTagName("id")[0].textContent;
    var cTarget = document.getElementById(szId);

    //Find column
    var cParent;
    while (cParent = cTarget.parentNode)
    {
        if (cParent.tagName == "TD")
        {
            //TD found.. 
            var cRow = cParent.parentNode;
            for (var n = 0; n < cRow.cells.length; n++)
                if (cRow.cells[n] == cParent)
                {
                    cRow.deleteCell(n);
                    return;
                }
        }
        
    }    
    alert("Unable to delete cell...");
}


function withRowContainingId(szId, szWhat)
{
    while (true)
    {
        var cTarget = document.getElementById(szId);

        if (!cTarget)
            return;

        var cTable = getTable(cTarget);
        switch (szWhat)
        {
            case "delete":
                cTable.deleteRow(rowNumber(cTarget));
                break;
            case "hide":
                cTable.rows[rowNumber(cTarget)].style.display = "none";
                return; //Infinite loop if just breaks... 
            case "show":
                cTable.rows[rowNumber(cTarget)].style.display = "";
                return; //Infinite loop if just breaks... 
            case "green":
                cTable.rows[rowNumber(cTarget)].className = "green";
                return;
            default:
                reportError("N/A",0,"Unknown what in withRowContainingId(): "+szWhat);            
        }
    }
}

function deleteRowContainingId(cCommand)
{
    var szId = cCommand.getElementsByTagName("id")[0].textContent;
    withRowContainingId(szId, szWhat = "delete");
}

function emptyTable(cCommand)
{
    var cFld = cCommand.getElementsByTagName("tablename")[0];
    while (deleteRowFromTable(cFld.textContent, 0));
}


function getIfSet(cCommand, szTag)
{
    var cFlds = cCommand.getElementsByTagName(szTag);
    
    if (cFlds.length)
        return cFlds[0].textContent;
    else
        return "";
}

function manuallyDecoded(szEncoded)
{
    //return "Was error... manually handled...";
    var szDecoded = "";
    
    for (var n=0; n<szEncoded.length; n++)
    {
        var szChar = szEncoded.substring(n,n+1);
        
        if (szChar == "%")
        {
            var szStr = szEncoded.substring(n,n+3);
            var szTrans;

            try {
                szTrans = decodeURIComponent(szStr); 
            }
            catch (e)
            {
                //reportError("", "", "decodeURIComponent() failed on "+szEncoded);
                //szValue = "Error: "+szEncoded;
                szTrans = "[error: "+szStr+"]";
            }

            n += 2;
            szDecoded = szDecoded.concat(szTrans);               
        }
        else
            szDecoded = szDecoded.concat(szChar);
    }
    
    return szDecoded;
}

function getDisplayDate(cDate)
{
    //return cDate.toString("dd.mm.yy");
    var day = cDate.getDate();
    var month = cDate.getMonth() + 1; //months are zero based
    var year = cDate.getYear()-100;
    
    var ONE_DAY = 1000 * 60 * 60 * 24
    // Convert both dates to milliseconds
    var cNow = new Date();
    //cNow.setHours(cNow.getHours()+7);
    cNow.setHours(0,0,0,0);
    var nMillisec = cDate.getTime() - cNow.getTime();
    if (nMillisec >= 0)
    {
        var nDays = Math.round(nMillisec/ONE_DAY);
        switch(nDays)
        {
            case 0:
                return "I dag ("+day+"."+month+")";
            case 1:                                  
                return "I morgen ("+day+"."+month+")";
            default:
                if (nDays < 7)
                {
                    var $cDayName = ["Søn","Man","Tirs","Ons","Tors","Fre","Lør"];
                    return $cDayName[cDate.getDay()]+"("+day+".)";
                }
                if (nDays < 250)
                    return day+"."+month;
        }
    }

    return (day<10?"0":"")+day+"."+(month<10?"0":"")+month+"."+year;
}

function convertToDate(szT)
{
    var t = szT.match(/^(\d{4})\-(\d{2})\-(\d{2})$/);
    if(t==null)
        return null;
    var d=+t[3], m=+t[2], y=+t[1];
    return new Date(y,m-1,d);
}

function decoded(szEncoded)
{
    try {
        szValue = decodeURIComponent(szEncoded);
    }
    catch (e)
    {
        //reportError("", "", "decodeURIComponent() failed on "+szEncoded);
        //szValue = "Error: "+szEncoded;
        szValue = manuallyDecoded(szEncoded);
    }
    
    var szTxt = "";        
    var cArray = szValue.split(/\{JSENCODEDATE\:(.*)\}/g);
        
    for (var n= 0; n < cArray.length; n++)
    {
        var szT = cArray[n];
        var cDate = convertToDate(szT);
        
        if(cDate !== null)
            szTxt += getDisplayDate(cDate);
        else
            szTxt += szT;            
    }
    
    return szTxt;
}

function handleSetValue(cCommand, bAdd)
{
    var szDropIfExists = getIfSet(cCommand, "dropifexists");
    if (szDropIfExists.length)
    {
        var cFound = document.getElementById(szDropIfExists);

        if (cFound)
            return;
    }
    
    var szWhat = getIfSet(cCommand, "what");
    var szId = getIfSet(cCommand, "id");
    
    if (szId == "topIns")
        szId = "topIns";
    var szName = getIfSet(cCommand, "name");
    var szEncoded = getIfSet(cCommand, "value");
    var szWhere = getIfSet(cCommand, "where");
    var szOnDuplicates = getIfSet(cCommand, "onDuplicates");    //add(default)/omit
    var szWarnIfNotFound = getIfSet(cCommand, "warnIfNotFound");    //1 or 0 or not def..
    var szStyle = getIfSet(cCommand, "style");
    
    var szValue = decoded(szEncoded);
    
    if (szId.length)
        cFld = document.getElementById(szId);
    else
        cFld = document.getElementsByName(szName)[0];
        
    if (!cFld)
    {
        if (!szWarnIfNotFound.localeCompare("1"))
            alert("Field not found or field id specified wrong: "+szId);
        return;
    }
    
    //Check if value exist...
    var szCurval;
    if (szWhat.indexOf("Value")>0)
        szCurval = cFld.value;
    else
        szCurval = cFld.innerHTML;
            
    if (szOnDuplicates.indexOf("omitIfContains") == 0)// == "omit")
    {
        var cParts = szOnDuplicates.split("^");
        if (szCurval.indexOf(cParts[1]) >= 0)
        {
            //alert("Text already exists in "+szId+"/"+szName+". Skipping...");
            return;
        }   
        //else
        //{
        //    var cTemp = document.getElementById("tempbox");
        //    cTemp.innerHTML = "{--"+szValue+"--}<br><br>NOT FOUND IN<br><br>{--"+szCurval+"--}";
        //}
    }
    
    var szAddTo = "";
    
    
    if (szStyle.length)
    {
        var cElem = szStyle.split(":");
        if (cElem.length > 1)
            if (cElem[0]=="display")
                cFld.style.display = cElem[1];
    }
    
    
    if (bAdd)
        szAddTo = szCurval;            
    
    if (szWhat == "setValue")
    {
       if (szWhere == "top") 
        cFld.value = szValue+szAddTo;
       else
        cFld.value = szAddTo+szValue;
    }
    else
    {
       if (szWhere == "top") 
        cFld.innerHTML = szValue+szAddTo;
       else
        cFld.innerHTML = szAddTo+szValue;
    }
}

function handleAjaxRequest(cCommand)
{
    var szUrl = getIfSet(cCommand, "url");
    var szDecoded = decodeURIComponent(szUrl.replace(/\+/g,  " "));
    
    xmlhttp = getXML();
    xmlhttp.open("GET",szDecoded,true);
    xmlhttp.send();
    return false;
}

function handleStdAjaxRequest(cCommand)
{   //stdAjaxRequest
    var szFunc = decodeURIComponent(getIfSet(cCommand, "func"));
    var cRequests = szFunc.split("#");    
//    if (szFunc.indexOf("#")>0)
//        alert("List of requests:"+szFunc);

    if (cRequests.length > 1)
        for (var i=0;i<cRequests.length;i++)
        {
            var szRequest = cRequests[i];
            requestData(szRequest, "");
        }    
    else
    {
        var szParams = getIfSet(cCommand, "params");
        var szDecoded = decodeURIComponent(szParams.replace(/\+/g,  " "));
        requestData(szFunc, szDecoded);
    }
}

function handleJavaScriptRequest(cCommand)
{
    var nDelay = parseInt(getIfSet(cCommand, "delay"));
    if (nDelay)
    {
        //150202 
        var cFlds = cCommand.getElementsByTagName("delay");
        
        if (cFlds.length)
            cFlds[0].textContent = "0";
        else
        {
            alert("Error!");
            return;
        }
        
        setTimeout(function (){ handleJavaScriptRequest(cCommand);}, nDelay * 1000);
        return;
    }
    
    var szScript = getIfSet(cCommand, "script");
    szScript = decodeURIComponent(szScript);
    var cParams = cCommand.getElementsByTagName("params")[0];
    
    var cParamArr = cParams.getElementsByTagName("param");

    switch (cParamArr.length)
    {
        case 0:
            window[szScript]();
//            alert("Should start "+szScript);
            break;
        case 1:
            window[szScript](decodeURIComponent(cParamArr[0].textContent));
            break;
        case 2:
            window[szScript](decodeURIComponent(cParamArr[0].textContent),decodeURIComponent(cParamArr[1].textContent));
            //alert("Script: "+szScript+", params: "+decodeURIComponent(cParamArr[0].textContent)+" and "+decodeURIComponent(cParamArr[1].textContent));
            break;
        case 3:
            window[szScript](decodeURIComponent(cParamArr[0].textContent),decodeURIComponent(cParamArr[1].textContent),
                decodeURIComponent(cParamArr[2].textContent));
            break;
        case 4:
            window[szScript](decodeURIComponent(cParamArr[0].textContent),decodeURIComponent(cParamArr[1].textContent),
            decodeURIComponent(cParamArr[2].textContent),decodeURIComponent(cParamArr[3].textContent));
            break;
        case 5:
            window[szScript](decodeURIComponent(cParamArr[0].textContent),decodeURIComponent(cParamArr[1].textContent),decodeURIComponent(cParamArr[2].textContent),
            decodeURIComponent(cParamArr[3].textContent),decodeURIComponent(cParamArr[4].textContent));
            break;
        case 6:
            window[szScript](decodeURIComponent(cParamArr[0].textContent),decodeURIComponent(cParamArr[1].textContent),
            decodeURIComponent(cParamArr[2].textContent),decodeURIComponent(cParamArr[3].textContent),
            decodeURIComponent(cParamArr[4].textContent),decodeURIComponent(cParamArr[5].textContent));
            break;
        default:
            alert("Unhandled number of parameters: "+cParamArr.length);
    }
}

function setDisplay(cCommand)
{
    var szId = getIfSet(cCommand, "id");
    var szDisplay = getIfSet(cCommand, "display");
    var cTarget = document.getElementById(szId);
    if (cTarget)
        cTarget.style.display = szDisplay;
    else
    {
        var szWarn = getIfSet(cCommand, "warnIfNotFound");
        
        if (!szWarn.localeCompare("1"))
        {
            reportError("N/A",0,"Field "+szId+" not found! Error msg to user");
            alert("An error has occurred and has been reported to our support team.");       //140622 - better warning...
        }
    }
}

function chatWith()
{
    var szId = getIfSet(cCommand, "id");
    if (szId+0)
        jqcc.cometchat.chatWith(szId);
    else
        alert("No user specified for chat");
}

function moveRowWithDivToTop(cTbl, szSection, bHideOthers)        
{
    var cDiv = document.getElementById(szSection);
    
    if (!cDiv)
        return;
        
    //alert("Found "+szSection);
    var cRow = cDiv.parentNode;
    while (cRow.tagName != "TR")
        cRow = cRow.parentNode;
        
    var szCode = cRow.innerHTML;
    //cRow.innerHTML = "&nbsp;";
    
    if (!cTbl)
    {
        //alert("Looking for table..");
        cTbl = getTable(cRow);
    }
        
    cTbl.deleteRow(rowNumber(cRow));
    
    var cNewRow = cTbl.insertRow(0);
    cNewRow.innerHTML = szCode;        

    if (false)//bHideOthers)    //140123
    {
        for (var n = 1; n < cTbl.rows.length; n++)
        {
            //cTbl.rows.style.visibility = 'hidden';
            cTbl.rows[n].style.display = "none";

        }
        dbg("Hidden rows for "+cTbl.id+"<br>");
    }
    //Hide other rows.
}

function moveToTop(szSection)
{
    var cTbl = document.getElementById("contenttbl");
    
    if (!cTbl)
        return;

    moveRowWithDivToTop(cTbl, szSection, bHideOthers = true)        
 
    //Move the corresponding menu section to top (in left hand menu)
    var cTbl = document.getElementById("menues");
    
    if (!cTbl)
        return;

    moveRowWithDivToTop(cTbl, szSection+"mnu", !bHideOthers); 
    scroll(0,0);
}

function xmlCmdMoveToTop(cCommand)
{
    var szId = getIfSet(cCommand, "id");
    moveToTop(szId);
}

function getPosition(cElem) 
{
    var xPos = 0;
    var yPos = 0;
  
    while(cElem) 
    {
        xPos += (cElem.offsetLeft - cElem.scrollLeft + cElem.clientLeft);
        yPos += (cElem.offsetTop - cElem.scrollTop + cElem.clientTop);
        cElem = cElem.offsetParent;
    }
    return { x: xPos, y: yPos };
}

function displayPerson(cCommand)
{
    var szFormTag = getIfSet(cCommand, "formtag");
    var szCode = getIfSet(cCommand, "code");
    var szDecoded = decoded(szCode);
    //dbg(decoded(szCode));
    var szMoveTo = getIfSet(cCommand, "moveto");
    var nId = getIfSet(cCommand, "id");
    var nScrollTo = getIfSet(cCommand, "scrollto");
    var cExistsInTable = false;
    var nRow = -1;
    var bChatFound = false;

    //Check if form found in chat central
    //140930
    var szChatDivId = "chatPal"+nId;
    var cChatFound= document.getElementById(szChatDivId);

    var cPhoneBkTbl = document.getElementById("phonebktbl");
    
    //Find and delete the existing record..    
    var cFormFound = document.getElementById(szFormTag);
    if (cFormFound && cPhoneBkTbl)
    {
        cExistsInTable = getTable(cFormFound);
        nRow = rowNumber(cFormFound);//cExistsInTable);

        if (cFormFound && szMoveTo.length)
        {
            cExistsInTable.deleteRow(nRow);
            //Check if this is chat pal info
            if (!cExistsInTable.id.localeCompare("pal"))
                document.getElementById("palContainer").style.display = "none";
        }
    }

    if (cPhoneBkTbl)
    {
        var cNewRow;
        
        if (szMoveTo.length)
        {
            if (szMoveTo == "top")
                cNewRow = cPhoneBkTbl.insertRow(0);
            else
                cNewRow = cPhoneBkTbl.insertRow(cPhoneBkTbl.rows.length);   //assumes bottom....
        }
        else
            if (cExistsInTable == cPhoneBkTbl)
            {
                //alert("Trying to change row: "+nRow);
                cNewRow = cPhoneBkTbl.rows[nRow];
            }
            else
                cNewRow = cPhoneBkTbl.insertRow(0); //Found in other table.. insert new here...
           
        //szDecoded = '<td colspan="4"><br><br><br><br><br><br><br><br><h2>Dette er den nye koden...</h2></td>';
        cNewRow.innerHTML = szDecoded;
        //alert("Inserted row code: "+szDecoded);
        //alert("Phone bk table: "+cPhoneBkTbl.innerHTML);
        moveRowWithDivToTop(false, "phonebktbl", bHideOther = false);           //140120
    }
    else
        if (!cChatFound)
            menu("phonebook", "c=menu", "phonebook");

    //Find the form after put there...
    var cThumbs1 = document.getElementById("thumbs1_"+nId);
    var cThumbs2 = document.getElementById("thumbs2_"+nId);
    var cFormFound = document.getElementById(szFormTag);

    if (cFormFound) //Not found when in chat central...
    {
        var nHeight = cFormFound.offsetHeight;
    
        if (!cThumbs1 || !cThumbs2)
            return;

        var szStatus = "";
        
        if (cThumbs1.innerHTML.length > 10)
        {
            szThumbs = cThumbs1.innerHTML;
            cThumbs2.innerHTML = szThumbs;
            cThumbs1.innerHTML = "";
            var nHeight2 = cFormFound.offsetHeight;
            //szStatus = "Height before: "+nHeight+", height after: "+nHeight2;
        }
        //else
        //    szStatus = "Height: "+nHeight;
        //alert(szStatus);
        //cThumbs2.innerHTML = szHtml;
        
        if (nHeight < nHeight2)
        {
            //Move it back to first position...
            cThumbs1.innerHTML = szThumbs;
            cThumbs2.innerHTML = "";
        }
        
        //Finally adjust the size of the edit field
        var cFormPos = getPosition(cFormFound);
        var nRight = cFormPos.x + cFormFound.offsetWidth; //style.width; 
        var nInitWidth = cFormFound.offsetWidth;
        var cInputs = cFormFound.getElementsByTagName("INPUT");
        var cEdit = false;
        for (var m=0; m<cInputs.length; m++)
            if (!cInputs[m].id.localeCompare("txtTxt"))
            {
                cEdit = cInputs[m];
                break;
            }
  
// 150423 - got too large...      
//        for (var n=0; n<100 && cFormFound.offsetWidth == nInitWidth; n++)
//            cEdit.size += 1;
//        cEdit.size -= 1;
        
        /*while (true)
        {
            var cEditPos = getPosition(cEdit);
            //var nEditRight = cEdit.position().left + cFormFound.offsetWidthright;
            //if (nEditRight > nRight-10)
            if (cEditPos.x + cEdit.offsetWidth > nRight-20)
                break;

            cEdit.size += 1;
        } */
    }

    if (cChatFound)
    {
        cChatFound.innerHTML = szDecoded;
        scrollIntoView(szChatDivId);
    }
    else
        //Don't always scroll to bcoz will flash when used to fill initial list.... 
        if (parseInt(nScrollTo) && szFormTag.length)
            scrollIntoView(szFormTag);
        else
            scroll(0,0);    //131030
}

function phonebook(cCommand)
{
    var szFunc = getIfSet(cCommand, "func");
    switch (szFunc)
    {
        case "fill":
            var szEntries = getIfSet(cCommand, "entries");
            //alert("Entries to put :"+szEntries);
            var cEntries = szEntries.split(",");
            var szPending = "";
            for (var n=0;n<cEntries.length;n++)
            {
                var szEntry = cEntries[n];
                if (!szEntry.length)
                    continue;
                    
                //alert("Entry: "+szEntry);
                 //requestData("insLast", "c=pb&id="+cEntries[n]);
                 
                 if (szPending.length)
                 {
                     szPending = szPending+","+szEntry;
                     requestData("addbatch", "c=pb&idlist="+szPending);
                     szPending = "";
                 }
                 else
                    szPending = ""+szEntry;
            }
             if (szPending.length)
                 requestData("addbatch", "c=pb&idlist="+szPending);
            break;
        default: 
            alert("unknown phonebook command");
    }
}

function sendUserConf(cCommand, bConfirmed)
{
    var szClass = getIfSet(cCommand, "class");
    var szFunc = getIfSet(cCommand, "func");
    var szJson = getIfSet(cCommand, "json");
    
    if (szJson.length)
    {
        var szParams = "c="+szClass+"&json="+szJson;
    }
    else
    {
        var szXml = decodeURIComponent(getIfSet(cCommand, "xml"));
        szXml = szXml+getWrapped("confirmed", (bConfirmed?"1":"0"));
        var szParams = "c="+szClass+"&xml="+getWrapped("xml",szXml);
    }
    //alert("Sending: "+szParams);
    requestData(szFunc, szParams);
}

function userConf(cCommand)
{
    var szMsg = getIfSet(cCommand, "header");
    var szSubMsg = getIfSet(cCommand, "message");
    
    if (userConfirmed(szMsg+"\n\n"+szSubMsg))
        sendUserConf(cCommand, true);        //140506
    else
    {
        var szSendIfCancel = getIfSet(cCommand, "sendIfCancel");
        
        if (parseInt(szSendIfCancel))
            sendUserConf(cCommand, false);
    }
}

function moduleData(cCommand)
{
    var szModule = getIfSet(cCommand, "module");
    var szFunc = "module_ajax_handler_"+szModule;   //i.e: module_ajax_handler_clubs() as defined in clubs.js

    //alert("About to call "+szFunc+"()");    
    //if (typeof(szFunc) == "function")
    window[szFunc](cCommand);
}

function getFieldVal(szId)
{
    var cFld = document.getElementById(szId);
    
    if (!cFld)
    {
        alert("Field not found: "+szId);
        return;
    }
    //alert("Field found, tag name: "+cFld.tagName);  
    var szVal = "??";
    
    switch (cFld.tagName)
    {
        case "INPUT":
        case "SELECT":
            szVal = cFld.value;
            break;
        case "DIV":
            var szDivs="";
            var n;
            for (var n=0; n<cFld.childNodes.length; n++)
            {
                var cNode = cFld.childNodes[n];
                if (cNode.nodeName == "#text")
                {
                    szVal = cNode.nodeValue;
                    break;
                }
                else
                    //<a>-tags: nodeName = "a" and so on... implement here..
                    szVal = cNode.innerHTML;
            }
            break;
        
        default: 
            alert("Field type not yet handled: "+cFld.tagName);
            szVal = "??";
    }
    return szVal;    
}


function memorySave(cCommand)
{
    var szId = getIfSet(cCommand, "id");
    var szVal = getFieldVal(szId);        
    var szMemTag = getIfSet(cCommand, "memtag");
    cStoredVals[szMemTag] = szVal;
    //alert("Value stored to "+szId+", values: "+cStoredVals[szMemTag]);
}

function memoryGet(cCommand)
{
    var szId = getIfSet(cCommand, "id");
    var szMemTag = getIfSet(cCommand, "memtag");
    //alert("Memory variable found: "+cStoredVals[szMemTag]);
    var szVal = cStoredVals[szMemTag];
    
    //alert("Value stored to "+szId+", values: "+cStoredVals[szMemTag]);

    var cFld = document.getElementById(szId);
    
    if (!cFld)
    {
        alert("Field not found: "+szId);
        return;
    }
    
    switch (cFld.tagName)
    {
        case "INPUT":
        case "SELECT":
            cFld.value = szVal;
            break;
        case "DIV":
            cFld.innerHTML = szVal;
            break;
        
        default: 
            alert("Field type not yet handled: "+cFld.tagName);
    }
    //131130
}

function memoryRequest(cCommand)
{
    var szMemTag = getIfSet(cCommand, "memtag");
    var szVal = cStoredVals[szMemTag];
    
    var szFunc = decodeURIComponent(getIfSet(cCommand, "func"));
    var szParams = decodeURIComponent(getIfSet(cCommand, "params"));
    
    var szParams = "c=inline&edit="+szVal+"&"+szParams;
    //alert("Func: "+szFunc+", params: "+szParams);
    requestData(szFunc, szParams);
        //131203
}


function changeElementId(cCommand)
{
    var szFromId = getIfSet(cCommand, "from");
    var szToId = getIfSet(cCommand, "to");
    var cFld = document.getElementById(szFromId);
    cFld.id = szToId;
    //alert("Id changed..");
    
}

function getContents(cCommand)
{
    //First figure out what to get...
    var szDivClass = getIfSet(cCommand, "divclass");    
    var szFormat = getIfSet(cCommand, "format");   
    var szTextFound = "";
    var cTblFound = false;
    
    if (szDivClass.length)
    {
        //Find div of specified class...
        //131206 tagname
        var cDivs = document.getElementsByTagName("DIV");
        var cFound = false;
        for (var n=0; n<cDivs.length;n++)
        {
            var cDiv = cDivs[n];
            if (!szDivClass.localeCompare(cDiv.className))
            {
                cFound = cDiv;
                szTextFound = cDiv.innerHTML;
                break;
            }
        }
        
        if (!cFound)
            alert("Div not found.. (class name given)");
        //else
        //   alert("Found div.. Contents: "+cFound.innerHTML);

    }
    else 
    {
        var szTableId = getIfSet(cCommand, "table");    
        if (szTableId.length)
            cTblFound = document.getElementById(szTableId);
        else
            alert("No spec where to search....");
    }
   
    if (!szTextFound.length && !cTblFound)
        alert("No text found..");

    //Process it..
    switch (szFormat)
    {
        case "tblxml":
            //alert("About to process tbl contents..");
            szTextFound = getXmlFromTable(cTblFound, "tr", false, getIfSet(cCommand, "debugdiv"));
            //alert("back from process..");
            break;
            
        case "text":
        case "":
            //alert("Nothing to do with the contents..");
            break;
            
        default:
            //Do nothing...
            alert("Unknown format: "+szFormat);
            
    }        
        
    //Then figure out what to do with it...
        
    var szJs = getIfSet(cCommand, "js");    

    if (szJs.length)
    {
        //131206
        var cParams = xmlDoc.getElementsByTagName("param");
        
        //if (!cParams.length)
        //    return false;
        
        //alert(cCommands.length+" commands");

        switch (cParams.length)
        {
            case 0:
                window[szJs](szTextFound);
                break;
            case 1:
                window[szJs](szTextFound, cParams[0].textContent);
                break;
            case 2:
                window[szJs](szTextFound, cParams[0].textContent, cParams[1].textContent);
                break;
            case 3:
                window[szJs](szTextFound, cParams[0].textContent, cParams[1].textContent, cParams[2].textContent);
                break;
            case 4:
                window[szJs](szTextFound, cParams[0].textContent, cParams[1].textContent, cParams[2].textContent, cParams[3].textContent);
                break;
            default:
                alert("Unhandled number of paramters: "+cParams.length);
        }
    }        
}

function daysUntil(szDate) 
{
    var fromdate = szDate.slice(8, 10);
    fromdate = parseInt(fromdate);
    var frommonth = szDate.slice(5, 7); 
    frommonth = parseInt(frommonth);
    var fromyear = szDate.slice(0, 4); 
    fromyear = parseInt(fromyear);
    var oneDay = 24*60*60*1000;
    var cDate = new Date(fromyear,frommonth-1,fromdate-1);
    var cNow = new Date();

    var nDays = Math.round(Math.abs((cDate.getTime() - cNow.getTime())/(oneDay)));
    return nDays;
}

function getPendingTxt(szFrom)
{
    var cEls = szFrom.split(/[-| |:]/g);

    if (!cEls.length)
        return "unable to decode";

     var cDate = new Date(cEls[0], cEls[1]-1, cEls[2], cEls[3], cEls[4], cEls[5], 0);
     var cNow = new Date();
     var nDiff = cDate - cNow;
     
     if (nDiff < 0)
        return "0:00";
        
     var mins = Math.floor((Math.abs(nDiff)) / 1000 / 60);
     var hours = Math.floor(mins/60);
     mins = mins - hours*60;
     return (nDiff<0?"-":"")+hours+":"+mins;
}

function startCountdown(szId, szFrom)
{
    var szTxt = getPendingTxt(szFrom);
    var cDiv = document.getElementById(szId);
    if (cDiv)
    {
         cDiv.innerHTML = szTxt;
         //setTimeout("makeBlk('"+szId+"')",nSecs*1000);
    }
    setTimeout("startCountdown('"+szId+"','"+szFrom+"')",60*1000);
}


function calendarEvents(cCommand)
{
    menu("getTeamRoles","c=team");
    menu("getTeamWalls","c=team");

    var cEvents = cCommand.getElementsByTagName("events")[0];
    var cEventArr = cEvents.getElementsByTagName("event");
    var cTbl = document.getElementById("activitiesTbl");
    
    var cKidsColorJson = getJsonParamArray(getIfSet(cCommand,"kidsColor"));
    
    var cHelpArr = new Array(
        'Vent på utfylling',
        'Hvis ingen respons innen 30sek:',
        '<a href="javascript:menu(\'menu:home\',\'cache=0\')">Klikk her for å oppdatere cache</a>',
        'Ellers prøv igjen om 10min',
        'Stadig klikking stresser bare serveren',
        'Ved fortsatt problem: <a href="mailto:post@foreldrekontakten.no">Kontakt oss</a>'
        );
    var nHelpNdx = 0;

    if (!cEventArr.length)
    {
        var cRow = cTbl.insertRow(0);
        cRow.insertCell.innerHTML = "Ingen poster funnet i aktivitetslisten";
        //140912
        /*var nLoopCount = getIfSet(cCommand, "loop");
        var szRetryMsg = getIfSet(cCommand, "retryMessage");
        
        if (szRetryMsg.length)
        {
            if (userConfirmed(szRetryMsg))
                requestData('club:getEvents','loop='+nLoopCount);
        }
        else
            requestData('club:getEvents','loop='+nLoopCount);
        */
        return;
    }
    
    var cArr = new Array();
    var cCheckArr = new Array();
    var nDummy = 0;
        
    for (var n=0; n<cEventArr.length; n++)
    {
        var cEvent = cEventArr[n];
        //140102 
        var szId = decodeURIComponent(getIfSet(cEvent, "id"));        
        var szFrom = decodeURIComponent(getIfSet(cEvent, "frm"));
        var szTo = decodeURIComponent(getIfSet(cEvent, "to"));
        var szTxt = decodeURIComponent(getIfSet(cEvent, "txt"));
        var szActId = getIfSet(cEvent, "aid");
        
        if (szActId == 268)
        {
            nDummy = 1;
        }
        
        var szCat = getIfSet(cEvent, "ct");

        var szJson = getIfSet(cEvent, "json");
        var cJson = false;
        if (szJson.length)
        {
            cJson = getJsonParamArray(szJson);
            //if ()
            //cJson = 
        }
        
        
        var szSignedByContents = "";        
        var szFree = getIfSet(cEvent, "free");
        var szTot = getIfSet(cEvent, "tot");
        var szComing = getIfSet(cEvent, "coming");
        var szNames = getIfSet(cEvent, "nm");//+" ("+szActId+")";
        var szDecodedNames = decodeURIComponent(szNames);

        var szDt = szFrom.substr(0,10);
        var szColor = "";
        
        var szHelp = (!szCat.localeCompare("WBA")?
                    (nHelpNdx < cHelpArr.length?'<font color="red">'+cHelpArr[nHelpNdx++]+'</font>':""):"");
            
        szSignedByContents = '<div id="sign">'+szHelp+'</div>';
        
        if (daysUntil(szDt) < 7 && parseInt(szFree))
        {
            szColor = "red";
            szSignedByContents = '<font color="red">'+szSignedByContents+'</font>';
        }

        var szNotes = getIfSet(cEvent, "notes");
        if (szNotes.length)
        {
            var cParts = szNotes.split(":");
            if (cParts.length == 2)
            switch(cParts[0])
            {
                case "endedMatch":
                     szTxt += "<b>Slutt: "+cParts[1]+'</b>';


                     break;
                case "ongoingMatch":
                     szTxt += initOngoingMatch(szActId);
                     break;
                case "pendingMatch":
                     szTxt += ". Nedtelling: "+getPendingMatchText(szActId, szFrom);
                     break;
                     
                default:
                     szTxt += '('+szNotes+')';
                    
            }
        }
        
        if (cTbl)
        {
            var cRow = cTbl.insertRow(cTbl.rows.length);  

            var szRowId = getActTblRowId(szCat, szActId, szDt, szFrom, szId);
                
            cRow.id = szRowId;

            //Fields: id,nm,frm,to,aid,ct,txt,trns,sgn,tkn(free?),tot,notes,coming
            //140505: szCat+
            
            //New_vKidsActivity  - handle multiple names in new ver:
            var cMultiId = szId.split(",");
            var cMultiNames = szDecodedNames.split("¨");
            var szNamesCont = "";

            if (cMultiId.length == cMultiNames.length)  //Otherwise hang....
            {
                for (var c=0; c<cMultiId.length;c++)
                {
                    var szThisId = cMultiId[c];
                    var szThisNm = cMultiNames[c];
                    for (var z = 0; z<cKidsColorJson.length;z++)
                        if (parseInt(cKidsColorJson[z][0]) == parseInt(szThisId))
                            szThisNm = '<font color="'+cKidsColorJson[z][1]+'">'+szThisNm+'</font>';
                    szNamesCont += (szNamesCont.length?", ":"")+'<a href="javascript:showProfile('+szThisId+',\'\')">'+szThisNm+'</a>';
                }

                cRow.insertCell(cRow.cells.length).innerHTML = szNamesCont+'<div id="kids" style="display:none">'+szId+'</div><div id="kidsNm" style="display:none">'+szDecodedNames+'</div>';
            }
            else
                //140607 New_vKidsActivity (old ver)    
                cRow.insertCell(cRow.cells.length).innerHTML = '<a href="javascript:showProfile('+szId+',\'\')">'+decodeURIComponent(szDecodedNames)+'</a>';

            var cDate = convertToDate(szDt);
        
            if(cDate !== null)
                szDispDt = getDisplayDate(cDate);
            else
                szDispDt = szDt;

            var cDateCell = cRow.insertCell(cRow.cells.length);
            cDateCell.innerHTML = szDispDt;

            var cFromCell = cRow.insertCell(cRow.cells.length);
            cFromCell.innerHTML = szFrom.substr(11,5);

            var cToCell = cRow.insertCell(cRow.cells.length);
            cToCell.innerHTML = szTo.substr(11,5);
            
            var szLoc = getIfSet(cEvent, "loc");
            
            if (szLoc.localeCompare("^"))
            {
                var cParts = szLoc.split("^");
                szTxt += ", "+cParts[1];
            }
                
            var cTxtCell = cRow.insertCell(cRow.cells.length);
            cTxtCell.innerHTML = '<a href="javascript:menu(\'show'+szCat+'\',\'c=club&id='+szActId+'\')">'+szTxt+'</a><div id="info" style="display:inline">&nbsp;</div>';

            if (cJson && parseInt(cJson["T"]) == 1)
            {
                cDateCell.style.fontWeight="bold";
                cFromCell.style.fontWeight="bold";
                cToCell.style.fontWeight="bold";
                cTxtCell.style.fontWeight="bold";
            }

            //140516.. moved to post processing in updateActResp()....
            
            var cCell = cRow.insertCell(cRow.cells.length);
            cCell.innerHTML = '<div id="trns"></div>'; //szTransLink;
            cCell.height = "16";

            var cSignCell = cRow.insertCell(cRow.cells.length);

            var szResponse;
            var szRespMsg = getIfSet(cEvent, "rsp");
            if (!szCat.localeCompare("Activity"))
                szResponse = "";//getRespButtons(szRowId, szComing, false, true, szRespMsg);// false = not clicked, true = swap when click
            else
                if (!szCat.localeCompare("Acct"))
                    szResponse = getAcctRespButton(szActId);
                else
                    szResponse = szSignedByContents;

            cSignCell.innerHTML = szResponse;
        }
        else
        {
            var cRow = new Array();
            
            cRow["start_date"] = szFrom.substr(0,16);
            cRow["id"] = szActId+szCat+szFrom.substr(8,2);       //getIfSet(cEvent, "id");
            cRow["end_date"] = szTo.substr(0,16);
            cRow["text"] = szTxt+' '+szSignedByContents;
            
            if (szColor.length)
                cRow["textColor"] = "red";
            //alert("id: "+szId+", from: "+szFrom+", to: "+szTo+", txt: "+szTxt);




            cArr.push(cRow);
            //Put in calendar
        }
    }

    if (!cTbl)
        scheduler.parse(cArr, "json");//takes the name and format of the data source    

    //if (cCheckArr.length)
    //{
      //  requestData('fillActResp', 'c=tools&act='+cCheckArr.join("^"));
    //}
    addToInnerHtml("cacheInfo",".. Req.sent");


}
