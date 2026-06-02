<?php
//Use this for sending elements to javascript using Ajax

//NOTE! Modifications not finalized...
//no use calling $pSystem::getXml(); or creating any object....
//All function are now static....

class CXmlCommand
{
    public $cCommands = array();  //NOTE! main handling is moved to CSystem

public function __construct()    
{
//    $this->cCommands = array();
}

static function encoded($szTxt)
{
    //$szTxt = htmlEncoded($szTxt); //ØT 140207 - removed... required extensive testing...
    return (isset($szTxt)?rawurlencode($szTxt):"");
    //return escape($szTxt);
}

static public function getWrapped($szTag, $szParam)
{
    return "<$szTag>$szParam</$szTag>";
}
    
static public function addTableRow($szTable, $szWhere, $szKey, $cArr, $szClass = "", $szId = "")//$szHTML)
{
    $szTxt = CXmlCommand::getWrapped("what", "addTableRow").
    CXmlCommand::getWrapped("tablename", $szTable).
    CXmlCommand::getWrapped("where", $szWhere).             //"top"  (default is at end)
    CXmlCommand::getWrapped("key", $szKey);                 //Used to search if row id already exists.. if so, overwrite...

    if (strlen($szId))
        $szTxt .= CXmlCommand::getWrapped("id", $szId);
    
    if (strlen($szClass))
        $szTxt .= CXmlCommand::getWrapped("class", $szClass);
        
    $szRow = "";
    
    foreach($cArr as $szCell)
        $szRow .= CXmlCommand::getWrapped("td", CXmlCommand::encoded($szCell));
    
    $szTxt .= CXmlCommand::getWrapped("code", $szRow);//CXmlCommand::encoded($szHTML));   //<td>fld</td> tags...
    //$this->cCommands[] = $szTxt;
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
}

static public function addTableCol($szTable, $nRow, $szCode, $szValign="top")
{
    $szTxt = CXmlCommand::getWrapped("what", "addTableCol").
    CXmlCommand::getWrapped("tablename", $szTable).
    CXmlCommand::getWrapped("row", $nRow).
    CXmlCommand::getWrapped("valign", $szValign).
    //CXmlCommand::getWrapped("code", "[CDATA[".$szCode."]]");
    CXmlCommand::getWrapped("code", CXmlCommand::encoded($szCode));
    
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
//    $this->cCommands[] = $szTxt;
}

static public function deleteRow($szTableName, $nNdx)
{
    $szTxt = CXmlCommand::getWrapped("what", "deleteRow").
    CXmlCommand::getWrapped("tablename", $szTableName).
    CXmlCommand::getWrapped("row", $nNdx);
    
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
}

static public function deleteRowWithId($szRowId)
{
    $szTxt = CXmlCommand::getWrapped("what", "deleteRow").
    CXmlCommand::getWrapped("rowId", $szRowId);
    
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
}

static public function deleteColumnContainingId($szTable, $szId)
{
    $szTxt = CXmlCommand::getWrapped("what", "deleteColumnContainingId").
    CXmlCommand::getWrapped("tablename", $szTable).
    CXmlCommand::getWrapped("id", $szId);
    
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
}

static public function emptyTable($szTableName)
{
    //CXmlCommand::alert("emptyTable...");    
    $szTxt = 
    CXmlCommand::getWrapped("what", "emptyTable").
    CXmlCommand::getWrapped("tablename", $szTableName);
    
    global $pSystem;
    $pSystem->cCommands[] = $szTxt;
}

static public function printXmlWrappedCmd($szCmd)
{
    $szTxt = CXmlCommand::getWrapped("xml", CXmlCommand::getWrapped("commands", $szCmd));
    $szPattern = '/(.*)INSERT\\_CALLBACK\\_CONTENTS\\_HERE(.*)/s';
    if (preg_match($szPattern, $szTxt, $cMatch))
    {
        $szTxt = $cMatch[1];
        $pSystem = getSystem();
        call_user_func($pSystem->szXmlCallbackFunc);
        $szTxt .= $cMatch[2];
    }
    
    print $szTxt;
                                          
    global $_global_ip_logging_warning_record_id;
    
    if (isset($_global_ip_logging_warning_record_id))
    {
        $szCategory = CDb::getString("select Category from SystemMessage where SystemMessageId = :id",$cFlds = array(":id"=>$_global_ip_logging_warning_record_id));
        
        if ($szCategory != "IpLogging")
            reportHacking("IP logging.. wrong category: $szCategory");
        else
        {
            $cFlds["txt"] = $szTxt;
            CDb::doExec("update SystemMessage set TechInfo = :txt where SystemMessageId = :id",$cFlds);
            unset($_global_ip_logging_warning_record_id);
        }
    }
}

static public function alert($szMsg)
{
    global $pSystem;
    //NOTE Alert boxes doesn't handle Å as &Aring;.... so can't use encode()...
    //$szEncodedText = CXmlCommand::encoded($szMsg);
    $szEncodedText = rawurlencode($szMsg);

    //140128
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "alert").CXmlCommand::getWrapped("msg", $szEncodedText);
    //$this->cCommands[] = CXmlCommand::getWrapped("what", "alert").CXmlCommand::getWrapped("msg", $szMsg);
}

static public function prompt($szMsg, $szDefault = "", $szRequestFunc = "", $cJsonArray = "")
{
    global $pSystem;

    if (is_array($cJsonArray))
        $szJson = json_encode($cJsonArray);
    else
        $szJson = "";

    //140511 xml
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "prompt").CXmlCommand::getWrapped("msg", CXmlCommand::encoded($szMsg)).
        CXmlCommand::getWrapped("default", CXmlCommand::encoded($szDefault)).CXmlCommand::getWrapped("requestFunc", $szRequestFunc).CXmlCommand::getWrapped("json", $szJson);
}

static public function setDisplay($szId, $szDisplay, $bWarnIfNotFound = false)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "setDisplay").
            CXmlCommand::getWrapped("id", $szId).
            CXmlCommand::getWrapped("display", $szDisplay).
            CXmlCommand::getWrapped("warnIfNotFound", ($bWarnIfNotFound?"1":"0"));
}
static public function setClass($szId, $szClass)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "setClass").
            CXmlCommand::getWrapped("id", $szId).
            CXmlCommand::getWrapped("class", $szClass);
}

static public function getParamsFromArr($cMoreParamsArr)
{
    $szXML = "";
    foreach ($cMoreParamsArr as $szKey => $szValue)
        $szXML .= CXmlCommand::getWrapped($szKey, $szValue);
    return $szXML;
}

static public function setInnerHTML($szFldId, $szFldName, $szValue, $cMoreParamsArr = array())
{
    //Known extra params: "dropifexists" => "formLogin"
            //NOTE! omitIfContains used in js....
            //"style"=>"display:none"|"display:"
    $szIdent = "";
    if (strlen($szFldId)) $szIdent = CXmlCommand::getWrapped("id", $szFldId);
    if (strlen($szFldName)) $szIdent = CXmlCommand::getWrapped("name", $szFldName);
    
    //$this->cCommands[] = 
    global $pSystem;
    
    if ($pSystem)
        $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "setInnerHTML").$szIdent.CXmlCommand::getWrapped("value", CXmlCommand::encoded($szValue)).CXmlCommand::getParamsFromArr($cMoreParamsArr);
}

static function addToInnerHTML($szFldId, $szFldName, $szValue, $szWhere = "bottom", $szOnDuplicates = "add")
{
    $szIdent = "";
    if (strlen($szFldId)) $szIdent = CXmlCommand::getWrapped("id", $szFldId);
    if (strlen($szFldName)) $szIdent = CXmlCommand::getWrapped("name", $szFldName);
    
    //$this->cCommands[] = 
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "addToInnerHTML").
            $szIdent.
            CXmlCommand::getWrapped("where", $szWhere).
            CXmlCommand::getWrapped("value", CXmlCommand::encoded($szValue)).
            CXmlCommand::getWrapped("onDuplicates", $szOnDuplicates);  //add(default)/omit/omitIfContains^<searchtext>
}

static function setValue($szFldId, $szFldName, $szValue)
{
    $szIdent = "";
    if (strlen($szFldId)) $szIdent = CXmlCommand::getWrapped("id", $szFldId);
    if (strlen($szFldName)) $szIdent = CXmlCommand::getWrapped("name", $szFldName);
    
        //$this->cCommands[] = 
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "setValue").$szIdent.CXmlCommand::getWrapped("value", CXmlCommand::encoded($szValue));
}

static function addToValue($szFldId, $szFldName, $szValue)
{
    $szIdent = "";
    if (strlen($szFldId)) $szIdent = CXmlCommand::getWrapped("id", $szFldId);
    if (strlen($szFldName)) $szIdent = CXmlCommand::getWrapped("name", $szFldName);
    
        //$this->cCommands[] = 
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "addToValue").$szIdent.CXmlCommand::getWrapped("value", CXmlCommand::encoded($szValue));
}

static function commandCount()
{
    //$this->cCommands[] = 
    global $pSystem;
    return count($pSystem->cCommands);//$this->cCommands);
}

static public function ajaxRequest($szUrl)
{
    //Note, this takes full url...
        //$this->cCommands[] = 
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "ajaxRequest").CXmlCommand::getWrapped("url", CXmlCommand::encoded($szUrl));
}

static public function javascript($szScript, $cParamArr, $nDelaySec=0)
{
    //NB! WRONG: Note, this takes full url...
    //$this->cCommands[] = 
    //Sample call: CXmlCommand::javascript("menu", array("readNhfClubs", $szParams)); 

    $szXml = CXmlCommand::getWrapped("what", "javascript").CXmlCommand::getWrapped("script", CXmlCommand::encoded($szScript));
    
    $szParams = "";
    foreach ($cParamArr as $cParam)
        if (is_array($cParam))
        {
            $szPar="";
            foreach ($cParam as $szKey => $szVal)
                $szPar .= (strlen($szPar)?"&":"").$szKey."=".$szVal;
                
            $szParams .= CXmlCommand::getWrapped("param", CXmlCommand::encoded($szPar));
        }
        else
            $szParams .= CXmlCommand::getWrapped("param", CXmlCommand::encoded($cParam));

    $szXml .= CXmlCommand::getWrapped("params", $szParams);
    
    if ($nDelaySec)
        $szXml .= CXmlCommand::getWrapped("delay", $nDelaySec);
        
    global $pSystem;
    $pSystem->cCommands[] = $szXml;
}
    
static function sendDivBatch($cBatch)
{
    foreach($cBatch as $cCmd)
    {
        if (sizeof($cCmd)<2)
            alert("No parameter for: ".$cCmd[0]);
        else
            CXmlCommand::setInnerHTML($cCmd[0],"",$cCmd[1]);
    }
}
    
static function userConfirmed($szClass, $szFunc, $szHeader, $szSubMessage, $bSendIfCancel, $szXmlStringOrJsonArr)
{
    $szXml = CXmlCommand::getWrapped("what", "userConfirmed").
            CXmlCommand::getWrapped("class", $szClass).
            CXmlCommand::getWrapped("func", $szFunc).
            CXmlCommand::getWrapped("header", $szHeader).
            CXmlCommand::getWrapped("message", $szSubMessage).
            CXmlCommand::getWrapped("sendIfCancel", ($bSendIfCancel?"1":"0"));
            
            if (is_array($szXmlStringOrJsonArr))
                $szXml .= CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($szXmlStringOrJsonArr)));
            else
                $szXml .= CXmlCommand::getWrapped("xml", CXmlCommand::encoded($szXmlStringOrJsonArr));
            
    //alert("About to send xml: ".$szXml);            
    getSystem()->cCommands[] = $szXml;
}

    
static function addStdAjaxRequest($szFunc, $szParam)
{
    //Standard ajax request (calling content.php)
            //$this->cCommands[] = 
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "stdAjaxRequest").
    (strlen($szFunc)?CXmlCommand::getWrapped("func", CXmlCommand::encoded($szFunc)):"").
    CXmlCommand::getWrapped("params", CXmlCommand::encoded($szParam));
}

static public function initchat()
{
    //Reinitialized cometChat (after login our logout...)
    //Makes the script abort when running locally(???)

    //if (!runningLocally())
        getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "initchat");
}

static public function chatWith($nUserId)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "chatWith").
    CXmlCommand::getWrapped("id", CXmlCommand::encoded($nUserId));
}

static public function moveToTop($szIdTag)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "moveToTop").
    CXmlCommand::getWrapped("id", CXmlCommand::encoded($szIdTag));
}

static public function deleteRowContainingId($szId)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "deleteRowContainingId").
    CXmlCommand::getWrapped("id", CXmlCommand::encoded($szId));
}

static public function displayPers($szId, $szCode, $szMoveTo = "top", $szXml = "", $nScrollTo = 0)
{
    /**************************** NOTE! Error.. $szXml is not used... $szMoveTo is passed instead... */
    $szFormTag = CPhonebook::getFormTag($szId);
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "displayPerson").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("formtag", CXmlCommand::encoded($szFormTag)).
    CXmlCommand::getWrapped("moveto", CXmlCommand::encoded($szMoveTo)).
    CXmlCommand::getWrapped("code", CXmlCommand::encoded($szCode)).
    ($nScrollTo?CXmlCommand::getWrapped("scrollto", "1"):"").
    CXmlCommand::getWrapped("xml", CXmlCommand::encoded($szXml));   //150222 - NOTE! Was $szMoveTo here also...
}

static public function moduleData($szModule, $szTag, $szTxt, $szMoreXml = "")
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "moduleData").
    CXmlCommand::getWrapped("module", CXmlCommand::encoded($szModule)).
    CXmlCommand::getWrapped("tag", CXmlCommand::encoded($szTag)).
    CXmlCommand::getWrapped("text", CXmlCommand::encoded($szTxt)).$szMoreXml;
}
                       
static public function calendarEvents($szXml, $nLoop = 0, $szRetryMsg = "", $szKidsColorJson = "")
{
    //Origninal layout... $szXml = "<entry><id>...</id><nm>Name</nm><from>yyyy-mm-dd hh:mm</from><to>....</to><ct>category</ct><txt>...</txt><trns>1</trns><free>n shifts free</free><tot>of total n shifts</tot><notes>Transport notes</notes><coming>Meldt coming:[1|0|]</coming></event><event....."
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "calendarEvents").
    CXmlCommand::getWrapped("events", $szXml).
    CXmlCommand::getWrapped("loop", $nLoop).
    CXmlCommand::getWrapped("retryMessage", $szRetryMsg).
    (strlen($szKidsColorJson)?CXmlCommand::getWrapped("kidsColor", $szKidsColorJson):"");
}

static public function getXmlText(&$cCommands)
{
    global $pSystem;    
    $szTxt = "";
    foreach ($pSystem->cCommands as $szCommand)
        if (is_array($szCommand))
        {
            switch($szCommand[0])
            {
                case "print":
                    print $szCommand[1];
                    return; //Print command found... omit other commands and return (may be changed to print more???)
            }
        }
        else
            $szTxt .= CXmlCommand::getWrapped("command", $szCommand);

    return CXmlCommand::printXmlWrappedCmd($szTxt);
}

    
static public function doSend()
{
    global $pSystem;   
    
    //if ($pSystem->cPostProcessingCommands)
    //{
    //    alert("post processing found");
    //}

if (!$pSystem) //170131
{
	//170131 - Think may end here if cookie delete by server... 
	require_once("/var/www/html/class/System.class.php");
	$pSystem = new CSystem();
//	return;
}
    
    $pSystem->cCommands = array_merge($pSystem->cCommands, $pSystem->cPostProcessingCommands);
    $pSystem->cPostProcessingCommands = array();
     
    if (!count($pSystem->cCommands))
    {
        if ($pSystem->bXmlFlushed)
            return;     //Already flushed... Don't create blank...
            
        //if (isAjax()) NO NEED BCOX SETS waitCursor below...
        //    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "nothing");
    }
    
    if (!myId())
        CXmlCommand::informLoggedIn($bForcedLoggedOut = false);

    CXmlCommand::waitCursor($bWait=false);  //Last command to send is to restore cursor..
    
    //Print the commands to canvas:
    CXmlCommand::getXmlText($pSystem->cCommands);
    
    $pSystem->cCommands = array();
    $pSystem->bXmlFlushed = true;
}

static function send()
{
    //programExit("Obsolete function xml->send called.");
    //No operation required...
}

static function instantAlert($szMsg)
{
    global $pSystem;
    $cXml = $pSystem->getXml();//new CXmlCommand();
    $cXml->alert($szMsg);
//    $cXml->send();
}

static function instantAddToInnerHTML($szFldId, $szFldName, $szValue)
{
    global $pSystem;
    $cXml = $pSystem->getXml();//new CXmlCommand();
    $cXml->addToInnerHTML($szFldId, $szFldName, $szValue);
//    $cXml->send();
}

static function flushXml()
{
    if (!isAjax())
    {
        if (!in_array(get("func"),array("getMatchRpt")))
        {
            $szMsg = "flushXml() callend when not ajax call... probably some error... Func = ".get("func");
            reportHacking($szMsg);
        }
        return;
    }

    if (isPendingWarning())
    {
        alert(getWarning());
        resetWarning();
    }

    //150130
    //Get buffer... and check if it's error message....
    $szBuff = trim(ob_get_contents());
    if (strlen($szBuff) && !experiencingDbConnectionTrouble())
    {

        if (get("func")== "getMatchRp")
            //NOTE! See also CXmlCommand::printToCanvas() and CXmlCommand::getXmlText() 
            saveHackingReportToDb("Kamprapport printed.. Used to trigger warning.....","N/A",0,$szBuff);
        else                    
        //NOTE! Happens when kamprapport is printed...
            saveHackingReportToDb("Unhandled situation... output buffer contained data..","N/A",0,$szBuff);
        
    }
    
    CXmlCommand::doSend();
    //Generates error sometimes... trying catch below... ob_end_flush(); //Flush what's printed to the internal buffer.. check ob_start();

    if (strlen(ob_get_contents()))
    {
        try { 
            ob_end_flush();
            } 
            
        catch( Exception $e ) 
        {
            reportHacking("ob_end_flush() caused exception... in flushXml()");    
        }
    }
    
//    global $_global_exited_gracefully;
//    $_global_exited_gracefully = true;
}


static function memSave($szId, $szMemoyTag)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "memSave").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("memtag", CXmlCommand::encoded($szMemoyTag));

    //alert("About to save var...");
}

static function setMemVal($szTag, $szValue)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "setMemVal").
    CXmlCommand::getWrapped("tag", $szTag).
    CXmlCommand::getWrapped("value", CXmlCommand::encoded($szValue));

    //alert("About to save var...");
}

static function memGet($szMemoyTag, $szId)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "memGet").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("memtag", CXmlCommand::encoded($szMemoyTag));
    //Gets value from memory and puts somewhere (innerHTML or value)
    //alert("About to get var..");
}

static function memRequest($szMemoryTag, $szFunc, $szMoreParams)
{
    global $pSystem;
    //alert("Memrequest!!");
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "memRequest").
    CXmlCommand::getWrapped("memtag", CXmlCommand::encoded($szMemoryTag)).
    CXmlCommand::getWrapped("func", CXmlCommand::encoded($szFunc)).
    CXmlCommand::getWrapped("params", CXmlCommand::encoded($szMoreParams));
}

static function changeElementId($szFromId, $szToId)
{
    global $pSystem;
    $pSystem->cCommands[] = CXmlCommand::getWrapped("what", "changeElementId").
    CXmlCommand::getWrapped("from", $szFromId).
    CXmlCommand::getWrapped("to", CXmlCommand::encoded($szToId));
    
}

static function getContents($szXml)
{
    //Use this to get contents from users current web page and send to some JS. Params may be added.. 
    //Current known params:
    
    //How to locate info:
    //divclass - class name of div to look for..
    
    //How to process the info
    //format - [text|tblxml]
    //debugdiv - id of div to put debug info from the processing

    //How to send it
    //js - name of javascript to call (found text is first param)
    //params - more parameters to pass to the js
    
    //NOTE! Check xmlRequest for example... Send the contents of <div class="contactInfoCell"> to CTools::test(). $_GET[txt] = the text from the div...
    //$szParams = CXmlCommand::getWrapped("param","test").CXmlCommand::getWrapped("param","c=tools");
    //$szXml = CXmlCommand::getWrapped("divclass", "contactInfoCell").CXmlCommand::getWrapped("js", "xmlRequest").CXmlCommand::getWrapped("params", $szParams);
    //CXmlCommand::getContents($szXml);
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "getContents").$szXml;
}

static function informLoggedIn($bForcedLoggedOut = false)
{
    if (!myId())
    {
        //160704: Refresh only if thinks logged in... check modjson
        $szModJson = get("modjson");
        if (strlen($szModJson))
        {
            $cModArr = json_decode($szModJson, $bAssoc = true);
            if (isset($cModArr["loggedId"]))
                if ($cModArr["loggedId"]+0)
                    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "refresh");  //To make web page aware that not logged in....
                //else
                //    CXmlCommand::alert("Knows not logged it...");
        }
    }
    
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "informLoggedIn").
            CXmlCommand::getWrapped("loggedIn", (myId()?"Yes":"No")).
            CXmlCommand::getWrapped("forcedLoggedOut", ($bForcedLoggedOut?"Yes":"No"));
}

static function setFlag($szFlag, $szValue)//"LoginInProgress");
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "setFlag").CXmlCommand::getWrapped("flag", $szFlag).CXmlCommand::getWrapped("value", $szValue);
}

static function waitCursor($bWait)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "waitCursor").CXmlCommand::getWrapped("wait", ($bWait?"1":"0"));
}

static function setNextCommandContentsCallback($szFunction, $cParametersArr)
{
    //To use, first call this func, then call CBasic::display("INSERT_CALLBACK_CONTENTS_HERE");
    $pSystem = getSystem();
    $pSystem->szXmlCallbackFunc = $szFunction;
    $pSystem->cXmlCallbackParams = $cParametersArr;
}

static function requestWiki($szCategory, $nId, $szDivTag, $szTitle)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "wiki").
    CXmlCommand::getWrapped("category", $szCategory).
    CXmlCommand::getWrapped("id", $nId).
    CXmlCommand::getWrapped("divid", $szDivTag).
    CXmlCommand::getWrapped("title", CXmlCommand::encoded($szTitle));
}

static function resetDropList($szId, $szSetTo)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "resetDropList").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("setTo", CXmlCommand::encoded($szSetTo));
}

static function makeBlink($szId)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "makeBlink").
    CXmlCommand::getWrapped("id", $szId);
}

static function sendJsVersion()
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "jsVersion").
    CXmlCommand::getWrapped("version", currentJsVersion());
}

static function sendSubmenu($pSubmenu)
{                                                              
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "submenu").
    CXmlCommand::getWrapped("id", $pSubmenu->getContainerId()).
    $pSubmenu->getXml();
}

static function pickDate($szId) //Keyword: initDatePick() selectDate chooseDate calendar datePicker dateSelector
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "pickDate").
    CXmlCommand::getWrapped("id", $szId);
}
               
static function ticking($bValue)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "ticking").
    CXmlCommand::getWrapped("ticking", $bValue);
}

static function editInline($szDivId, $szHtml, $szFldType, $szCategory)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "editInline").
    CXmlCommand::getWrapped("id", $szDivId).
    CXmlCommand::getWrapped("fldType", $szFldType).
    CXmlCommand::getWrapped("category", $szCategory).
    CXmlCommand::getWrapped("html", CXmlCommand::encoded($szHtml));
}

static function tooltip($szId, $szTip)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "tooltip").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("tip", CXmlCommand::encoded($szTip));
}

static function submitForm($szFormId = "", $szFormWithFldId = 0)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "submitForm").
    CXmlCommand::getWrapped("id", $szFormId).CXmlCommand::getWrapped("fldId", $szFormWithFldId);
    
}

static function debug($szDebugText)
{
    //May be tracked in the browser (reply to requests) or put in the debug field
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "debug").
    CXmlCommand::getWrapped("txt", $szDebugText);
}

static function moveLastEntryToEnd()
{
    $szLastCommand = array_pop(getSystem()->cCommands);
    if ($szLastCommand !== NULL)
        getSystem()->cPostProcessingCommands[] = $szLastCommand;
    else
        reportHacking("move last when there's none... in moveLastEntryToEnd()");
}

static function breakPoint()
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "breakPoint");
}

static function setTopInsertion($szHtml, $szSection = "Ins")
{
    $szDivId = "top".$szSection;
    if (strlen($szHtml))
    {
        //CXmlCommand::breakPoint();
        CXmlCommand::setInnerHTML($szDivId,"",$szHtml);
        CXmlCommand::setDisplay($szDivId, "inline");
    }
    else
    {
        //CXmlCommand::breakPoint();
        CXmlCommand::setDisplay($szDivId, "none");
        CXmlCommand::setInnerHTML($szDivId,"","");
        
    }
}

static function setTextOnRow($szTableId, $szRowId, $szFldId, $szTxt, $bAddToTxt = true)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "setTextOnRow").
    CXmlCommand::getWrapped("tbl",  $szTableId).
    CXmlCommand::getWrapped("row", $szRowId).
    CXmlCommand::getWrapped("fld", $szFldId).
    CXmlCommand::getWrapped("txt", CXmlCommand::encoded($szTxt)).
    CXmlCommand::getWrapped("add", ($bAddToTxt?"1":"0"));
}

static function makeMenuGroup($szId, $szName, $szNameScript, $szCreate, $szCreateScript, $cChoices)
{
    //$cChoices is array of array("Title", "Script" [, "icon"])
    //Maybe should add to menu is szId already exists.. (not yet implemented)
    $szCmd = CXmlCommand::getWrapped("what", "makeMenuGroup").
    CXmlCommand::getWrapped("id",  $szId).  
    CXmlCommand::getWrapped("name",  $szName).
    CXmlCommand::getWrapped("nameScript",  $szNameScript).
    CXmlCommand::getWrapped("create",  $szCreate).
    CXmlCommand::getWrapped("createScript",  $szCreateScript);

    for ($n = 0; $n < sizeof($cChoices); $n++)
    {
        $szCmd .= CXmlCommand::getWrapped("choice$n",  $cChoices[$n][0]);
        $szCmd .= CXmlCommand::getWrapped("choiceScript$n",  $cChoices[$n][1]);
        if (isset($cChoices[$n][2]))
            $szCmd .= CXmlCommand::getWrapped("icon$n",  $cChoices[$n][2]);
    }
    
    getSystem()->cCommands[] = $szCmd;
}

static function setFldValIfSet(&$cDefArray,$n,$szNewNdx, $szFromLbl)
{
    if (isset($cDefArray["fields"][$n][$szFromLbl]))    
        $cDefArray[$szNewNdx.$n] = $cDefArray["fields"][$n][$szFromLbl];
}

static function form($cDefArray)
{
    for ($n = 0; $n < sizeof($cDefArray["fields"]); $n++)
    {
        CXmlCommand::setFldValIfSet($cDefArray,$n,"l","label");
        //$cDefArray["l$n"] = $cDefArray["fields"][$n]["label"];
        CXmlCommand::setFldValIfSet($cDefArray,$n,"id","id");
        //$cDefArray["id$n"] = $cDefArray["fields"][$n]["id"];
        CXmlCommand::setFldValIfSet($cDefArray,$n,"t","type");
        //$cDefArray["t$n"] = $cDefArray["fields"][$n]["type"];
        CXmlCommand::setFldValIfSet($cDefArray,$n,"s","size");
        //$cDefArray["s$n"] = $cDefArray["fields"][$n]["size"];
        CXmlCommand::setFldValIfSet($cDefArray,$n,"v","value");
        //$cDefArray["v$n"] = $cDefArray["fields"][$n]["value"];
        CXmlCommand::setFldValIfSet($cDefArray,$n,"e","export");
        
        if (isset($cDefArray["fields"][$n]["options"]))
        {
            //alert("Options set but not yet learned to handle..");
            for ($m=0; $m < sizeof($cDefArray["fields"][$n]["options"]); $m++)
            {
                $szOption = $cDefArray["fields"][$n]["options"][$m];
                $cDefArray["o".$n."_".$m] = $szOption;
            }
            
        }
    }
    
    unset($cDefArray["fields"]);
    
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "form").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cDefArray)));
}

static function locationSelector($szWhere, $szDefCountryCode, $szDefCountry, $nDefMuniId, $szDefMuniName, $nDefSubDivId, $szDefSubDivName)
{
    $cJsonArr = array(
                    "where"=> $szWhere, 
                    "cCode"=>$szDefCountryCode, "cName"=>$szDefCountry, 
                    "mCode"=>$nDefMuniId, "mName"=>$szDefMuniName, 
                    "sCode"=>$nDefSubDivId, "sName"=>$szDefSubDivName
                );
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "locSel").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJsonArr)));
}

static function fbPost($cJsonArray)
{
// NOTE! Fields must be exactly as follows:
//                    method: 'feed', 
//                    name: getIfSet(cCommand, "name"),
//                    link: getIfSet(cCommand, "link"),
//                    picture: getIfSet(cCommand, "pic"),
//                    caption: getIfSet(cCommand, "cap"),
//                    description: getIfSet(cCommand, "desc")
        
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "fbPost").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJsonArray)));
}

static function postOnWall($cElements)
{
    //For usage.. search...    (or see CWall::jsWallPost())
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "WP").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cElements)));
}

static function wallPostComment($szWhat, $nWpId, &$cJson)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "WPCmnt").
    CXmlCommand::getWrapped("cat", $szWhat).
    CXmlCommand::getWrapped("id", $nWpId).
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJson)));
}

static function transLink(&$cJson)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "transLink").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJson)));
}

static function responseBtn(&$cJson)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "responseBtn").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJson)));
}

static function chatMsg($cJson)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "chatMsg").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJson)));
}

static function gotoDiv($szDiv)
{
    CXmlCommand::javascript("scrollIntoView",array($szDiv));
}

static function ad($cJsonArr)
{
    /*
    Json fields:
    o    Div    div id for where to put it.
    o    Id
    o    Hd
    o    Ds    (description)
    o    OP (Ordinary price
    o    MP (price to pay)
    o    Kw (keyword)
    o    Pics (pictures)
            url
            ttl    (title)
            ds    (description)
            sml    (small picture – big if not given)
        */

    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "ad").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJsonArr)));
}

static function notification($cJsonArr)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "noti").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJsonArr)));
}

static function cart($cJsonArr)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "cart").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cJsonArr)));
}

static function adjustImg($szId, $szWorH, $nPx)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "adjustImg").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode(array($szId, $szWorH, $nPx))));
}

static function enable($szId)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "enable").
    CXmlCommand::getWrapped("id", $szId);
}

static function disable($szId)
{


    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "disable").
    CXmlCommand::getWrapped("id", $szId);
}

static function adInterest($nId, $cArray)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "adInterest").
    CXmlCommand::getWrapped("id", $nId).
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cArray)));
}

static function adjustThumbs($cIdArray)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "adjustThumbs").
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cIdArray)));
}

static function adjustImgs($cIdArray, $szXorH, $nPx)
{
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "adjustImgs").
    CXmlCommand::getWrapped("XorH", $szXorH).
    CXmlCommand::getWrapped("px", $nPx).
    CXmlCommand::getWrapped("json", CXmlCommand::encoded(json_encode($cIdArray)));
}

static function withTable($szId, $szCmd, $nParam)
{
    /*  Commands:
               - hideAll
               - showNFirst  Param = count
               - showNMore      Param = count
               - hideNMore      Param = count
    */
    getSystem()->cCommands[] = CXmlCommand::getWrapped("what", "withTable").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("cmd", $szCmd).
    CXmlCommand::getWrapped("param", $nParam);
    
}

static function withElement($szDiv, $szTag, $szWhat, $szParam)
{
    CXmlCommand::javascript("withElement",array($szDiv, $szTag, $szWhat, $szParam));
}

static function printToCanvas($szHtml)
{
    getSystem()->cCommands[] = array("print",$szHtml);
}

static function timer($bOnOff)
{
    $szTxt = CXmlCommand::getWrapped("what", "timers").
    CXmlCommand::getWrapped("on", ($bOnOff?1:0)).
    (false && isOy()?CXmlCommand::getWrapped("alert", 1):"");
    getSystem()->cCommands[] = $szTxt;
}

static function check($szId, $bSetChecked)
{
    $szTxt = CXmlCommand::getWrapped("what", "check").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("on", ($bSetChecked?"1":"0"));
    getSystem()->cCommands[] = $szTxt;
}

static function setEventHandler($szId, $szEvent, $szHandler)
{
    $szTxt = CXmlCommand::getWrapped("what", "setEventHandler").
    CXmlCommand::getWrapped("id", $szId).
    CXmlCommand::getWrapped("event", $szEvent).
    CXmlCommand::getWrapped("handler", $szHandler);
    getSystem()->cCommands[] = $szTxt;
}

static function setJsonParamFld($szKey, $szVal)
{
    $szTxt = CXmlCommand::getWrapped("what", "jsonParam").
    CXmlCommand::getWrapped("key", $szKey).
    CXmlCommand::getWrapped("val", $szVal);
    getSystem()->cCommands[] = $szTxt;
}

static function timeCountDown($szDiv, $szTime)
{
    $szTxt = CXmlCommand::getWrapped("what", "timeCountDown").
    CXmlCommand::getWrapped("div", $szDiv).
    CXmlCommand::getWrapped("tm", $szTime);
    getSystem()->cCommands[] = $szTxt;
}

}
?>
