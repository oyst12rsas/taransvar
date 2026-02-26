<?php

//Keywork USE n is_numeric() for , isNumeric(), isNumber, isInteger

function noop()
{
    //Just to be able to place a break point...
    $_POST["dummy"]="testing";  //never checked
}

function setShowPictures($bShow)
{
	global $_GLOBAL_SHOWPICTURES;
	$_GLOBAL_SHOWPICTURES = $bShow;
}


function getShowPictures()
{
	global $_GLOBAL_SHOWPICTURES;
	if (!isset($_GLOBAL_SHOWPICTURES))
	{
		if (!($nMe = myId()))
			return true;
			
		$_GLOBAL_SHOWPICTURES = (getString("select CAST(ShowPictures AS UNSIGNED) from Profile where ProfileId = :me",array(":me"=>$nMe))+0 > 0);
	}
	
	return $_GLOBAL_SHOWPICTURES;
}


function encoded($szTxt)
{
	$szTxt = str_replace("<", "&lt;", $szTxt);
	$szTxt = str_replace(">", "&gt;", $szTxt);
    $szTxt = str_replace("&", "&amp;", $szTxt);
	return str_replace('"', "&quot;", $szTxt);
}

function nullIfZero($nNum)
{
    if ($nNum+0)
        return $nNum +0;
    else
        return "NULL";
}

function ajaxPrepared($szTxt)
{
    $szTxt = htmlEncoded($szTxt);
    //$szTxt = str_replace("?", "&Oslash;", $szTxt);
    if (strpos($szTxt, "ystein Tor"))
    {
        if (strpos($szTxt, "?")==0)
            $szMsg = "normal found..";
        else
            $szMsg = "other: $szTxt";
        saveHackingReportToDb("",  "", "NULL", "Trying to convert: $szMsg");
    }
    return $szTxt;
}

function dbStr($szTxt, $bDelimited = false, $bNullIfBlank = false)
{
	if (!strlen($szTxt) and $bNullIfBlank)
		return "NULL";
		
	//login();
	$szTxt = encoded($szTxt);
    //$szTxt = mysql_real_escape_string($szTxt);
	return ($bDelimited?"'$szTxt'":$szTxt);
}

function printTxt($szTxt)
{
	print encoded($szTxt);
}

function outputFormatUserSpecifiedHtmlCode($szTxt)
{
//    $szPattern = '/(.*?)[<>](.*?)/';

//    if (!preg_match_all($szPattern, $szTxt."<", $cRecMatches))
//        return $szTxt;

    $szPattern = '/(.*?)\\<(.*?)\\>(.*?)/s';

    if (!preg_match_all($szPattern, $szTxt."<#dummy#>", $cRegMatches, PREG_SET_ORDER))
        return $szTxt;

    $szTxt = "";
    $cLegalTags = array("table", "tbody", "tr", "td", "h1","h2", "h3", "font" ,"br","hr","b","ul","ol","li","p");    //,"hr"

    foreach ($cRegMatches as $cMatch)
    {
        $szTxt .= $cMatch[1];
        
        $szTag = $szCheck = strtolower($cMatch[2]);
        
        if ($szTag == "#dummy#")
            continue;   //
        
        if (substr($szTag,0,1)=="/")
            $szCheck = substr($szTag,1);    //Skip the initial slash

        if (($nPos = strpos($szCheck," ")) !== false)
            $szCheck = substr($szCheck,0,$nPos);
            
        if (!in_array($szCheck,$cLegalTags))
        {
            $szTag = "&lt;$szTag>";
            reportHacking("Attempt to use unauthorized html tag: $szCheck in wiki");
        }
        else
            $szTag = "<$szTag>";
            
        $szTxt .= $szTag;    
    }
        
    return $szTxt;
}

function OBSOLETE_outputFormatUserSpecifiedHtmlCode($szTxt)
{
	//Only let known legal tags pass... other &lt; encode...
	$cLegalTags = array("table", "/table", "tbody", "/tbody", "tr", "/tr", "td", "/td", "h1", "/h1","h2", "/h2","h3", "/h3","font","/font","br","hr","b","/b");	//,"hr"
	
	$n = strpos($szTxt, "<");
	$nLoop=0;
	
	while ($n !== false)
	{
		$bFound = false;
			
		$szSearchFrom = substr($szTxt, $n+1);

		foreach ($cLegalTags as $szTag)
		{
			$szCheck = substr($szSearchFrom, 0, strlen($szTag));
			
			if ($szCheck == $szTag)
			{
				$bFound = true;
				break;
			}
		}
			
		if (!$bFound)	//It's an illegal html tag. Exchange with &lt;
			$szTxt = substr($szTxt, 0, $n).'&lt;'.$szSearchFrom;
		
		$nNextFound = strpos($szSearchFrom, "<");
		
		if ($nNextFound === false)
			break;
		
		$n = $n + ($bFound?1:4) + $nNextFound;
	}
	
	return $szTxt;
}


function removeHtml($szTxt)
{
	return str_replace("<", "&lt;", $szTxt);
}

function getMd5($szId)
{
    return md5("ril£€$ $szId");
	//return md5("ril??$ $szId");
}


function LeadingZero($nTime) 
{
    if ($nTime+0 < 10)
        return '0'.($nTime+0);
    else
        return $nTime."";
}

function printDuration($nSeconds) {
    
    $nDays = floor($nSeconds / 86400);
    $nSeconds -= $nDays * 86400;

    $nHours = floor($nSeconds / 3600);
    $nSeconds -= $nHours * (3600);

    $nMinutes = floor($nSeconds / 60);
    $nSeconds -= $nMinutes * (60);


    $szTimeStr = (($nDays > 0) ? $nDays." days " : "").LeadingZero($nHours).':'.LeadingZero($nMinutes).':'.LeadingZero($nSeconds);
	print $szTimeStr;
}


function logActivity()
{
	//$szSQL = 'update Profile set Lastactivity = NULL where ProfileId = '.myId();
	
	//mysql_query($szSQL) or die("Query failed");
	//Don't use... will loop.. executeSqlOk($szSQL);
}


function her($szTxt) {return "<h3>$szTxt</h3>";}
function warn($szTxt) {return "<h3>$szTxt</h3>";}

function lastInsertId() {return getString("SELECT LAST_INSERT_ID();");}

function getUserName() {return $_SESSION['sess_adm_username']; }
//function getUserId()  NOTE use myId() instead.. getUserId() causes problems bcoz cometchat has same function name...
//{
//	if ($_SESSION['sess_adm_userid'])
		//return $_SESSION['sess_adm_userid']; 
	
//	if (strlen(getFunc("permacode")))
//		return getFunc("pro");
		
//	return 0;
//}

$g_RunningLocal = true;

function onlyDigits($szText)
{
	for ($n = 0; $n < strlen($szText); $n++)
	{
		$szChar = substr($szText, $n, 1);
		
		if (strpos("0123456789_",$szChar) === false)
			return false;
	}
	return true;
}

function inRange($szElement, $nMin, $nMax)
{
	if (!onlyDigits($szElement))
		return false;
	$nElement = $szElement+0;
	
	return ($nElement < $nMin || $nElement > $nMax ? false : true);
}


function onlyLegalEmailChars($szElement)
{
	$szElement = strtolower($szElement);
	
	for ($n = 0; $n < strlen($szElement); $n++)
	{
		$szChar = substr($szElement, $n, 1);
		
		if (strpos("abcdefghijklmnopqrstuvwxyz0123456789_-.",$szChar) === false)
		{
			//her("Found: '$szChar'");
			return false;
		}
	}
	return true;
}

function isLegalEmail($szEpost)	//Keyword: isValidEmail()
{
    //Check if specified in format: "Name <email@domain.com>"
    $szPattern = '/(.*?)\\<(.*?)\\>/';
    if (preg_match($szPattern, $szEpost, $cMatches))   //Check if thers's a = before the ( ... to exclude match on parameters=value
        $szEpost = $cMatches[2];
    
    if (!filter_var($szEpost, FILTER_VALIDATE_EMAIL)) 
        return false;

    $cArr = explode("@", $szEpost);
    
    if (count($cArr) != 2)
        return false;
        
    if (!checkdnsrr($cArr[1], 'MX'))
        return false;

    return true;
    /*    
	$szArr = explode("@", $szEpost);
	
	if (count($szArr) != 2)
		return false;
		
	if (!onlyLegalEmailChars($szArr[0]))
		return false;
		
	$szDomain = $szArr[1];
	
	$szArr = explode(".", $szDomain);
    if (sizeof($szArr)<2)
        return false;
	
	foreach($szArr as &$szElement)
		if (!onlyLegalEmailChars($szElement))
			return false;

	return true;*/
}

function pad0($nDigit) //keyword: zeropadd, leadingZero
{
	return ($nDigit<10?'0'.$nDigit:$nDigit);
}

function now()              //keyword: currentTimeStamp()
{
    return date('Y-m-d H:i:s', time());
}

function today()
{
	$cNow = getdate();                                 
	return  pad0($cNow["year"]).'-'.pad0($cNow["mon"]).'-'.$cNow["mday"];
}

function getYYMMDDDate($szDate)
{
    //For now assumes $szDate is in db date format...
    return (substr($szDate, 2,2).substr($szDate, 5,2).substr($szDate, 8,2));     
}

function getMMDDDate($szDate)
{
    //For now assumes $szDate is in db date format...
    return (substr($szDate, 5,2).substr($szDate, 8,2));     
}

function getShortToday()	//Returns YYMMDD
{
	$cNow = getdate();
	return pad0($cNow["year"]-((int)($cNow["year"]/100))*100).pad0($cNow["mon"]).$cNow["mday"];
}

function displayTime($nSeconds, $bRemoveSecondsIfZero = false, $bConvertToDays = true) 
{
    if ($bConvertToDays)
    {
        $nDays = floor($nSeconds / 86400);
        $nSeconds -= $nDays * 86400;
    }
    else
        $nDays = 0;

    $nHours = floor($nSeconds / 3600);
    $nSeconds -= $nHours * (3600);

    $nMinutes = floor($nSeconds / 60);
    $nSeconds -= $nMinutes * (60);
    return ($nDays > 0 ? $nDays." days " : "").leadingZero($nHours).":".leadingZero($nMinutes).($nSeconds || !$bRemoveSecondsIfZero?":".leadingZero($nSeconds):"");
}

function relDate($szStartDate = "", $nDays = 0) //getRelDate getRelativeDate daysAgo, daysIFuture, futureDate
{
    if (!strlen($szStartDate))
        $szStartDate = today();
    else
        if (strlen($szStartDate)<4)
        {
            $szStartDate = today();
            $nDays = $szStartDate+0;
        }        
        
    if (!validDate($szStartDate, $bMayBeBlank=false))
    {
        reportHacking("Invalid date in $szStartDate");
        return today();
    }
    
	login();
	return getString("select DATE_ADD('$szStartDate', INTERVAL $nDays DAY)");
}
		
function validDate($szDate, $bMayBeBlank, $szFormat="") //Keyword: legalDate, isLegalDate, isValieDate
{
    switch ($szFormat)
    {
        case "MMDD":
            $szDay = substr($szDate,2,2);
            $szMon = substr($szDate,0,2);
            $nDayOfYearCheat = ($szMon+0) * 100+($szDay+0);
            $cNow = getdate();                                 
            $nToday = $cNow["mon"]*100 + $cNow["mday"];
            
            //The club part normally handles dates in future... May be different with other modules but then they should include year in dates...
            if ($nDayOfYearCheat > $nToday && $cNow["mon"]>2)
                $nYear = $cNow["year"]; //Date in future and after Feb
            else
                $nYear = ($cNow["mon"] <= 2?$cNow["year"]-1:$cNow["year"]+1);
            $szDate = "$szDay.$szMon.$nYear";                
            break;
        case "":
            break;
        default:
            reportHacking("Unknown format: $szFormat in validDate()");
            return false;    
    }
    
	//$_POST["date"])
	if (!($szDate = trim($szDate)))
		if (!$bMayBeBlank)
			return false;
		else
			return "";
			
	//Check if format November 15, 1989 (facebook)
	
	$cElements = explode(" ", $szDate);
	
	if (sizeof($cElements) == 3)
	{
		$szMonth = $cElements[0];
		//her("Month $szMonth");
		$nMonth = array_search($szMonth, array("January","February","March","April","May","June","July","August","September","October","November","December"));
		
		if ($nMonth !== false)
		{
			$nMonth++;
			//her("Month: $nMonth");
		
			//remove comma after date.
			$szDay = $cElements[1];
		
			if (substr($szDay, strlen($szDay)-1, 1) == ",")
				$szDay = substr($szDay, 0, strlen($szDay)-1);

			$szYear = $cElements[2];
			return "$szYear-$nMonth-$szDay";
			//asdfasdf
		}
	}
			
	//Check if format YYYY-MM-DD
	//her("checking YYYY-MM-DD");
	
	$cElements = explode("-", $szDate);
	
	if (sizeof($cElements) > 1)
	{
		if (sizeof($cElements) != 3)
			return false;
			
			
		//her("Checking range...");
			
		if (!inRange($cElements[0], 1850, 2100))
				return false;
		if (!inRange($cElements[1], 1, 12))
				return false;
		if (!inRange($cElements[2], 1, 31))
				return false;
		
		return $szDate;
	}

	//check DD.MM and DD.MM.YY and DD.MM.YYYY
	
	$cElements = explode(".", $szDate);
	
	if (sizeof($cElements) > 1)
	{
		$nSize = sizeof($cElements);

		if ($nSize > 3)
			return false;

		if (!inRange($cElements[1], 1, 12))
			return false;
		if (!inRange($cElements[0], 1, 31))
			return false;
	
		if ($nSize == 3)
		{
            if (inRange($cElements[2], 0, 99))
            {
                //If this or next hear.. assume 20 omitted... otherwise 19
                $cNow = getdate();                                 
                $nCurYear = $cNow["year"]%100;
                $nYear = $cElements[2];
                
                //if ($nYear == $nCurYear || $nYear == $nCurYear+1)
                if ($nYear < $nCurYear +5)
                    $cElements[2] += 2000;
                else
                    $cElements[2] += 1900;
            }
			//if (inRange($cElements[2], 10, 99))
			//	$cElements[2] += 2000;
			else
				if (!inRange($cElements[2], 1800, 2200))
					return false;
		}
		else
		{
			//Find year.. 
			$cNow = getdate();
			$nMonth = $cNow["mon"];
			
			$cElements[2] = $cNow["year"];
			
			$nDate = $cElements[1]*100+$cElements[0];
			$nToday = $cNow["mon"] * 100 + $cNow["mday"];
			//her("$nDate, $nToday");
			
			if ($nDate <  $nToday)
				$cElements[2]++;
		}
                      
		return $cElements[2].'-'.LeadingZero($cElements[1]).'-'.LeadingZero($cElements[0]);
		//return $cElements[0].'.'.$cElements[1].'.'.$cElements[2];
	}

	return false;
}

function validTime($szTime, $bMayBeBlank, $bNullIfBlank = false, $szFormat = "HM")  //HM = Hours:Minutes
{
    $szTime = trim($szTime);
    
	//$_POST["date"])
	if (!strlen($szTime))
	{
		if (!$bMayBeBlank)
			return false;
		else
            if ($bNullIfBlank)
                return "NULL";
            else
                return $szTime;
	}
			
	//Check if format HH:MM
	
	$cElements = explode(":", $szTime);
	
	if (sizeof($cElements) > 1)
	{
		if (sizeof($cElements) < 2 or sizeof($cElements) > 3)
		{
			//her("Wrong num of elements");
			return false;
		}
			
		if (!inRange($cElements[0], 0, (!strcmp($szFormat, "HM")?24:99))) //HH:MM or MM:SS (match time)
		{
			//her("hours not in range");
				return false;
		}
		
		if (!inRange($cElements[1], 0, 59))
		{
			//her("minutes not in range");
			return false;
		}
		
		if (sizeof($cElements) == 3 and !inRange($cElements[2], 0, 59))
		{
			//Seconds not in range
			return false;
		}
		
        if ($bNullIfBlank)
            return "'$szTime'";
		else
            return $szTime;
	}
	else
		if (sizeof($cElements) == 1)
        {
            $nLen = strlen($szTime);
            if ($nLen == 3 or $nLen == 4)
            {
                $szHour = substr($szTime, 0, $nLen-2);
                $szMin = substr($szTime, $nLen-2);
                
                if (inRange($szHour, 0,24) && inRange($szMin,0,59))
                    if ($bNullIfBlank)
                        return "'".$szHour.":".$szMin."'";
                    else
                        return $szHour.":".$szMin;
            }
            else
                if (inRange($cElements[0], 1, 24))
                    if ($bNullIfBlank)
                        return "'".$cElements[0].":00'";
                    else
                        return $cElements[0].":00";
        }

	return false;
}

function checkDelimited($szFld)
{
    if (!strlen($szFld) || !strcmp($szFld, "''") || !strcmp($szFld, "NULL"))
        return "NULL";
        
    if (strcmp(substr($szFld,0,1), "'"))
        return "'$szFld'";
    else
        return $szFld;
}

//////////////////////////////////////////////////////////////////////////////////////////
function removeSingleQuotes(&$szBuffer)
//////////////////////////////////////////////////////////////////////////////////////////
{
	//return str_replace("'", "'+CHAR(39)+'", $szBuffer);
	return str_replace("'", "`", $szBuffer);
}//removeSingleQuotes()

function getDbVal($szVal)
{
	if (!strlen($szVal))
		return "NULL";
	return $szVal+0;
}

function getDbCharVal($szVal)
{
	$szVal = trim($szVal);
	if (!strlen($szVal))
		return "NULL";
		
	//login();
	//return "'".mysql_real_escape_string($szVal)."'";
    return $szVal; //Transition to PDO
}



/*
//////////////////////////////////////////////////////////////////////////////////////////
function getPath()	//NOTE! Must be defined in mail php file
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $module, $szLanguageCode, $bIsDebugVersion, $szGlobalPartner, $g_current_script;

	if (isset($g_current_script))
		return $g_current_script;

	if (isset($module))
	{
		if ($module == "translate")
		{
			$szLowCaseLang = strtolower($szLanguageCode);
			return "translate_$szLowCaseLang.php";
		}
		else if ($module == "partner")
		{
			$szLowCaseLang = strtolower($szLanguageCode);
			return "sub_$szGlobalPartner.php";
		}

	}
	else
	{
		if (isset($bIsDebugVersion) && $bIsDebugVersion == 1)
			return "subscr_utvikling.php";
		else
			return "subscr.php";
	}
}//getPath()








*/

//////////////////////////////////////////////////////////////////////////////////////////
function fromInternalIp()
//////////////////////////////////////////////////////////////////////////////////////////
{
	$szRemoteAddr = getenv(REMOTE_ADDR);

	if ($szRemoteAddr == "10.10.1.127")
		return true;

//	if ($szRemoteAddr == "213.188.13.162")	//or "213.188.13.131", not sure what is correct!
//		return true;
		
	return false;
}//fromInternalIp()

/*
//////////////////////////////////////////////////////////////////////////////////////////
function dbLogin()
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $dbConn, $g_RunningLocal;

	if (isset($dbConn))
		return $dbConn;

	//define("mysqlhost", "localhost");

	//print PHP_VERSION . "<br><br>";

//	define("mysqluser", "DBA");
//	define("mysqlpassword", "brasub2027");
//	define("databasename", "subscribtions");

//	if ($g_RunningLocal)
//		$dbConn = sqlanywhere_connect("eng=SUBSCR;dbn=subscriptions;uid=".$mysqluser.";pwd=".$mysqlpassword);
//	else
//		$dbConn = sybase_connect("subscriptions",$mysqluser,$mysqlpassword);

	$szUser = "DBA";
	$szPass = "brasub2027";

	//$dbConn = sqlanywhere_connect("uid=$szUser;pwd=$szPass");
	
	if ($g_RunningLocal)
		$dbConn = sqlanywhere_connect("eng=SUBSCR;dbn=subscriptions;uid=".$szUser.";pwd=".$szPass);
	else
		$dbConn = sybase_connect("subscriptions",$szUser,$szPass);


	if( ! $dbConn )
		//echo "Connection failed\n";
		die("Problems connecting to the DB <br>"); //return NULL;

	//print "Connected successfully";

	//mysql_select_db(databasename) or die("Could not select database");

	return $dbConn;
}//dbLogin()
*/

//////////////////////////////////////////////////////////////////////////////////////////
function mysqlLogin()
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $mySqlLogin, $db_inc, $szLibRoot; //, $mysqlhost, $mysqluser, $mysqlpassword;	
	
	if (!isset($mySqlLogin))
	{
		if (!isset($db_inc))
		{
			include ($szLibRoot."/db.php");
		}
			
		$mySqlLogin = $db;
	
	}

	return $mySqlLogin;
}//mysqlLogin()


//////////////////////////////////////////////////////////////////////////////////////////
function getMysqlString($szSQL)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$res = mysql_query($szSQL, mysqlLogin());
	if ($rec = mysql_fetch_array($res))
		return $rec[0];
	else
		return NULL;
}//getMysqlString()

function giveSqlFailedError($szSQL, $pException = false, $bWarnUser = true)
{
    saveLastSqlErrorInfo($szSQL);   
    $szError = ($pException?$pException->getMessage():"no error msg given");
    
    if (isOy())
    {   
        if ($bWarnUser)
            //CXmlCommand::prompt("Oy: Error in SQL:\n\n$szSQL\nError: $szError", $szSQL);
            print "Oy: Error in SQL:<br>$szSQL<br>Error: $szError<br>";
    }
    else
    {
        global $debug_mysql;
        
        if ($bWarnUser)
            alert('Some error has occurred. This has been reported to the support team.'.($debug_mysql ? ' SQL: '.$szSQL:'!'));
    }    
    reportHacking("Error executing sql: $szError. SQL: $szSQL");        
}

function giveSqlFailedErrorThenDie($szSQL, $pException = false)
{
    giveSqlFailedError($szSQL, $pException);
    CXmlCommand::flushXml();
    die("");
}

function executeSqlOk($szSQL, $cFlds=array())
{
    checkNeedsSanitizing($szSQL,$cFlds);
    //150225
    CDb::doExec($szSQL,$cFlds);
    /*
    if (strpos($szSQL, "ate Matches set HomePoints = HomePoints + 1")!= false)
    {
        noop();
    }
    
	global $debug_mysql;
	
	//login();
	logActivity();

    try {
        $bOk = mysql_query($szSQL);
    }    

    catch (Exception $e)
    {
        $bOk = false;
    }    
    if (!$bOk)
    {
        //For some reson got here on (all ok when ran from mysqladmin: delete from TeamMember where TeamMember.TeamId = 14990 and MemberId = 1177
        giveSqlFailedErrorThenDie($szSQL);
    }
    return $bOk;*/
    return true;
}

//////////////////////////////////////////////////////////////////////////////////////////
function mysqlExecute($szSQL)
//////////////////////////////////////////////////////////////////////////////////////////
{
	return executeSqlOk($szSQL);
	//$res = mysql_query($szSQL, mysqlLogin());
}//mysqlExecute()


function saveLastSqlErrorInfo($szSQL)
{
    if (strstr($szSQL, "insert into SystemMessage (PostedBy, RegardingWhat, RegardingId, Warning")!== false)
        return;     //To avoid infinite loop...
        
    if (strstr($szSQL, "update Setup set LastSqlErrorTime")!== false)
        return;    //NOTE! Save only if not the same... to avoid infinite loop

    //return; 
    
   //Send msg with fixed length in attempt to avoid problem requiring more disk space...
   //login();
   $szSQL = "update Setup set LastSqlErrorTime = Now(), LastSqlErrorBy = :me, LastSqlErrorSql = :sql";
   CDb::doExec($szSQL, array(":me"=>myId(),":sql"=>str_pad($szSQL,500)));
   //executeSqlOk($szSQL);
}


function getNextWeekdayDate($nWeekday, $nMoreInterval)
{
    //Sunday = WeekDay 1....
    //Next Sunday: $nWeekday = 1, $nMOreInterval = 0;
    $nOffset = $nWeekday + $nMoreInterval;
    $szDate = getString("SELECT DATE_ADD(Now(), INTERVAL (7 - DAYOFWEEK(Now()) + $nOffset) DAY)");
    return substr($szDate, 0, 10);
}


//////////////////////////////////////////////////////////////////////////////////////////
function prepareQuery($szSQL, $cFlds = array())
//////////////////////////////////////////////////////////////////////////////////////////
{
//150225 - transition to PDO
/*
    global $g_RunningLocal, $debug_mysql ;
	$dbConn = login();

    $result = mysql_query($szSQL);// or die('Failed to prepare query'.($debug_mysql ? ': '.$szSQL:'!'));
    
    if (!$result)
    {
        saveLastSqlErrorInfo($szSQL);   //Send msg with fixed length in attempt to avoid problem requiring more disk space...
        saveHackingReportToDb("Failed to run mysql_query", "", 0, $szSQL);
        $szMsg = 'Failed to prepare query'.($debug_mysql ? ': '.$szSQL:'!');
        
        //die($szMsg);
        throw new Exception($szMsg);
    }
    
	return $result;
*/
    checkNeedsSanitizing($szSQL,$cFlds);
    return new CParser($szSQL,$cFlds);    
}//prepareQuery()


//////////////////////////////////////////////////////////////////////////////////////////
function fetchRow($result)
//////////////////////////////////////////////////////////////////////////////////////////
{
    if (is_object($result))
        //NOTE! row returned is FETCH_BOTH row...
        return $result->next(); //Assuming it's a CParser object...
    else
    	return mysql_fetch_row($result);
}//fetchRow()


//////////////////////////////////////////////////////////////////////////////////////////
function fetchArray($result)
//////////////////////////////////////////////////////////////////////////////////////////
{
    
    if (is_object($result))
        //NOTE! row returned is FETCH_BOTH row...
        return $result->next(); //Assuming it's a CParser object...
    else
    {
        reportHacking("fetchArray() called with pre PDO result set..");
	    return mysql_fetch_array($result);  //Returns FALSE if none found...
    }
}//fetchArray()


//////////////////////////////////////////////////////////////////////////////////////////
function freeResult($result)
//////////////////////////////////////////////////////////////////////////////////////////
{
/*	global $g_RunningLocal;
	
	if ($g_RunningLocal)
		mysql_free_result($result);
	else
		sybase_free_result($result);
*/
}//freeResult()


function getArray($szSQL,$cFlds=array())
{
	//login();
	//$result = prepareQuery($szSQL);
	//return fetchArray($result);
    checkNeedsSanitizing($szSQL,$cFlds);
    return CDb::fet($szSQL,$cFlds,PDO::FETCH_BOTH);    //150223
}

function dbLog($szTxt)
{
    CDb::doExec("insert into Log (Txt) values (:txt)",array(":txt"=>$szTxt));
}

function checkNeedsSanitizing($szSQL,$cArray)
{
    //if (!runningLocally())
    //    return;
    
    if (sizeof($cArray))
        return;
        
    if (!strpos($szSQL," where "))
        return;
        
    dbLog("Needs sanitize(no params)?: $szSQL");
}

//////////////////////////////////////////////////////////////////////////////////////////
function getString($szSQL, $cArray = array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    
    //print "Simulated sql error executing: $szSQL";
    //return;
    
/*	global $dbConn;

	login();

	$result = prepareQuery($szSQL);

	if ($line = fetchRow($result))
		return $line[0];
	else
		return NULL;
	*/

    checkNeedsSanitizing($szSQL,$cArray);
    
    return CDb::get()->getString($szSQL, $cArray);	
}//getString()

//////////////////////////////////////////////////////////////////////////////////////////
function getString2Ok($szSQL, &$szFld1, &$szFld2, $cArray=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cArray);
    
    if ($line = CDb::fet($szSQL,$cArray,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		return true;
	}
	else
		return false;
}//getString2Ok()

//////////////////////////////////////////////////////////////////////////////////////////
function getString3Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, $cFlds = array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
    {
        $szFld1 = $line[0];
        $szFld2 = $line[1];
        $szFld3 = $line[2];
        return true;
    }
    else
        return false;
}//getString3Ok()

//////////////////////////////////////////////////////////////////////////////////////////
function getString4Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		$szFld3 = $line[2];
		$szFld4 = $line[3];
		return true;
	}
	else
		return false;
}//getString4Ok()



//////////////////////////////////////////////////////////////////////////////////////////
function getString6Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		$szFld3 = $line[2];
		$szFld4 = $line[3];
		$szFld5 = $line[4];
		$szFld6 = $line[5];
		return true;
	}
	else
		return false;
}//getString6Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString8Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		$szFld3 = $line[2];
		$szFld4 = $line[3];
		$szFld5 = $line[4];
		$szFld6 = $line[5];
		$szFld7 = $line[6];
		$szFld8 = $line[7];
		return true;
	}
	else
		return false;
}//getString8Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString10Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, &$szFld9, &$szFld10, $cFlds = array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
    {
        $szFld1 = $line[0];
        $szFld2 = $line[1];
        $szFld3 = $line[2];
        $szFld4 = $line[3];
        $szFld5 = $line[4];
        $szFld6 = $line[5];
        $szFld7 = $line[6];
        $szFld8 = $line[7];
        $szFld9 = $line[8];
        $szFld10 = $line[9];
        return true;
    }
    else
        return false;
}//getString10Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString12Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, &$szFld9, &$szFld10, &$szFld11, &$szFld12, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		$szFld3 = $line[2];
		$szFld4 = $line[3];
		$szFld5 = $line[4];
		$szFld6 = $line[5];
		$szFld7 = $line[6];
		$szFld8 = $line[7];
		$szFld9 = $line[8];
		$szFld10 = $line[9];
		$szFld11 = $line[10];
		$szFld12 = $line[11];
		return true;
	}
	else
		return false;
}//getString12Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString14Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, &$szFld9, &$szFld10, &$szFld11, &$szFld12, &$szFld13, &$szFld14, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
    {
        $szFld1 = $line[0];
        $szFld2 = $line[1];
        $szFld3 = $line[2];
        $szFld4 = $line[3];
        $szFld5 = $line[4];
        $szFld6 = $line[5];
        $szFld7 = $line[6];
        $szFld8 = $line[7];
        $szFld9 = $line[8];
        $szFld10 = $line[9];
        $szFld11 = $line[10];
        $szFld12 = $line[11];

        $szFld13 = $line[12];
        $szFld14 = $line[13];
        return true;
    }
    else
        return false;
}//getString14Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString16Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, &$szFld9, &$szFld10, &$szFld11, &$szFld12, &$szFld13, &$szFld14, &$szFld15, &$szFld16, $cFlds=array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
	{
		$szFld1 = $line[0];
		$szFld2 = $line[1];
		$szFld3 = $line[2];
		$szFld4 = $line[3];
		$szFld5 = $line[4];
		$szFld6 = $line[5];
		$szFld7 = $line[6];
		$szFld8 = $line[7];
		$szFld9 = $line[8];
		$szFld10 = $line[9];
		$szFld11 = $line[10];
		$szFld12 = $line[11];

		$szFld13 = $line[12];
		$szFld14 = $line[13];
		$szFld15 = $line[14];
		$szFld16 = $line[15];
		return true;
	}
	else
		return false;
}//getString16Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function getString18Ok($szSQL, &$szFld1, &$szFld2, &$szFld3, &$szFld4, &$szFld5, &$szFld6, &$szFld7, &$szFld8, &$szFld9, &$szFld10, &$szFld11, &$szFld12, &$szFld13, &$szFld14, &$szFld15, &$szFld16, &$szFld17, &$szFld18, $cFlds = array())
//////////////////////////////////////////////////////////////////////////////////////////
{
    checkNeedsSanitizing($szSQL,$cFlds);

    if ($line = CDb::fet($szSQL,$cFlds,PDO::FETCH_NUM))
    {
        $szFld1 = $line[0];
        $szFld2 = $line[1];
        $szFld3 = $line[2];
        $szFld4 = $line[3];
        $szFld5 = $line[4];
        $szFld6 = $line[5];
        $szFld7 = $line[6];
        $szFld8 = $line[7];
        $szFld9 = $line[8];
        $szFld10 = $line[9];
        $szFld11 = $line[10];
        $szFld12 = $line[11];

        $szFld13 = $line[12];
        $szFld14 = $line[13];
        $szFld15 = $line[14];
        $szFld16 = $line[15];
        $szFld17 = $line[16];
        $szFld18 = $line[17];
        return true;
    }
    else
        return false;
}//getString18Ok()


//////////////////////////////////////////////////////////////////////////////////////////
function countRecords($szSQL)
//////////////////////////////////////////////////////////////////////////////////////////
{

    $pParser = new CParser($szSQL,array(),PDO::FETCH_NUM);	
	$nCount = 0;

	while ($line = $pParser->next())
		$nCount++;
		
	return $nCount;
}//countRecords()

function makeFullJS($szEvent, $szScript)
{
    //NOTE! This function didn't work last time I tried... Weird result....
    if (!strlen(trim($szScript)))
        return "";

    $szPattern = '/(.*?)\\=(.*?)\\((.*?)/';
    if (preg_match($szPattern, $szScript, $cMatches))   //Check if thers's a = before the ( ... to exclude match on parameters=value
        return $szScript;
            
//    $szPos = strpos($szScript, "=");
//    if ($szPos !== FALSE)
//        return $szScript;//."(var ikke false, men $szPos)";
//    else
    return $szEvent.'="'.$szScript.'"';
}
	
//////////////////////////////////////////////////////////////////////////////////////////
function getDropFromArray($cArray, $szName, $szDefaultId="", $szDefaultName="", $szJavaScripts="", $nSize=1, $bUseArrayKeys = false, $cJsonArray = false, $szValue = "")
//////////////////////////////////////////////////////////////////////////////////////////
{	
	$szTxt = '<select name="'.$szName.'" id="'.$szName.'" size="'.$nSize.'" '.makeFullJS("onchange",$szJavaScripts).' >';

	if (strlen($szDefaultId))
		$szTxt .= '<option value="'.$szDefaultId.'">'.$szDefaultName.'</option>';

    if ($cArray)
	foreach ($cArray as $szId => $cFlds)
	{
        if ($bUseArrayKeys)
            $szVal = $cFlds;   //$szId is key..
        else
        {
		    if (!is_array($cFlds))
			    $cFlds = explode("^", $cFlds);
		
		    if (count($cFlds) > 1)
		    {
			    $szId = $cFlds[0];
			    $szVal = $cFlds[1];
		    }
		    else
			    $szId = $szVal = $cFlds[0];
        }

        $szSelected = ($szValue == $szId? 'selected="selected"':'');

        if ($szDefaultId != $szId)
            $szTxt .= '<option value="'.$szId.'" '.$szSelected.'>'.$szVal.'</option>';
    }

	$szTxt .= '</select>';
    if (is_array($cJsonArray))
        $szTxt .= '<div style="display:none" id="'.$szName.'Json">'.json_encode($cJsonArray).'</div>';
        //140128 json
    return $szTxt;
}//printDropFromArray()

function printDropFromArray($cArray, $szName, $szDefaultId="", $szDefaultName="", $bSubmitOnChange = false, $nSize=1)
{
    //$szOnselect = ($bSubmitOnChange ? ' onchange="submitForm()" ': ' ');
    $szJava = ($bSubmitOnChange === true ? ' onchange="submitForm()" ': ($bSubmitOnChange === false ?' ': $bSubmitOnChange));
    print getDropFromArray($cArray, $szName, $szDefaultId, $szDefaultName, $szJava, $nSize);    
}

function getArrayFromLangString($szTxtElement, &$cArr, &$szDefaultId, &$szDefaultName)
{
    $szTxt = getTxt($szTxtElement);
    $cArr = explode("#", $szTxt);
    if (strlen($szDefaultId) && !strlen($szDefaultName))
    {
        //Id set but not name.. Find corresponding name in array..
        foreach($cArr as $szString)
        {
            $cParts = explode("^", $szString);
            if (sizeof($cParts) == 2 && !strcmp($cParts[0], $szDefaultId))
            {
                $szDefaultName = $cParts[1];
                break;
            }
        }
    }
}

function getDropFromLangString($szTxtElement, $szName, $szDefaultId="", $szDefaultName="", $bSubmitOnChange = false, $bUseEnglishVals = false)
{
    if ($bUseEnglishVals)
    {
        $cNative = explode("#", getTxt($szTxtElement));
        $cEnglish = explode("#", getTxt($szTxtElement,"ENG"));
        $cArr = array();
        for ($n=0;$n<sizeof($cEnglish);$n++)
        {
            if ($n >= sizeof($cNative))
                break;
            $cArr[] = $cEnglish[$n]."^".$cNative[$n];
        }
    }
    else
        getArrayFromLangString($szTxtElement, $cArr, $szDefaultId, $szDefaultName);
	return getDropFromArray($cArr, $szName, $szDefaultId, $szDefaultName, $bSubmitOnChange);
}

function printDropFromLangString($szTxtElement, $szName, $szDefaultId="", $szDefaultName="", $bSubmitOnChange = false)
{
    print getDropFromLangString($szTxtElement, $szName, $szDefaultId, $szDefaultName, $bSubmitOnChange);
}

//////////////////////////////////////////////////////////////////////////////////////////
function getDropFromSql($szSQL, $cFlds, &$nFound, $szName, $szDefaultId, $szDefaultName, $szOnChange = "", $bIncludeBlankFirst = false, $nSize=1)//$bSubmitOnChange = false)
//////////////////////////////////////////////////////////////////////////////////////////
{	                    
	//$szOnselect = ($bSubmitOnChange ? ' onchange="submitForm()" ': ' ');
	$szOnselect = (strlen($szOnChange) ? ' onchange="'.$szOnChange.'" ': ' ');

	$szTxt = '<select name="'.$szName.'" id="'.$szName.'" '.$szOnselect.' size="'.$nSize.'">';

	if (strlen($szDefaultId))
		$szTxt .= '<option value="'.$szDefaultId.'">'.$szDefaultName.'</option>';
	else
		if ($bIncludeBlankFirst)
			$szTxt .= '<option value="0">'.getTxt("-------- Choose --------").'</option>';

    //login();
    checkNeedsSanitizing($szSQL,$cFlds);
    $result = prepareQuery($szSQL, $cFlds); //150226
	
	$nFound = 0;
	while (($line = fetchRow($result)) != NULL)
	{
		$nFound++;
		$szId = $line[0];
		$szName = $line[1];

		if ($szDefaultId != $szId)
			$szTxt .= '<option value="'.$szId.'">'.encoded(htmlEncoded($szName)).'</option>';
	}

	$szTxt .= '</select>';
	return $szTxt;
}//getDropFromSql()


function printDropFromSql($szSQL, $szName, $szDefaultId, $szDefaultName, $szOnChange = "", $bIncludeBlankFirst = false, $nSize=1)//$bSubmitOnChange = false)
{
    print getDropFromSql($szSQL, array(), $nFound, $szName, $szDefaultId, $szDefaultName, $szOnChange, $bIncludeBlankFirst, $nSize);//$bSubmitOnChange = false);
    return $nFound;
}


//////////////////////////////////////////////////////////////////////////////////////////
function GetMax($lpTable, $lpField)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$szSQL = "select Max($lpField) from $lpTable";
	return getString($szSQL);
}//GetMax()


//////////////////////////////////////////////////////////////////////////////////////////
function blankOrVal($szVal)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if ($szVal == "(NULL)")
		return "";
	return $szVal;
}//blankOrVal()


//////////////////////////////////////////////////////////////////////////////////////////
function checkPrint($szStringFromDB)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if ($szStringFromDB == "(NULL)" || $szStringFromDB == "")
		print "&nbsp;";
	else
		print $szStringFromDB;
}//checkPrint()


//////////////////////////////////////////////////////////////////////////////////////////
function printableDate($szDate)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$cDateParts = explode("-", $szDate);
	
	if (count($cDateParts) != 3)
		return $szDate;
		
	$szDate = substr($cDateParts[0], 2, 2).$cDateParts[1].$cDateParts[2];
	return $szDate;
}//printableDate()


//////////////////////////////////////////////////////////////////////////////////////////
function printableDatetime($szDateTime)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$cDateAndTime = explode(" ", $szDateTime);
	
	if (count($cDateAndTime) != 2)
		return $szDateTime;
	
	$szDate = $cDateAndTime[0];
	
	$szTime = $cDateAndTime[1];
	$cTimeParts = explode(":", $szTime);
	$szPrintTime = $cTimeParts[0].$cTimeParts[1];
	
	$szRetVal = printableDate($szDate)." ".$szPrintTime;
	return $szRetVal;
}//printableDate()


//////////////////////////////////////////////////////////////////////////////////////////
function htmlEncoded($szString)
//////////////////////////////////////////////////////////////////////////////////////////
{

	//NOTE! This php function may be better: string htmlentities ( string string [, int quote_style [, string charset]] )

	$szString = str_replace("Æ", "&AElig;", $szString);
	$szString = str_replace("Ø", "&Oslash;", $szString);
	$szString = str_replace("Å", "&Aring;", $szString);

	$szString = str_replace("æ", "&aelig;", $szString);
	$szString = str_replace("ø", "&oslash;", $szString);
	$szString = str_replace("å", "&aring;", $szString);

	$szString = str_replace("ä", "&auml;", $szString);
	$szString = str_replace("ö", "&ouml;", $szString);
	$szString = str_replace("Ä", "&Auml;", $szString);
	$szString = str_replace("Ö", "&Ouml;", $szString);

	$szString = str_replace("Ü", "&Uuml;", $szString);
	$szString = str_replace("ü", "&uuml;", $szString);

	$szString = str_replace("ß", "&szlig;", $szString);

	//$szString = str_replace("\n", "<br>", $szString);

    
//	$szString = str_replace("\r\n", "<br>", $szString);
//	$szString = str_replace("\n\r", "<br>", $szString);
//	$szString = str_replace("\r", "<br>", $szString);


	$szString = str_replace("½", "&frac12;", $szString);



	return $szString;
}//htmlEncoded()

function getTypeLink($szFldName, $nId, $szFldVal = "")
{
    switch($szFldName)
    {
        case "Agent":   //Used by CSystemCaleb...
            return '<a href="javascript:menu(\'agent\',\'c=sys&id='.$nId.'\')">'.$szFldVal.'</a>';
        case "Navn":
            return getPersonLink($nId, strlen($szFldVal)?$szFldVal:"[Open]");
        case "Name":
            return getProfileLink($nId, $szFldVal);
            
        case "MunicipalityName":
            return '<a href="javascript:menu(\'showMuni\',\'c=tools&id='.$nId.'\')">'.$szFldVal.'</a>';
    }
    return $szFldVal;
}                            


//////////////////////////////////////////////////////////////////////////////////////////
function getSqlResult($szSQL, &$nRecords, $cSkipHeaderFields = false, $nMaxRecords = 20, $cCallbacks = false)
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $limitRecordCount, $g_RunningLocal;	//Global setup options

	//$dbConn = login();
	$result = prepareQuery($szSQL);
	//$nNumFlds = mysql_num_fields($result);
    $nNumFlds = $result->pStmt->columnCount();
    
    $szTxt = "";//herIfOy($szSQL);
	$szTxt .= "<table>";
	$szTxt .= "<tr>";

	for ($n = 0; $n < $nNumFlds; $n++)
	{
		//$fldInfo = mysql_fetch_field($result, $n);
        //$szFldName = $fldInfo->name;
        $cFldInfo = $result->pStmt->getColumnMeta($n);
        $szFldName = $cFldInfo["name"];
		
		if ($cSkipHeaderFields === false or !in_array($szFldName, $cSkipHeaderFields))
		{
            $szThisFld = td($szFldName);
            
            if (is_array($cCallbacks))
                if (isset($cCallbacks[$szFldName]))
                {
                    $szTmp = call_user_func_array($cCallbacks[$szFldName], array($cRecord = false, $n, $szFldName, $bHeading = true));
                    
                    if ($szTmp !== false)
                        $szThisFld = $szTmp;
                }

			$szTxt .= $szThisFld;
		}                  
	}

	$szTxt .= "</tr>";

	$nRecords = 0;
	$nIdFound = 0;
    $szIdFldName = "";

	while ($line = fetchRow($result))// or die("Query failed: $szSQL")))
	{
		$nRecords++;


		if (isset($limitRecordCount) && $nRecords > $limitRecordCount)
		{
			$szTxt .= '<tr><td colspan="'.$nNumFlds.'"><h3>Search result is truncated</td></tr>';
			break;
		}
		else
		{
			$szTxt .= "<tr>";

			for ($n = 0; $n < $nNumFlds; $n++)
			{
				//$fldInfo = mysql_fetch_field($result, $n);
                $cFldInfo = $result->pStmt->getColumnMeta($n);
                $szFldName = $cFldInfo["name"];

				switch ($szFldName)
				{
				case 'ClubId':
					$szTxt .= '<td>'.getClubLink($line[$n]).'</td>';
					break;
				case 'TeamId':
					$szTxt .= '<td>'.getTeamLink($line[$n]).'</td>';
					break;
				
				case "Agent":
                case "Navn":
				case "Name":
                case "MunicipalityName":
					$szTxt .= "<td>";
					if ($nIdFound)
						$szTxt .= getTypeLink($szFldName, $nIdFound, $line[$n]);
					else
						$szTxt .= htmlEncoded($line[$n]);

					$szTxt .= "</td>";
					break;
				case 'Id':
                case 'AgentId':
				case 'ProfileId':
                case "MunicipalityId":
					//check if next column is Name or Navn first...
                    $szIdFldName = $szFldName;
					if ($n < $nNumFlds-1)
					{
						//$cNextFld = mysql_fetch_field($result, $n+1);
						$szNext = $szFldName;//$cNextFld->name;
						if (in_array($szNext, array("Name","Navn","MunicipalityName","Agent")))
						//if ($szNext == "Name" or $szNext == "Navn" or $szNext == "MunicipalityName")
						{
							//her("ID found");
							$nIdFound = $line[$n];
							break;	//Skip this field and use as Id for next field.
						}
					}
					//Proceed to default....
		
                case "LinkToProfile":
                    $szTxt .= td(getProfileLink($line[$n]));
                    break;
        			
				default: 
                    $szFldVal = $line[$n]; 
                    $szThisFld = td(htmlEncoded($szFldVal));
                    
                    if (is_array($cCallbacks))
                    {
                        //$fldInfo = mysql_fetch_field($result, $n);

                        if (isset($cCallbacks[$szFldName]))
                        {
                            //alert("Callback found for ".$fldInfo->name);
                            $szThisFld = call_user_func_array($cCallbacks[$szFldName], array($line, $n, $szFldName, $bHeading = false));
                        }
                    }
					$szTxt .= $szThisFld;
					break;
				}
		    }

			$szTxt .= "</tr>";
            if ($nRecords >= $nMaxRecords)
            {
                $szTxt .= '<tr><td colspan="'.$nNumFlds.'"><b>List is truncated</b></td></tr>';
                break;
            }
		}

	}

	$szTxt .= "</table>";
    //$szTxt .= warn("$nRecords records found..: $szSQL");

	freeResult($result);
	
	//return $nRecords;
    return $szTxt;
}//getSqlResult()

function displaySqlResult($szSQL, $cSkipHeaderFields = false)
{
    print getSqlResult($szSQL, $nRecords, $cSkipHeaderFields);
    return $nRecords;
}

//////////////////////////////////////////////////////////////////////////////////////////
function printHtmlStartTags()
//////////////////////////////////////////////////////////////////////////////////////////
{
	print '<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body><LINK href="standard.css" rel="stylesheet" type="text/css"><table><tr><td>';
	print "<title>Sybase subscr</title>";
}//printHtmlStartTags()


//////////////////////////////////////////////////////////////////////////////////////////
function getHtaccessClientId()
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $PHP_AUTH_USER;

	$szSQL = "select ClientId from Client where Username = '$PHP_AUTH_USER'";
	
	$szClientId = getString($szSQL);
	
	return $szClientId;
}//getHtaccessClientId()


//////////////////////////////////////////////////////////////////////////////////////////
function isSupervisor()
//////////////////////////////////////////////////////////////////////////////////////////
{
    return isWebmaster();
    /*
	global $g_isSupervisor, $PHP_AUTH_USER;
	
	$szSQL = "select IsSupervisor from Client where Username = '$PHP_AUTH_USER'";
	
	$bIsSupervisor = getString($szSQL);
	
	return $bIsSupervisor;*/
}//isSupervisor()


//////////////////////////////////////////////////////////////////////////////////////////
function printHtmlEndTags()
//////////////////////////////////////////////////////////////////////////////////////////
{
    $szRequest = getJavaRequest();
    //print "Printing request: -$szRequest-<br>";
    if (strlen($szRequest))
        print '<div id="request" style="display:none">'.$szRequest.'</div>';

	print '</td></tr></table></body></html>';
}//printHtmlEndTags()

//////////////////////////////////////////////////////////////////////////////////////////
function printPageFooter()
//////////////////////////////////////////////////////////////////////////////////////////
{
	printHtmlEndTags();
}//printPageFooter()

//////////////////////////////////////////////////////////////////////////////////////////
function checkAddComma(&$szSeparatedList)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (strlen($szSeparatedList))
		$szSeparatedList = "$szSeparatedList, ";
}//checkAddComma()


//////////////////////////////////////////////////////////////////////////////////////////
function checkAddAnd(&$szWhere)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (strlen($szWhere))
		$szWhere = "$szWhere AND ";
}//checkAddComma()


//////////////////////////////////////////////////////////////////////////////////////////
function checkAddCrit(&$szWhere, $szFldName, $szFldVar, $bAlpha)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (!strlen($szFldVar))
		return;

	if (strlen($szWhere))
		$szWhere = "$szWhere and ";

	if ($bAlpha)
		$szWhere = "$szWhere upper($szFldName) like upper('%$szFldVar%')";
	else
		$szWhere = "$szWhere $szFldName = $szFldVar";

}//checkAddCrit()


//////////////////////////////////////////////////////////////////////////////////////////
function prepareTextForEmail($szBuf)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$szBuf = str_replace("æ", "?", $szBuf);
	$szBuf = str_replace("ø", "?", $szBuf);
	$szBuf = str_replace("å", "?", $szBuf);

	$szBuf = str_replace("Æ", "?", $szBuf);
	$szBuf = str_replace("Ø", "?", $szBuf);
	$szBuf = str_replace("Å", "?", $szBuf);

	$szBuf = str_replace("û", "?", $szBuf);
	$szBuf = str_replace("? ", "?", $szBuf);
	$szBuf = str_replace("á", "?", $szBuf);
		  
	$szBuf = str_replace("ä", "?", $szBuf);
	$szBuf = str_replace("ö", "?", $szBuf);

	$szBuf = str_replace("è", "?", $szBuf);
	$szBuf = str_replace("é", "?", $szBuf);
	$szBuf = str_replace("à", "?", $szBuf);

	$szBuf = str_replace("ë", "?", $szBuf);
	
	$szBuf = str_replace("\r\n", "\n", $szBuf);
	
	return $szBuf;
}//prepareTextForEmail()


//////////////////////////////////////////////////////////////////////////////////////////
function urlToDBString($szString)
//////////////////////////////////////////////////////////////////////////////////////////
{
	//NOTE! To find new entries, enter correct value in subscr.exe, then activate code in subscr_utvikling.php (search for ASCII-CODES)
	//go to Search in menu and type the character in the Name-field and search.

	$szString = str_replace("+", " ", $szString);
	$szString = str_replace("%2B", " ", $szString);
	$szString = str_replace("%40", "@", $szString);

	$szString = str_replace("%C3%84", "'+CHAR(196)+'", $szString);	//?
	$szString = str_replace("%C3%96", "'+CHAR(214)+'", $szString);	//?
	$szString = str_replace("%C3%8B", "'+CHAR(203)+'", $szString);	//?
	$szString = str_replace("%C3%A4", "'+CHAR(228)+'", $szString);	//?
	$szString = str_replace("%C3%B6", "'+CHAR(246)+'", $szString);	//?
	$szString = str_replace("%C3%AB", "'+CHAR(235)+'", $szString);	//?

	$szString = str_replace("%C3%86", "?", $szString);	//'+CHAR(146)+' ?
	$szString = str_replace("%C3%98", "?", $szString);	//'+CHAR(157)+'
	$szString = str_replace("%C3%85", "?", $szString);
	$szString = str_replace("%C3%A6", "?", $szString);
	$szString = str_replace("%C3%B8", "?", $szString);
	$szString = str_replace("%C3%A5", "?", $szString);

	$szString = str_replace("%5C%22", '"', $szString);



//	$szString = str_replace("", "", $szString);
//	$szString = str_replace("", "'+CHAR()+'", $szString);

	return $szString;
}//urlToDBString()


//////////////////////////////////////////////////////////////////////////////////////////
function decodeFormattedDateOk($csRegDate, $csFormat, &$nYear, &$nMonth, &$nDay, &$csErrMsg)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if ($csFormat == "YYYY-MM-DD" || $csFormat == "Y-M-D" || $csFormat == "Y-m-d")
	{
		$nYear = strtok($csRegDate, "-");
		$nMonth = strtok("-");
		$nDay = strtok("-");
		
		return true;
	}
	
	print "<h2>Unknown format in decodeFormattedDateOk(): $csFormat</h2>";
	return false;
}//decodeFormattedDateOk()

function minToHHMM($nMatchMinutes, $szSeparator = "")
{
    $nHours = floor($nMatchMinutes / 60);
    $nMinutes = $nMatchMinutes - $nHours * 60;
    return pad0($nHours).$szSeparator.pad0($nMinutes);
}

//////////////////////////////////////////////////////////////////////////////////////////
function getNDaysBetween($csDate1, $csDate2)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (!decodeFormattedDateOk($csDate1, "Y-m-d", $nYear, $nMonth, $nDay, $csErrMsg))
		return 0;
		
	$cDate1 = mktime ( 0, 0, 0, $nMonth, $nDay, $nYear);
		
	if (!decodeFormattedDateOk($csDate2, "Y-m-d", $nYear, $nMonth, $nDay, $csErrMsg))
		return 0;

	$cDate2 = mktime ( 0, 0, 0, $nMonth, $nDay, $nYear);

	$nSecondsBetween = $cDate2 - $cDate1;
	
	//if ($nSecondsBetween < 0)
	//	$nSecondsBetween = -$nSecondsBetween;
		
	return $nSecondsBetween / 60 / 60 / 24;
}//getNDaysBetween()


//////////////////////////////////////////////////////////////////////////////////////////
function getNDaysUntil($csDate)
//////////////////////////////////////////////////////////////////////////////////////////
{
	$csToday = date("Y-m-d");
	
	return getNDaysBetween($csToday, $csDate);
}//getNDaysUntil()


//////////////////////////////////////////////////////////////////////////////////////////
function getNDaysSince($csDate)
//////////////////////////////////////////////////////////////////////////////////////////
{
	return -getNDaysUntil($csDate);
}//getNDaysSince()


//////////////////////////////////////////////////////////////////////////////////////////
function relativeDate($nDays, $csFormat)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if ($csFormat != "YYYY-MM-DD" && $csFormat != "Y-m-d")
	{
		print "<h2>Unknown format in relativeDate(), using default</h2>";
		return 0;
	}

	$nNDaysForward = mktime(0, 0, 0, date("m")  , date("d")+ $nDays, date("Y"));
	$szRetVal = date("Y-m-d", $nNDaysForward);
	
	return $szRetVal;
	
}//relativeDate()


////////////////////////////////////////////////////////////////////////
function areEqual($dNetPrice, $dOldPrice, $fPercentDiffAccepted)
////////////////////////////////////////////////////////////////////////
{
	//print "Checking $dNetPrice against $dOldPrice<br>";

	$dDiff = $dNetPrice - $dOldPrice;

	if ($dDiff < 0)
		$dDiff = -$dDiff;

	if (!$dOldPrice + 0 && $dNetPrice + 0 <> 0)	//Would generate division by 0 below!
		return false;

	$fPercentDiff = $dDiff / $dOldPrice * 100;


	return ($fPercentDiff < $fPercentDiffAccepted);
}//areEqual()

function programExit($szErrorMsg)
{
    //Add it up to the session, and redirect
    $_SESSION['errormsg'] = "<div style='padding-left: 50px;color:#FF0000'>$szErrorMsg</div>";
    session_write_close();
    header("Taransvar social network page");
    exit();
}

//////////////////////////////////////////////////////////////////
function login() 
//////////////////////////////////////////////////////////////////
{
    noop();
    
    //150224 - No longer necessary after transition to PDO...
    /*

	global $p_glob_mysql_connection, $b_loggedIn, $szDBHost, $szDBUserName, $szDBPass, $szDBDBName;

	if ($b_loggedIn)
		return;

//	include "setup.php";

    
	//Before: $link = mysql_connect($szDBHost, $szDBUserName, $szDBPass) or die("Data base connection failed");
    
    //Testing error handling:
    //150223 - throws error: The mysql extension is deprecated and will be removed in the future: use mysqli or PDO instead, [error no: 8192]
    $p_glob_mysql_connection = @mysql_connect($szDBHost, $szDBUserName, $szDBPass) or die("Data base connection failed");

    //Check if it's valid
    if(!$p_glob_mysql_connection)
        programExit("Cannot connect to specfied database (Taransvar social network page)!");

	mysql_select_db($szDBDBName) or die("data base open failed");
	//db_connect('postgres', 'pgadm12', 'localhost', 'taransvar');
	$b_loggedIn = true;
    
    //Testing this to get UTF-8 values
    mysql_query("set names 'utf8'");

*/
}//


function usernameExist($userName, $password)
{
	$szSQL = "select PersonId from Person where Email = '$userName' and Pass = '$password'";
	//her($szSQL);
	$nId = getString($szSQL);
	return $nId+0 > 0;
}



//////////////////////////////////////////////////////////////////////////////////////////
function logSessionStarted()
//////////////////////////////////////////////////////////////////////////////////////////
{
//	print "<h2>New session started!</h2>";
	logRequest();
}//logSessionStarted()


//////////////////////////////////////////////////////////////////////////////////////////
function setRequestWhat($szWhatCode, $szWhatId)
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $site_log_id;
	
	if ($site_log_id + 0 <= 0)
		return;
	
	$szWhatId = $szWhatId + 0;
	
	$szSQL = "update SiteLog set WhatCode = '$szWhatCode', WhatId = $szWhatId where SiteLogId = $site_log_id";
	tryExecuteSqlOk($szSQL);
	//print "$szSQL<br>";
	
}//setRequestWhat()


//////////////////////////////////////////////////////////////////////////////////////////
function usingHttps()
//////////////////////////////////////////////////////////////////////////////////////////
{
	$szHTTPS = getenv(HTTPS);
	//print "$szHTTPS<br>";
	
	return ($szHTTPS == "on");
}//usingHttps()

/*
//////////////////////////////////////////////////////////////////////////////////////////
function loginAndSessionStuffOk()
//////////////////////////////////////////////////////////////////////////////////////////
{
	global $site_log_id, $sessid, $sid, $cliid, $cid, $g_issupervisor, $func;

	$site_log_id = -1;

	if (!session_is_registered('subscr_php_logged_in'))
	{
		session_register('subscr_php_logged_in');
		logSessionStarted();
	}
	else
		logRequest();	

	if (!usingHttps() && runningOn() != "Delta")
	{
		print "<h1>You must use https to access this program!</h1>";
		return false;
	}

	if (!session_is_registered('sessid'))
	{
	    session_register('sessid');
		//print "<h3>sessid initiert!</h3>";
	
		if (isset($sid) && strlen($sid) > 0)
			$sessid = $sid;
		else
			$sessid = 0;
	}

	if (!isset($sid) || strlen($sid) == 0)
		$sid = $sessid;

	//print "<br>Session: $sid<br>";

	if (!session_is_registered('cliid'))
	{
	   	session_register('cliid');
		//print "<h3>clientid initiert!</h3>";
		
		if (isset($cid) && strlen($cid) > 0)
			$cliid = $cid;
		else
			$cliid = 0;
	}

	if (!session_is_registered('g_issupervisor'))
	{
		session_register('g_issupervisor');

		$g_issupervisor = isSupervisor();
	
	}

	if (!isset($cid) || strlen($cid) == 0)
	{
		$cid = $cliid;
	}

	if ($func == "submlogin")
	{
		//print "<h1>Calling submitLoginOk</h1>";

		if (!submitLoginOk())
		{
			//print "<h1>Calling login</h1>";
			login();
			return true;
		}
	}

	if ($func == "logout")
	{
        //probably never gets here anymore...
		$cid = "";
		$cliid = "";
        CMenu::updateLeftMenuSections();
	}

	if ($cid + 0 < 1)
	{
		//print "Not logged in!<br>";
		
		if (!login())
			return false;	//Not automatically logged in
	}
	
	return true;
}//loginAndSessionStuffOk()
*/


//////////////////////////////////////////////////////////////////////////////////////////
function addToSetList(&$szSetList, $szField, $szVal, $bNumeric)
//////////////////////////////////////////////////////////////////////////////////////////
{
	checkAddComma($szSetList);
	
	if (!strlen($szVal))
		$szVal = "NULL";
	else
		if (!$bNumeric)
			$szVal = "'$szVal'";
		else
		{
			//print "NUMERISK!: $szVal<br>";
			$szVal = $szVal + 0;
		}
	
	$szSetList = "$szSetList $szField = $szVal";

}//addToSetList()

function getModifiedFrom($from)
{
    $szServer = $_SERVER["SERVER_NAME"];
    if (strpos($szServer, "www.")===0)
    {
        $szServer = substr($szServer,4);
        //reportHacking("Server name changed from ".$_SERVER["SERVER_NAME"]." to ".$szServer." (in sentHTMLmail())");
    }
    
    if ($szServer == "crm4638.cyberrehab.org")
        $szServer = "cyberrehab.org";
    
    if (strpos($from, $szServer) == false)
    {
        $szMsg = "";
        if ($_SERVER["SERVER_NAME"] != "localhost")
            saveHackingReportToDb("Had to change sender email.. Server name: ".$szServer.", sender: ".$from, "N/A", 0, "sentHTMLmail()",$nAvoidLastNHoursDuplicates = 1,$bThisUserOnly = false);
        //Probably wrong sender domain that will stop the email.
        switch ($_SERVER["SERVER_NAME"])
        {
            case "taransvar.no":
                $szNewFrom = "ot@taransvar.no";
                break;
            case "speakitout.org":
            case "foreldrekontakten.no":
                $szNewFrom = "post@foreldrekontakten.no";
                break;
                
            case "localhost":
                $szNewFrom = "post@foreldrekontakten.no";
                break;
                
            default:
                $szNewFrom = $from;
                $szMsg = "Unknown domain: ".$szServer." Don't know what to put as email sender";
                reportHacking($szMsg);
        }       
        
        if (!strlen($szMsg))
            $szMsg = "Sender email address changed from $from to $szNewFrom";
        
        $from = $szNewFrom; 
    } 
    //else
    //    saveHackingReportToDb("Using sender email address: ".$from, "N/A", 0, "sentHTMLmail()",$nAvoidLastNHoursDuplicates = 1,$bThisUserOnly = false);
    return $from;
}

function saveToEmailOutbox($szMessage, $szSubject, $szMail, $bSendBCC)
{
    //Save the email in the outbox for later processing....
    //150131 insert into 
    if ($bSendBCC === true)
        $szBcc = "'oyst1_2rsas@hotmail.com'";
    else
        if ($bSendBCC === false)
            $szBcc = "NULL";
        else
            if (isLegalEmail($bSendBCC))
                $szBcc = "'$bSendBCC'";
            else
                $szBcc = "NULL";
        
    CDb::doExec("insert into EmailOutbox (EmailText, Subject, EmailAddress, CC, BCC) values (:txt,:subject,:email,NULL,$szBcc)",
                        array(":txt"=>$szMessage,":subject"=>$szSubject,":email"=>$szMail));
}

function sentPlainTextMail($szMail, $szName, $szSubject, $szMessage, $bSendBCC = false, $from = "", $cAttachments = false, $bFromQueue = false)
{
    // use wordwrap() if lines are longer than 70 characters
    $szMessage = wordwrap($szMessage,70);

    // send email

    if (!strlen($from))
        $from = getSystem()->senderEmail();
    
    $from = getModifiedFrom($from);
    
    $szHeader = "From: $from\r\n";
    //$headers .= "CC: somebodyelse@example.com\r\n";
    $szHeader .= "Reply-To: $from\r\n";
    $szHeader .= "Content-type: text/plain; charset=utf-8\r\n"; 
    
    if ($bSendBCC === true)
        //$szBcc = 'Bcc: oyst1_2rsas@hotmail.com';//'post@taransvar.no' . "\r\n";
        $szBcc = 'Bcc: '.getSystem()->bccEmailAddress();
    else
        if ($bSendBCC !== false && isLegalEmail($bSendBCC))
            $szBcc = 'Bcc: '.$bSendBCC;//'oyst1_2rsas@hotmail.com';//'post@taransvar.no' . "\r\n";
        else
            $szBcc = "";

    $szHeader .= $szBcc;
    
    try {
        $bOk = mail($szMail, $szSubject, $szMessage, $szHeader);    
    }
            
    catch(Exception $e)
    {
        $_SESSION['errormsg'] = $e->getMessage();
        return false;
    }
        
    if (!$bOk)
        //NOTE!Sender email must be on current domain... otherwise false...
        reportHacking("php mail() returned false... Email: $szMail, subject: $szSubject");
    
    return $bOk;
}

function sentHTMLmail($szMail, $szName, $szSubject, $szMessage, $bSendBCC = false, $from = "", $cAttachments = false, $bFromQueue = false)//ot@taransvar.no") //keyword: sentEmail() emailSent() 
{
    if (!$bFromQueue)
    {
        //Save the email in the outbox for later processing....
        saveToEmailOutbox($szMessage, $szSubject, $szMail, $bSendBCC);
        return true;
    }    

    if (!strlen($from))
        $from = getSystem()->senderEmail();
    
    $from = getModifiedFrom($from);

	global $debug_showMailContents;
/*	// To send HTML mail, the Content-type header must be set
	$headers  = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
*/

	/* ------------------  Text injections. --------------- */
	
	if (strpos($szMessage, $c_insert_ad_tag = "#insert ad here#"))
	{
       // require_once("promo.php");
	//	if (!function_exists ("calcBirthYear"))
	//		include "promo.php";
			
		//$szMessage = str_replace($c_insert_ad_tag, getTxt("We would also like to remind you about these products to support a good cause:").'<br>'.getPromotions(true /*$bForEmail*/), $szMessage);
        $szMessage = str_replace($c_insert_ad_tag, "", $szMessage);
	}

    //$from = "ot@taransvar.no"; NOT PARAMETER

   //end of message 
   //NOTE! Some say From could be on other domain... But not taking any risk.
    $headers  = "From: $from\r\n";//"post@foreldrekontakten.no\r\n"; //NOTE! For one.com: Sender must always be on current domain..
//    $headers  = "Sender: post@foreldrekontakten.no\r\n"; //NOTE! For one.com: Sender must always be on current domain..
//********** NOTE! *** Should be correct to use Reply-To, but email is not being sent by one.com when added. (no error message) 
// this was also confirmed by support chat but they asked me to send email to support@one.com
    $headers .= "Reply-To: $from\r\n";
    
    if ($cAttachments === false)
        $headers .= "Content-type: text/html; charset=utf-8\r\n"; 
    else
    {
        $random_hash = md5(date('r', time())); 
        $headers .= "Content-Type: multipart/mixed; boundary=\"PHP-mixed-".$random_hash."\"\r\n"; 
/*
        $szMessage = "--PHP-mixed-$random_hash\r\n\r\n".
            "Content-Type: multipart/alternative; boundary=\"PHP-alt-$random_hash\"\r\n\r\n".
            "--PHP-alt-$random_hash\r\n".  
            "Content-Type: text/html; charset=\"utf-8\"\r\n". 
            "Content-Transfer-Encoding: 7bit\r\n\r\n".
            $szMessage.
            "--PHP-alt-$random_hash--\r\n\r\n";

        for ($n = 0; $n < sizeof($cAttachments); $n++)
        {
            $attachment = chunk_split(base64_encode(file_get_contents($cAttachments[$n]))); 
            $szMessage .= "--PHP-mixed-$random_hash\r\n".  
            "Content-Type: application/zip; name=\"".$cAttachments[$n]."\"\r\n".  
            "Content-Transfer-Encoding: base64\r\n".
            "Content-Disposition: attachment  \r\n\r\n".






            $attachment. 
            "--PHP-mixed-$random_hash--\r\n\r\n"; 
        }*/
        
        ?> 
--PHP-mixed-<?php echo $random_hash; ?>  
Content-Type: multipart/alternative; boundary="PHP-alt-<?php echo $random_hash; ?>" 

--PHP-alt-<?php echo $random_hash; ?>  
Content-Type: text/plain; charset="utf-8" 
Content-Transfer-Encoding: 7bit

<?php echo $szMessage; ?>

--PHP-alt-<?php echo $random_hash; ?>  
Content-Type: text/html; charset="utf-8" 
Content-Transfer-Encoding: 7bit

<?php echo $szMessage; ?>

--PHP-alt-<?php echo $random_hash; ?>-- 

<?php

    for ($n=0; $n < sizeof($cAttachments); $n++)
    {
        $cParts = explode("/",$cAttachments[$n]);
        $szFileName = $cParts[sizeof($cParts)-1];
        $attachment = chunk_split(base64_encode(file_get_contents($cAttachments[$n]))); 

?>
--PHP-mixed-<?php echo $random_hash; ?>  
Content-Type: application/zip; name="<?php echo $szFileName; ?>"  
Content-Transfer-Encoding: base64  
Content-Disposition: attachment  

<?php echo $attachment; ?> 
--PHP-mixed-<?php echo $random_hash; ?>-- 
                                  
<?php 
        }

//copy current buffer contents into $message variable and delete current output buffer 
        $szMessage = ob_get_clean();
        ob_start(); //Turn output buffering back on....
    }

    //options to send to cc+bcc 
    //$headers .= "Cc: [email]ot@taransvar.no[/email]"; 
    //$headers .= "Bcc: [email]email@maaking.cXom[/email]"; 
     
    // now lets send the email. 
    //mail($to, $subject, $message, $headers); 

	// Additional headers
	//$headers .= 'To: Mary <mary@example.com>, Kelly <kelly@example.com>' . "\r\n";

	//$szAddrLine = 'To: '.$szName.' <'.$szMail.'>' . "\r\n";
	//$szAddrLine = 'To: '.$szName.' <'.$szMail.'>' . "\r\n";
	//$headers .= $szAddrLine;
//	$headers .= 'From: Taransvar.com <ot@taransvar.no>' . "\r\n";
	//$headers .= 'Cc: birthdayarchive@example.com' . "\r\n";
	
	if ($bSendBCC === true)
		//$szBcc = 'Bcc: oyst1_2rsas@hotmail.com';//'post@taransvar.no' . "\r\n";
        $szBcc = 'Bcc: '.getSystem()->bccEmailAddress();
    else
        if ($bSendBCC !== false && isLegalEmail($bSendBCC))
            $szBcc = 'Bcc: '.$bSendBCC;//'oyst1_2rsas@hotmail.com';//'post@taransvar.no' . "\r\n";
        else
            $szBcc = "";

    $headers .= $szBcc;
            
    if (runningLocally())
    {
        alert($szMessage);    
        $bOk = true;
    }
    else
    {     
        if (!$bFromQueue)
        {
            //140131 - 
            
        }
        else
        {
            global $szSendAllEmailsTo;
            if (isset($szSendAllEmailsTo) && strlen($szSendAllEmailsTo))
            {
                alert("All emails redirected to $szSendAllEmailsTo (global setting)");
                $szMail = $szSendAllEmailsTo;
            }
	        // Mail it
            
            //140131 - 
            $szSubject = '=?utf-8?B?'.base64_encode($szSubject).'?=';
            
            logEmail($szMessage);

            try {
	            $bOk = mail($szMail, $szSubject, $szMessage, $headers);
            }
            
            catch(Exception $e)
            {
                $_SESSION['errormsg'] = $e->getMessage();
                return false;
            }
        }
    }
    	
    if (!$bOk)
        //NOTE!Sender email must be on current domain... otherwise false...
        reportHacking("php mail() returned false... Email: $szMail, subject: $szSubject");
    
    return $bOk;
}


function getRealIpAddr()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
    {
      $ip=$_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
    {
      $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    else
    {
      $ip=$_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

function getLocationInfo($szIP)
{
	//Check article listing several resources...   http://stackoverflow.com/questions/3650006/get-country-of-ip-address-with-php
	//sample US IP: 12.215.42.19
	$url = "http://api.hostip.info/get_html.php?ip=$szIP"; 
	//print "$url<br><br>";
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: api.hostip.info'));
	$output = curl_exec($ch);
	curl_close($ch); 	
	return $output;
}	

function getLocation($szIP)
{
	$szLocationInfo = getLocationInfo($szIP);
	
	$nCityPos = strpos($szLocationInfo, "City:");
	
	if ($nCityPos)
	{
		$szPrefix = "Country: ";
		$szCountry = (substr($szLocationInfo, strlen($szPrefix), $nCityPos - strlen($szPrefix)));
		$cRetVal[] = $szCountry;
		$szRestOfString = substr($szLocationInfo, $nCityPos + strlen("City:"));
		
		$nIPPos = strpos($szRestOfString, "IP:");
		
		if ($nIPPos)
			$cRetVal[] = substr($szRestOfString, 0, $nIPPos);
		
		return $cRetVal;
	}
	return false;
}

function getCountry($szIP)
{
	$cLocationArr = getLocation($szIP);
	
	if  ($cLocationArr === false)
		return "Unknown";
	else
		return $cLocationArr[0];
}


//////////////////////////////////////////////////////////////////////////////////////////
function getMunicipalityDrop($szWhere, $szName, $szDefault, $szOnChange = "")//$bSubmitOnChange = false)
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (strlen($szDefault))
	{
		$szSQL = "select MunicipalityName from Municipality where MunicipalityId = $szDefault;";
		//her($szSQL);
		$szMunicipalityName = getString($szSQL);
	}
	else
		$szMunicipalityName = "";
	
	if ($szName == "county")
		$szSQL = "select MunicipalityId, MunicipalityName from Municipality where $szWhere";//mod(MunicipalityId, 100) = 0";
	else
		$szSQL = "select MunicipalityId, MunicipalityName from Municipality where $szWhere";

	//$bSubmitOnChange = true;
	//her("Default: $szDefault, $szMunicipalityName");

	//her($szSQL);
	return getDropFromSql($szSQL, array(), $nFound, $szName, $szDefault, $szMunicipalityName, $szOnChange, true /*IncludeBlankFirst*/);//$bSubmitOnChange);
}

function printMunicipalityDrop($szWhere, $szName, $szDefault, $szOnChange = "")//$bSubmitOnChange = false)
{
    print getMunicipalityDrop($szWhere, $szName, $szDefault, $szOnChange = "");//$bSubmitOnChange = false)
}

function printMunicipalityDropForCounty($nCounty, $nMunicipality, $szCountry, $szOnChange = "")
{
	//$nCounty = (int)($nMunicipality / 100);
	
	$szWhere = "CountryCode = '$szCountry' and MunicipalityId > $nCounty * 100 and MunicipalityId < ($nCounty + 1) * 100";
	//her("$szWhere, muni: $nMunicipality");

	printMunicipalityDrop($szWhere, "municipality", $nMunicipality, $szOnChange);
}


//////////////////////////////////////////////////////////////////////////////////////////
function getCountyDrop($szCountry, $szDefault, $szOnChange = "")
//////////////////////////////////////////////////////////////////////////////////////////
{
	if (strlen($szDefault))
	{
		$nCounty = (int)($szDefault / 100);
		$szDefault = $nCounty * 100;
	}

	//$bSubmitOnChange = true;
    if (!strlen($szOnChange))
	    $szOnChange = "fillMunicipalityList()";
	
	return getMunicipalityDrop("CountryCode = '$szCountry' and mod(MunicipalityId, 100) = 0", "county", $szDefault, $szOnChange);//$bSubmitOnChange);
}

function printCountyDrop($szCountry, $szDefault)
{
    print getCountyDrop($szCountry, $szDefault);
}

function printSubdivDrop($nMunicipality)
{
	$szSQL = "select SubdivisionId, SubdivName from Subdivision where MunicipalityId = $nMunicipality order by SortOrder";
	printDropFromSql($szSQL, "subdiv", "" /*$szDefault*/, "" /*$szMunicipalityName*/, "" /*$szOnChange*/, true /*$bIncludeBlankFirst*/);
}

function getGenderDrop($szDefault = "")
{  
    $szGenders = getTxt("Jente^Gutt^Begge", "", "NOR");
    $cArr = explode("^", $szGenders);
    return getDropFromArray($cArr, "gender", $szDefault, $szDefault);// $bSubmitOnChange = false)
}//getGenderDrop()


function printGenderDrop($szDefault = "")
{  
    print getGenderDrop($szDefault);
}//printGenderDrop()


function outputDate($szDate)
{
	$glob_lang = getLanguageCode();
	$cToday = getdate ();
	$cDate = explode("-", $szDate);

	if (sizeof($cDate) != 3)
		return $szDate;

	if ($cToday["year"] + 0 == $cDate[0] + 0)
	{
		if ($glob_lang == "NOR")
			return $cDate[2].'.'.$cDate[1];
		else
			return $cDate[1].'/'.$cDate[2];
	}
	else
		if ($glob_lang == "NOR")
			return $cDate[2].'.'.$cDate[1].'.'.$cDate[0];
		else
			return $cDate[1].'/'.$cDate[2].'/'.$cDate[0];

	return $szDate;	//Never gets here...
}


function outputTime($szTime)
{
	$cTime = explode(":", $szTime);
	
	if (sizeof($cTime)<3)
		return $szTime;

	if ($cTime[2] == "00")
		return substr($szTime,0,5);
	else
		return $szTime;
}

function getEmailCss()
{
	return '<style type="text/css">
body
{
font-family:"Arial";
font-size:15px;
}
</style>
';
}


function getTeamActivityPermaCode($nActivityId, $szDate, $szProfile)
{
    return CActivity::getPermaLinkCode($nActivityId, $szDate, $szProfile);
}


function getFilePermaCode($nUploadId)
{
	$szOwner = getString('select OwnerId from Uploads where UploadId = '.$nUploadId);
	$szStr = "$nUploadId, $szOwner ?%&/aQ1";
	return md5($szStr);
}

/*
siteName: http://localhost
curPageURL: http://localhost/sos/index.php?tool=test
getUrlWithScript: http://localhost/sos/index.php
curPagePath: http://localhost/sos
*/



function siteName() //Keywords: getServer getHostname, getServerName()
{
	$pageURL = 'http';
	
	if (isset($_SERVER["HTTPS"]) and $_SERVER["HTTPS"] == "on") 
		$pageURL .= "s";

	$pageURL .= "://".$_SERVER["SERVER_NAME"];

    if (strlen($pageURL)>100)
    {
        saveHackingReportToDb("Happens from cell in PHils.. SERVER_NAME is 132kb long?","",0,$_SERVER["SERVER_NAME"]);
        return "ERROR";
    }
    
	return $pageURL;
}

function getCurrentPath()
{
    //Return the location of current script
    $szURL = curPageURL();
    $szPattern = '/(.*)\\/(.*?)\\?(.*)/';
    if (preg_match($szPattern, $szURL, $cMatches))
    {
        return $cMatches[1];
    }
    else
        alert("Unexpected error..");
    return "";
}

function curPageURL() 
{
	//Note... Returns whole url with parameters and all.....
	$pageURL = siteName();  //150416: Generates error: Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 130968 bytes).......

	if ($_SERVER["SERVER_PORT"] != "80") 
		$pageURL .= ":".$_SERVER["SERVER_PORT"];
		
	$pageURL .= $_SERVER["REQUEST_URI"];
	return $pageURL;
}

function getUrlWithScript($bChangeContentToIndexPhp = false)
{
    //Use this when generating email from content or cron...
	//curPageURL() returns full url with parameter. Strip off the parameters (should also work on http://taransvar.com/sos
	$cParts = explode("?", curPageURL());
	$szUrl = str_replace("cron", "index", $cParts[0]);	//Fix url in case ran from cron job....
    
    if ($bChangeContentToIndexPhp)
        $szUrl = str_replace("content", "index", $szUrl);    //Fix url in case ran from cron job....
        
	return $szUrl;
}

function curPagePath() 
{
	//Note... Returns whole url with parameters and all.....
	$pageURL = getUrlWithScript();

	$nPos = strrpos ($pageURL , "/");
	
	if ($nPos !== false)
		return substr($pageURL, 0, $nPos);
	
	return "";	//shouldn't happen....
}

function getSiteUrl() { return curPageURL(); } //$_SERVER["SERVER_NAME"];

function getRootDomain()
{
    $cParts = explode(".",$_SERVER["SERVER_NAME"]);
    if (sizeof($cParts)>=2)
        return $cParts[sizeof($cParts)-2].".".$cParts[sizeof($cParts)-1];
    if (runningLocally())
        return $cParts[sizeof($cParts)-1];
    else
    {
        reportHacking("Error in getRootDomain()");
        return "";
    }
}

function runningLocally()
{ return (isset($_SERVER["SERVER_NAME"]) and $_SERVER["SERVER_NAME"] == "localhost");
}

function isWesternSite()
{
	//Loan module and more(??) is not available in western based sites...
	return strpos($_SERVER["SERVER_NAME"], "foreldrekontakten") !== false;
}

function checkParamInArray($szVal, $cArray, $szMsg, $szTable, $nId)
{
	if (!in_array($szVal, $cArray))
	{
		//login();
		//$szVal = mysql_real_escape_string($szVal);
		$szTechInfo = "$szTable = $szVal";
		reportHackingToUserAndDb($szMsg, $szTable, $nId, true, $szTechInfo);
		return false;
	}
	
	return $szVal;
}

function checkGetInArray($szTag, $cArray, $szMsg, $szTable, $nId)
{
	if (!isset($_GET[$szTag]))
		return false;
		
	$szVal = get($szTag);

	return checkParamInArray($szVal, $cArray, $szMsg, $szTable, $nId);
}

function checkPostInArray($szTag, $cArray, $szMsg, $szTable, $nId)
{
	if (!isset($_POST[$szTag]))
		return false;
		
	$szVal = post($szTag);

	return checkParamInArray($szVal, $cArray, $szMsg, $szTable, $nId);
}


function checkGetIsLegalDate($szTag, $szTable, $nId, $szTechInfo = "", $bPrintMenu = true) // Keyword: ValidDate()
{
	if (!isset($_GET[$szTag]))
		return false;
		
	$szVal = get($szTag);

	$szDate = validDate($szVal, false /*bMayBeBlank*/);
	
	if ($szDate === false)
	{
		//$szDate = mysql_real_escape_string($szDate);
		
		if (!strlen($szTechInfo))
			$szTechInfo = "Date = $szDate";
			
		reportHackingToUserAndDb("Illegal date", $szTable, $nId, $bPrintMenu, $szTechInfo);
		return false;
	}
	return $szVal;
}

function getMyClubsWhere()
{
	$nMe = myId();
	return " join Team T on (T.ClubId = C.ClubId) join TeamMember TM on (TM.TeamId = T.TeamId) left outer join Parent P on (P.KidId = MemberId) where TM.MemberId = $nMe or ParentId = $nMe";
}


function getMainMenuArray()
{
	//$cArr = array(getTxt("Info").'^Info', getTxt("Files").'^Files', getTxt("Offers").'^func=Offers', getTxt("Clubs").'^Clubs',  getTxt("Diary").'^tool=diary');
	$cArr = array(new CMenuItem(getTxt("Info"), 'Info'), 
				new CMenuItem(getTxt("Files"), 'Files'), 
				new CMenuItem(getTxt("Offers"), 'func=Offers'), 
				new CMenuItem(getTxt("Clubs"), 'Clubs'),  
				new CMenuItem(getTxt("Diary"), 'tool=diary'));

	if (!isWesternSite())
		$cArr[] = new CMenuItem(getTxt("Loan"), 'func=loan');
		//$cArr[] = getTxt("Loan").'^func=loan';

	return $cArr;
}


function getTxt($szTextKey, $szWantLangCode = "", $szOtherLangCode = "", $bSkipColorCoding = false, $szExtraLangGiven = "NOR", $szOtherLangText = "")
{
	global $glob_color_code_translation, $glob_useText;
	
	$glob_lang = getLanguageCode();
	
	if (isset($glob_useText))
		return $szTextKey; //Easiest for debugging...
	
	if ($szWantLangCode == "")
	{
		if ($glob_lang == "")
			$glob_lang = "ENG";	//NBNBNBNBNBNBNB Should read from profile table....
			
		$szLangCode = $glob_lang;
	}
	else
		$szLangCode = $szWantLangCode;
        
    if (!in_array($szLangCode,array("NOR","ENG")))
    {
        //For some reason languagecode ended up being "1".. caused problems bcoz stored variable...
        reportHacking("Language code had invalid value: $szLangCode. Setting to ENG");
        setLanguageCode("ENG");
        $szLangCode = "ENG";
    }        
		
	$szLangTxtField = 'Txt'.$szLangCode;

	//$szTextKey = str_replace("'", "`", $szTextKey);
	//login();
	//$szTextKey = mysql_real_escape_string($szTextKey);
		
	$szSearch = substr($szTextKey, 0, 40); //Size of field is varchar(40)
	$szSQL = "select $szLangTxtField, 1 from LanguageElement where ElementKey = :key";

	if (!getString2Ok($szSQL, $szTxt, $szDummy, array(":key"=>$szSearch)))
	{
		//her("Not found: $szTextKey");
		
		if (strlen($szOtherLangCode))
			$szLandFld = $szOtherLangCode;
		else
			$szLandFld = "ENG";
			
		$szSetFld = "Txt$szLandFld";

        $cFlds = array(":search"=>$szSearch, ":txt"=>$szTextKey);
		
		if (strlen($szOtherLangText))
		{
			$szExtraFld = ', Txt'.$szExtraLangGiven;
            $szExtraVal = ", :extra";
            $cFlds[":extra"] = $szOtherLangText;
		}
		else
			$szExtraFld = $szExtraVal = "";
		
		$szSQL = "insert into LanguageElement (ElementKey, $szSetFld $szExtraFld) values (:search, :txt $szExtraVal)";
        CDb::doExec($szSQL,$cFlds);
		return str_replace("\'", "'", $szTextKey);
	}

	$bColorCode = ($glob_color_code_translation and !$bSkipColorCoding and !in_array($szTextKey, array("Submit")) and !strpos($szTxt, "^"));

	if (!strlen($szTxt))
	{
		if ($glob_lang != "ENG")
		{
			$szSQL = "select MissingTranslationFor from LanguageElement where ElementKey = :key";
			$szMissingTransFor = CDb::getString($szSQL,array(":key"=>$szSearch));
			if ($glob_lang == $szMissingTransFor)
				CDb::doExec("update LanguageElement set DefaultGivenTimes = DefaultGivenTimes + 1 
                            where ElementKey = :key",array(":key"=>$szSearch));
			else
				CDb::doExec("update LanguageElement set MissingTranslationFor = :lang, DefaultGivenTimes = 1  
                                where ElementKey = :key",array(":lang"=>$glob_lang, ":key"=>$szSearch));
				
			//executeSqlOk($szSQL);
			return getTxt($szTextKey, "ENG");
		}

		$szTextKey = str_replace("\'", "'", $szTextKey);
			
		if ($bColorCode)
			return '<font color="red">'.$szTextKey.'</font>';
		else
			return $szTextKey;
	}

	$szTxt = str_replace("\'", "'", $szTxt);

	if ($bColorCode)
		return '<font color="green">'.$szTxt.'</font>';
	else
		return $szTxt;
}

function removeTxt($szOldTxt)
{
	//Not implemented yet... and to be removed when all old texts are removed from source code....
}

function getLanguageCode()
{
    return getSystem()->getLanguageCode();
}

function setLanguageCode($szNewLangCode = "")
{
	global $_SESSION; //$glob_lang;
		
	if (strlen($szNewLangCode))
	{
		$_SESSION["lang_code"] = $szNewLangCode;
		//her('Language set to '.$_SESSION["lang_code"]);
	}
	else
	{
		$nMe = myId();
		if ($nMe)
		{
			$_SESSION["lang_code"] = getString("select LanguageCode from Profile where ProfileId = :me",array(":me"=>$nMe));
			//her('Language set to '.$_SESSION["lang_code"]);
		}
	}
			
	//Don't change if not specified and not logged in....
}

function getCountryCode()
{
	global $_glob_country_code, $default_country_code;
	if (isset($_glob_country_code))
		return $_glob_country_code;
		
	if (myId())
	{
		$szSQL = 'select CountryCode from Profile where ProfileId = '.myId();
		$szCountryCode = getString($szSQL);
		
		if (strlen($szCountryCode))
			return $_glob_country_code = $szCountryCode;
	}
	
	return $pSystem->getDefaultCountryCode();	//Not logged in or CountryCode not set for user. Return default...
}


function isPendingWarning()
{
	global $global_warning;	//Message to be displayed after menu is printed..
	return isset($global_warning);
}

function setWarning($szMsg)
{
	global $global_warning;	//Message to be displayed after menu is printed..
	if (isPendingWarning())
		$global_warning .= "<br>";
	else
		$global_warning = "";
		
	$global_warning .= $szMsg;
}

function getWarning()
{
	global $global_warning;	//Message to be displayed after menu is printed..
	return $global_warning;
}

function resetWarning()
{
	global $global_warning;	//Message to be displayed after menu is printed..
	unset($global_warning);
}


function post($szTag)
{
	global $_POST;
	//return $_POST[$szTag];
	return (isset($_POST[$szTag]) ? $_POST[$szTag] : "");
	//return mysql_real_escape_string($_POST[$szTag]);
}

function get($szTag)
{
//    login();
//	return (isset($_GET[$szTag]) ? mysql_real_escape_string($_GET[$szTag]) : "");
    return (isset($_GET[$szTag]) ? $_GET[$szTag] : "");
	//return mysql_real_escape_string($_GET[$szTag]);     //140318 Invoike this...
}

function getDbStr($szTag, $bDelimited, $bMayBeBlank, $bNullIfBlank)
{
    $szVal = get($szTag);
    if (!$bMayBeBlank)
        if (!strlen($szVal))
            return false;

            
    if ($bNullIfBlank)
        if (!strlen($szVal))
            return "NULL";
            
    if ($bDelimited)
        return "'$szVal'";
    else
        return $szVal;
}

function getDbNum($szTag, $bMayBeBlank, $bNullIfBlank)
{
    $szVal = get($szTag);
    if (!$bMayBeBlank)
        if (!strlen($szVal))
            return false;
            
    if ($bNullIfBlank)
        if (!strlen($szVal))
            return "NULL";
            
    return $szVal+0;
}

function getIdAnAccessLevel($szTable, $szWhere, $szIdField, $nRequiredAccess, $cDefault, &$nAccessLevel)
{
    $nAccessLevel=-1;
    $szId = get($szIdField);

    if ($cDefault === false && !is_numeric($szId))
    {
        reportHackingToUserAndDbUsinLangObj("No proper id provided", $szTable, $szId, true /*$bPrintMenu*/, $szWhere." called from (getId()). Id field=\"$szIdField\"" );
        return false;        
    }

    if (!strlen($szId))
        return $cDefault;
    
    if (strlen($szId)<12)
        $nId = $szId +0;
    else
    {
        if (!is_numeric($szId))
            return false;
        else
            $nId = $szId;   //Will change format to exponential for big nymerbers like facebook ids.
    }
    
    if ($nId > 0 && strlen($szTable))
    {
        if (isWebmaster())
        {
            $nAccessLevel = 99;
            return $nId;
        }
        
        if (($nAccessLevel = accessLevel($szTable, $nId)) < $nRequiredAccess)
        {
            reportHackingToUserAndDbUsinLangObj("You may not access this object", $szTable, $nId, true /*$bPrintMenu*/, $szWhere." called from getId(), has $nAccessLevel, requires $nRequiredAccess");
            return false;            
        }
    }
    
    return $nId;
}

function getId($szTable = "", $szWhere = "", $szIdField = "id", $nRequiredAccess = 1, $cDefault = false)
{
    return getIdAnAccessLevel($szTable, $szWhere, $szIdField, $nRequiredAccess, $cDefault, $nAccessLevel);
}

function getAlfaStrOk($szTag, $bMayBeBlank = true)
{
    //Use this to validate string that you know should only contain characters (i.e. result form drop list )
    $szVal = get($szTag);
  
// funker ikke...    
//    if (!preg_match('/[^A-Za-z]/', $szVal)) // '/[^a-z\d]/i' should also work.
//        return false;         //?-??-?
        
    if (!$bMayBeBlank && trim($szVal) == "")
        return false;    

    return $szVal;        
}

function getSafe($szTag, $bMayBeBlank = true)
{
    if (!$szStr = getAlfaStrOk($szTag, $bMayBeBlank))
        return false;
        
    //login(); 
    return $szStr;//mysql_real_escape_string();
}
    

function in_array_case_insensitive($needle, $haystack) 
{
 return in_array( strtolower($needle), array_map('strtolower', $haystack) );
}

function setInstantAccess($szMenu, $szFunc, $szLabel, $szParams)
{
	$szTxt = "$szMenu#$szFunc#$szLabel#$szParams";
	
	if (!isset($_SESSION["instantAccess"]))
		$_SESSION["instantAccess"][0] = $szTxt;
	else
		if (!in_array($szTxt, $_SESSION["instantAccess"]))
			$_SESSION["instantAccess"][] = $szTxt;
}

function languageCodeOk($szLocalLang)
{
    if (!in_array($szLocalLang,array("ENG","NOR")))
    {
        reportHacking("Unrecognized language $szLocalLang in translateLanguageElement()");
        return false;
    }    
    return true;
}

function translateLanguageElement($szLocalLangText, $szLocalLang, $szTranslateTo)
{
	if (!strlen(trim($szLocalLangText)))
		return "";	//Otherwise will get some un-translated text....
	
    if (!languageCodeOk($szLocalLang))
        return $szLocalLangText;
    
	$szSQL = "select Txt$szTranslateTo from LanguageElement where Txt$szLocalLang = :txt";
	return getString($szSQL, array(":txt"=>$szLocalLangText));
}

function saveHackingReportToDb($szMsg, $szTable, $nId, $szTechInfo, $nAvoidLastNHoursDuplicates = -1, $bThisUserOnly = false, $szCategory = "Unknown")
{
    if ($nAvoidLastNHoursDuplicates > 0)
    {
        $cFlds = array(":msg"=>$szMsg, ":tech"=>$szTechInfo);
        if ($bThisUserOnly)
        {
            $cFlds[":user"] = myId();
            $szMoreWhere = " and PostedBy = :user ";
        }
        else
            $szMoreWhere = "";
            
        $szSQL = "select count(*) from SystemMessage where Warning = :msg and TechInfo = :tech and PostedTime > Now() - INTERVAL $nAvoidLastNHoursDuplicates HOUR $szMoreWhere";
        
        $nFound = CDb::getString($szSQL, $cFlds);
        
        if ($nFound)
            return;
            //$szMsg = "Would have skipped: ".$szMsg;
    }
    
    if (strstr($szMsg, "Maximum execution time of 30 seconds exceeded in"))
    {
        $szMsg = "Maximum found.. should not be saved: ".$szMsg;
    }
    
    $nId = $nId+0;
	$szURL = curPageURL();
	$szIP = getRealIpAddr();
	$szDbMsg = $szMsg;
    $szDbTech = $szTechInfo;
    if (strpos($szIP,","))
    {
        $cParts = explode(",",$szIp);
        reportHacking("Extracted first part from forwarded ip: ".$szIp." (".$cParts[0].")");
        $szIP = $cParts[0];
    }
    
    $szBinIP = inet_pton($szIP);
    
	//$szSQL = "insert into SystemMessage (PostedBy, RegardingWhat, RegardingId, Warning, URL, IP, TechInfo, Category) values ($nMe, '$szTable', $nId, '$szDbMsg', '$szURL', '$szIP', '$szDbTech','$szCategory')";
    $pDb = CDb::get();
//    return 0;   //150313
    $pDb->execute("insert into SystemMessage (PostedBy, RegardingWhat, RegardingId, Warning, URL, IP, BinaryIp, TechInfo, Category) 
                values (:me, :table, :id, :dbMsg, :url, :ip, :binIp, :tech, :cat)",
                array(":me"=>myId(), ":table"=>$szTable, ":id"=>$nId, ":dbMsg"=>$szDbMsg, ":url"=>$szURL,
                 ":ip"=>$szIP, ":binIp"=>$szBinIP, ":tech"=>$szDbTech, ":cat"=>$szCategory));
	//executeSqlOk($szSQL);
    return $pDb->lastInsertId();
}

function isChild($nKid) //Keyword areRelated() inRelation() inFamily() relatedTo() isParent(), isParentOf(), isKid()
{
    $nKid += 0;
	$szSQL = "select KidId from Parent where ParentId = :parent and KidId = :kid";
	return (getString($szSQL, array(":parent"=>myId(),":kid"=>$nKid)) == $nKid);
}

function getDisplayNameFor($nProfile)
{
    $nMe = myId();
    $szSQL = "select coalesce(Per.Name, FB, YMName, Per.Email, FbMail, 'Set name in PhoneBook')
        from Profile join CRM on CRM.BelongsToProfileId = $nMe left outer join Person Per on ReferringToId = Profile.ProfileId and CRM.CRMId = Per.CRMId
    where Profile.ProfileId = $nProfile";
    $szName = getString($szSQL);
    if (!strlen(trim($szName)))
        $szName = "Anonymous";
        
    return $szName;
}
/*
function addOnLoadRequest($szCategory, $szSpec, $nId = 0)
{
	//her("Adding request......");
	
	if (!isset($_SESSION['on_load_request_arr']))
	{
		$_SESSION['on_load_request_arr'] = array();
		//her("initiated");
	}
		
	$cSpec = array($szCategory, $szSpec, $nId);
	$_SESSION['on_load_request_arr'][] = $cSpec;
	//her("Ending");
}

function handleOnLoadRequests()
{
	$bWikiIncluded = false;
	
	if (isset($_SESSION['on_load_request_arr']))
	{
		foreach($_SESSION['on_load_request_arr'] as $cSpecArr)
		{
			//print "wiki|Request FOUND FROM content.php!".$cSpecArr[0].", ".$cSpecArr[1].", ".$cSpecArr[2];
		
			switch ($cSpecArr[0])
			{
				case "wiki":
					//print "wiki|WIKI DIV FOUND FROM content.php!";
					if (!$bWikiIncluded)
					{
						include "wiki.php";

						$bWikiIncluded = true;
					}
					
					printSomeWiki($cSpecArr[1], $cSpecArr[2]);

					break;
			}
		}
		
		unset($_SESSION['on_load_request_arr']);
	}
}
*/

function oy()
{
    return (runningLocally()?1:4);
}

function isOy()
{
    //return runningLocally() or (runningLocally() and myId() == 1) or (!runningLocally() and myId() == 4);
    return runningLocally() or (!runningLocally() && myId() == 4);
}

function herIfOy($szMsg)
{
	if (isOy())
		return her("Oy logged in so showing: ".$szMsg);
        
    return "";
}				


function rand_string( $length ) 
{
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";    
    $str = "";
    $size = strlen( $chars );
    for( $i = 0; $i < $length; $i++ ) {
        $str .= $chars[ rand( 0, $size - 1 ) ];
    }

    return $str;
}

function getDBPrepared($szTag)
{
    //login();
    //NOTE! Can't use encoded bcoz makes changes <table to &lt;table
    //return mysql_real_escape_string(outputFormatUserSpecifiedHtmlCode(get($szTag)));
    return outputFormatUserSpecifiedHtmlCode(get($szTag));
}

function cutTimeZeroTail($szTime) //Keyword: shortTime trunTime cutTime removeMinutes
{
    while (substr($szTime, -3) == ":00")
        $szTime = substr($szTime, 0, strlen($szTime)-3);
    return $szTime;
}

function getTableFieldInfo($szTable, $szField)
{
    //Returns array as:
    //Field     Type     Null     Key     Default     Extra 
    //MeetingId    bigint(20)    NO    PRI    NULL    auto_increment
    
    $szSQL = "SHOW FIELDS FROM ".$szTable." where Field = '".dbStr($szField)."'";
    return getArray($szSQL);
}

function tableFieldExists($szTable, $szField)
{
    $cArr = getTableFieldInfo($szTable, $szField);
    return count($cArr["Field"]) != 0;
}

function getForeignProfileKeyArray()
{
    return array('SalesRepId', 'ProspectId', 'PostedBy', 'ObjectOwnerId', 'RegisteredById');
}
        
function getLookup($szTableName, $szFldName, $szFldVal)
{
    //return "checking lookup...";
    if (in_array($szFldName, getForeignProfileKeyArray()))
        return getProfileLink($szFldVal+0);
    
    return false;
}

function doSendPasswordEmail($szEmail, $szPass = false)
{
    global $debug_showMailLinks;
    $szSQL = "select ProfileId, Name, Password, 1 from Profile where Email = :email";
    //her( $szSQL);
    getString4Ok($szSQL, $szPersonId, $szName, $szPassword, $szDummy,array(":email"=>$szEmail));

    if (!$szPersonId+0)
        return false;

//zxcvzxcv asdfasdf Should be changed to force user to register new password:
    //   1. Send email with temporary random generated password...
    //    2. User must change password... (store in temporary field until confirmed in case attempt to scam...)
    
    $cFlds = array();
    //150307 - sanitize this...
    
    if ($szPass === false)    //Send temporary password.
    {
        $szPassToSend = rand_string(5);
        $szConfirmCode = getConfirmPassCode($szPersonId, $szPassToSend);
        $szSetList = "ResetPasswordCode = '$szConfirmCode', TempPassGenerated = CURRENT_TIMESTAMP()";
        $szPasswordLabel = 'Temporary Password: ';
        $szFunc= "confirmresetpass";
        $szTempName = "EMAIL: Reset Password";
    }
    else
    {
        $szPassToSend = $szPass;
        $szSetList = "Password = '$szPassMd5'";
        $szPasswordLabel = 'Password: ';
        $szFunc = "confirmReg";
        $szTempName = "EMAIL: Reg confirmation";
    }
        
    $szPassMd5 = getMd5($szPassToSend);

    $szSQL = "update Profile set $szSetList where ProfileId = :id;";
    //her($szSQL);
    executeSqlOk($szSQL, array(":id"=>$szPersonId));
        
    $szSubject = getTxt("Username and password for").' '.siteName();
    $szParentMD = getMd5($szPersonId);

    $szURL = getSystem()->getUrlWithScript($bChangeContentToIndexPhp = true) // curPageURL().
        .'?c=login&func='.$szFunc.'&id='.$szPersonId.'&code='.$szConfirmCode;
    //Error.. just send url.. a tags are in text..'<a href="'.$szURL.'">'.getTxt("Click here to register new password").'</a>'               

    $szTemplate = getTxt($szTempName);
    $szTemplate = str_replace('$szPasswordLabel', $szPasswordLabel, $szTemplate);
    $szTemplate = str_replace('$szPassToSend', $szPassToSend, $szTemplate);
    $szTemplate = str_replace('$szName', $szName, $szTemplate);
    $szTemplate = str_replace('$szEmail', $szEmail, $szTemplate);
    $szTemplate = str_replace('$szURL', $szURL, $szTemplate);
    $szMessage = "<html>
<head>
  <title>$szSubject</title>
</head>
<body>".$szTemplate;

    if ($debug_showMailLinks)
    {
        $szMsg = '</h3>Set to print email info for debug</h3>
        <table>
        <tr><td>'.$szPasswordLabel.'</td><td>'.$szPassToSend.'</td></tr>
        <tr><td>MD5:</td><td>'.$szPassMd5.'</td></tr>
        <tr><td>Confirmation URL:</td><td><a href="'.$szURL.'">'.$szURL.'</a></td></tr>';
        CBasic::display($szMsg);
    }

    sentHTMLmail($szEmail, $szName, $szSubject, $szMessage);
    return true;
}


function sendConfirmationEMail($szEmail, $szPass)
{
    //Return disabled any registration... should find other way to avoid email to coaches if desired...
    //return; //150202 - Sendte til alle trenere etter hvers som registrert....
    //qwerqwer
    $szMd5Pass = getMd5($szPass);
    //$szSaveEmail = dbStr(strtolower($szEmail));
    
    $szSQL = "select ProfileId, Password from Profile where Email = :email";
    //her($szSQL);
    getString2Ok($szSQL, $szPersonId, $szReadPass, array(":email"=>$szEmail));
    
    /*if ($szMd5Pass != $szReadPass)
    {
        her("Some error happened!");
        reportHacking("Password was wrong in sendConfirmationEMail() PersonId: $szPersonId, email: $szEmail, Pass: $szPass");
        return;
    }*/
    
    $szPersonId += 0;
    
    if (!$szPersonId)
    {
        $szIP = getRealIpAddr();
        $cLocation = getLocation($szIP);
        if ($cLocation === false)
            $szCity = $szCountry = "Unknown";
        else
        {
            $szCountry = $cLocation[0];
            $szCity = $cLocation[1];
        }
        $szSQL = "insert into Profile (Email, Password, RegistrationIP, LocationCountry, LocationCity) values (:email, :md5pass, :ip, :country, :city)";
        //her($szSQL);
        executeSqlOk($szSQL,array(":email"=>$szEmail, ":md5pass"=>$szMd5Pass, ":ip"=>$szIP, ":country"=>$szCountry, ":city"=>$szCity));
        $szPersonId = lastInsertId();
    }
    
    $szSubject = getTxt("Confirmation of registration to")." ".getSystem()->getSiteName();//" Taransvar.com");
    $szParentMD = getMd5($szPersonId);

    //$szURL = 'http://taransvar.com/sos/?func=confirmReg&id='.$szPersonId.'&code='.$szParentMD;


    $szURL = getSystem()->getUrlWithScript(true /*$bChangeContentToIndexPhp*/).'?func=confirmReg&id='.$szPersonId.'&code='.$szParentMD;
    $szMessage = '<html>
<head>
  <title>'.$szSubject.'</title>
</head>
<body>'.getTxt("TX Mail Reg Conf Heading").
'<p>'.getTxt("User name").': '.encoded($szEmail).'</p><p>'.getTxt("Password").': '.encoded($szPass).'</p>
<p>'.getTxt("Kindly confirm your registration by clicking this link").':</p>
<p><a href="'.$szURL.'">'.$szURL.'</a></p>

<p>'.getTxt("Best regards").'</p>
<p>'.getSystem()->getSiteName().'</p>
</body>
</html>';
    
    //her("A");
    sentHTMLmail($szEmail, "" /*$szName*/, $szSubject, $szMessage);
    $szSQL = "update Profile set WelcomeEmailSent = b'1' where ProfileId = :id";
    executeSqlOk($szSQL, array(":id"=>$szPersonId));
    //her("B");
    return true;
}

function userUploads()
{
    $szSQL = 'select count(UploadId) from Uploads where OwnerId = '.myId();
    //her($szSQL);
    
    return getString($szSQL)+0;
}

function maxUploads() 
{
    global $global_max_files_per_user; return $global_max_files_per_user;
}

function alert($szMsg)
{
    //if (isAjax())
        CXmlCommand::alert($szMsg);
    //else
    //    herIfOy("<h3>$szMsg</h3>");
}
    
function debug($szMsg)
{
    if (runningLocally() or isOy())
    {
        $timestamp =  time();//E_STRICT error on mktime();
        $szMsg = $timestamp." ".$szMsg;
        CXmlCommand::setInnerHTML("debug","", $szMsg);
        CXmlCommand::addToInnerHTML("debugLog","", $szMsg);
    }
}    

function rowWarning($nCols, $szMsg)
{
    return '<tr><td colspan="'.$nCols.'">'.$szMsg.'</td></tr>';
}


function getParmString() //NOTE think can read from http request...  curPageURL() is better?
{
    if (!isset($_GET))
        return ""; //$_GET is unset if logged in..
        
    $cParams = array_merge($_GET, $_POST);
    $szParams = "";
    foreach($cParams as $key=>$val) 
        if (!in_array($key, array("c","func"))) //Handled specially...
            $szParams .= (strlen($szParams)?"&":"").$key."=".$val;
    return $szParams;
}

function getArrayFromSql($szSQL)//, $szSeparator = "")
{
    $pSql = new CSql();
    $cArr = array();
    while ($cRec = $pSql->parseSql($szSQL))
        $cArr[] = $cRec;
    return $cArr;
}

function sendBuffer($szDivId)
{
    $szHtml = ob_get_contents();
     ob_end_clean();
     if (strlen($szDivId))
        CXmlCommand::setInnerHTML($szDivId);
     else
        CBasic::display($szHtml);
}

function encryptData($source, $privateKey)
{
    $maxLength = 117;

    $output = "";
    while ($source)
    {
        $slice = substr($source, 0, $maxLength);
        $source = substr($source, $maxLength);

        openssl_private_encrypt($slice, $encrypted, $privateKey);
        $output .= $encrypted;
    }

    return $output;
}

function decryptData($source, $publicKey)
{
    $maxLength = 128;

    $output = "";
    while ($source)
    {
        $slice = substr($source, 0, $maxLength);
        $source = substr($source, $maxLength);

        openssl_public_decrypt($slice, $decrypted, $publicKey);

        $output .= $decrypted;
    }

    return $output;
}

// usage
//$myPrivateKey = ""; // your generated private key
//$myPublicKey = "";  // your generated public key

//$rawText = "lorem ipsum";

//$crypted = encryptData($rawText, $myPrivateKey);
//$decrypted = decryptData($crypted, $myPublicKey);

//to generate your private/public key pair, just execute the following commands:

//openssl genrsa -out private_key.pem 1024
//openssl rsa -pubout -in private_key.pem -out public_key.pem
//you will found two key on your current directory. If you need to add them onto variable, beware the whitespaces.


function ul($cArray, $szStyles="")
{
    $szHtml = "<ul $szStyles>";
    
    foreach ($cArray as $szRow)
        $szHtml .= '<li>'.$szRow.'</li>';

    return $szHtml."</ul>";
}

function a($szTxt, $szScript, $szMore="")
{
    return '<a href="'.$szScript.'" '.$szMore.'>'.$szTxt.'</a>';
}

function fontSize($szHtml, $nSize)
{
    return '<font size="'.$nSize.'">'.$szHtml.'</font>';
}

function h1($szHtml)
{
    return '<h1>'.$szHtml.'</h1>';
}


function h2($szHtml)
{
    return '<h2>'.$szHtml.'</h2>';
}


function h3($szHtml)
{
    return '<h3>'.$szHtml.'</h3>';
}

function tag($szString, $szTag)
{
    return "<$szTag>$szString</$szTag>";
}

function help_cl($szHtml)
{
    return '<div class="help_cl">'.$szHtml.'</div>';
}

function td($szTxt, $nColspan=-1, $szAttributes="")
{
    $szColspan = ($nColspan>1 ? ' colspan="'.$nColspan.'"':"").(strlen($szAttributes)?" ".$szAttributes:"");
    return '<td'.$szColspan.'>'.$szTxt.'</td>';
}

function th($szTxt, $nColspan=-1, $szAttributes="")
{
    $szColspan = ($nColspan>1 ? ' colspan="'.$nColspan.'"':"").(strlen($szAttributes)?" ".$szAttributes:"");
    return '<th'.$szColspan.'>'.$szTxt.'</td>';
}

function tr($szTxt, $szRowSettings="")
{
    return '<tr '.$szRowSettings.' >'.$szTxt.'</tr>';
}

function table($szTxt, $szStyles="")
{
    return '<table'.(strlen($szStyles)?" $szStyles":"").'>'.$szTxt.'</table>';
}

function hidden($szId, $szValue)
{
    return '<input type="hidden" id="'.$szId.'" value="'.$szValue.'">';
}

function color($szTxt, $szColor)
{
    return '<font color="'.$szColor.'">'.$szTxt.'</font>';
}

function green($szTxt)
{
    return color($szTxt, "green");
}

function red($szTxt)
{
    return color($szTxt, "red");
}

function gray($szTxt)
{
    return color($szTxt, "gray");
}

function yellow($szTxt)
{   
    return color($szTxt, "yellow");
}

function orange($szTxt)
{   
    return color($szTxt, "orange");
}

function div($szTxt, $szId="", $szClass="")
{
    return '<div'.(strlen($szId)?' id="'.$szId.'" ':' ').(strlen($szClass)?(strpos($szClass,"=")?$szClass:' class="'.$szClass.'" '):'').'>'.$szTxt.'</div>';
}

function b($szTxt)
{
    return "<b>$szTxt</b>";
}

function p($szTxt)
{
    return "<p>".$szTxt."</p>";    
}
    
function input($szId, $szValue="", $szOptions="")
{
    /*if (strstr($szValueOrOptions, '="'))
        $szOpt = $szValueOrOptions;
    else
        $szOpt = 'value="'.$szValueOrOptions.'"';*/
        
    return '<input type="text" id="'.$szId.'" value="'.$szValue.'" '.$szOptions.'>';
}

function radio($szId, $szGroup, $szValue, $bChecked = false, $szMore = "")
{
    return '<input type="radio" id="'.$szId.'" name="'.$szGroup.'" value="'.$szValue.'"'.($bChecked?' checked="checked"':'').''.$szMore.'>';
}

function checkbox($szId, $szValue="X", $bSet=false, $szMore="")
{
    return '<input type="checkbox" '.(strlen($szId)?'id="'.$szId.'"':"").' value="'.$szValue.'" '.($bSet?"checked ":"").$szMore.'>';
}

function button($szLabel, $szOnClick, $szId="", $szMore="")
{
    return '<input type="button" '.(strlen($szId)?'id="'.$szId.'"':"").' value="'.$szLabel.'" onclick="'.$szOnClick.'" '.$szMore.'>';
}

function textarea($szId, $nCols=0, $nRows=0, $szText="")
{
    return '<textarea id="'.$szId.'"'.($nCols>0?' cols="'.$nCols.'"':'').''.($nRows>0?' rows="'.$nRows.'"':'').'>'.$szText.'</textarea>';
}

function form($szRows, $szFunc, $nId, $szFlds, $bSubmitOnNewRow = true, $bAddSubmit=true, $szSubButtonLabel = "")
{
    if ($bAddSubmit)
    {
        if (!strlen($szSubButtonLabel))
            $szSubButtonLabel = getTxt("Submit");
            
        $szBtn = '<input type="submit" id="submit" value="'.$szSubButtonLabel.'">';

        if ($bSubmitOnNewRow)
            $szRows .= tr(td("&nbsp;").td($szBtn));
        else
            $szRows = tr($szRows.td($szBtn)); //Assume $szRows is list of table columns only. Add button and put in tr tags.
    }
        
    return '<form onsubmit="return formSubmitted(this,\''.$szFunc.'\','.$nId.', \''.$szFlds.($bAddSubmit?',submit':"").'\')">'
        .table($szRows).
        '</form>';
}

function quickMenu($szHtml)
{
    //Look for [Legal^legal] and replace with <a href="javascript:mnu1('legal')">Legal</a>
    //150406
    $szPattern = '/(.*?)\\[(.*?)\\^(.*?)\\](.*?)/s';
    if (preg_match_all($szPattern, $szHtml."[a^b]", $cMatches, PREG_SET_ORDER))
    {
        $szHtml = "";
        foreach($cMatches as $cMatch)
        {
            if ($cMatch[2]=="a" && $cMatch[3]=="b")
                $szTxt = $cMatch[1];
            else
                $szTxt = $cMatch[1].getGenericLink("mnu1('".$cMatch[3]."')",$cMatch[2]);
            $szHtml .= $szTxt;
        }
        
    }
    return $szHtml;
}

function reload($szScript, $szTooltip)  //rurun, repeat
{
    return getGenericIcon("reload", $szTooltip, $szScript);
}

function tooltip($szHtml, $szTooltip)
{
    return '<div style="display:inline" '.getTooltipScripts($szTooltip).'>(working?)'.$szHtml.'</div>';
}

function info($szScript, $szTooltip = "") //Keyword: getInfoButton(), getInfoLink() getInfoIcon getHelpButton, getHelpLink getHelpIcon
{
    return getGenericIcon("info", $szTooltip, $szScript);
    //return '<div style="display:inline" '.(strlen($szScript)?'onclick="'.$szScript.'"':'').'><img src="pics/info.png" width="16", height="16" border="0" title="'.$szTooltip.'"></div>';
}

function logToFile($szTxt, $szFileName)
{
    $szContents = "<?php return; /* $szTxt */ ?>";
    $szFile = "temp/".$szFileName.".php";
    file_put_contents($szFile, $szContents);
}

function dumpSqlToFile($szSQL, $szFileNamePostfix="")  //Keyword: logFile, logToFile, doLog logSqlToFile
{
    //Note.. see also saveLastSqlErrorInfo($szSQL)
    logToFile($szSQL, "sqldump".(strlen($szFileNamePostfix)?" $szFileNamePostfix":""));
}

function kb($nBytes)
{
    if ($nBytes < 900)
        return $nBytes."b";

    if ($nBytes < 900000)
        return substr($nBytes/1024,0,4)."kb";
    
    return substr($nBytes/1024/1024,0,4)."mb";
}

function leftAndRightTexts($szLeft,$szRight, $bUseTable= false)
{
    if ($bUseTable)
        return table(tr(td($szLeft).td($szRight,1,'align="right" width="50%"'),'width="100%"'));    
    return '<p class="alignleft">'.$szLeft.'</p><p class="alignright">'.$szRight.'</p>';
}

function getJsonParamArray($szJsonParamName = "json") //Keyword jsonArray()
{                                              
    if (!isset($_GET[$szJsonParamName]))
        return false;
        
    $szJson = $_GET[$szJsonParamName];
    return json_decode($szJson,true);
}



function getUploadSql($szWhere)
{
    return "select coalesce(concat(URL,FileName), concat(Location, FileName)) as FullFilePath from Uploads left outer join FileLocation on (FileLocationId = LocationId) where $szWhere";
}


function getUploadedFile($nId, $nWidth, $nHeight)
{


    return getFile($nId, getString(getUploadSql("UploadId = $nId")), $nWidth, $nHeight, false /*$bForcePrint*/);
}

function getFieldNoFromRecord($cRecord, $nFldNo)
{
    //Used by callbacks when record fetched with PDO::FETCH_BOTH
    if (isset($cRecord[$nFldNo]))
        return $cRecord[$nFldNo];
    else
    {
        $m=0;
        foreach ($cRecord as $szFld)
            if ($m==$nFldNo)
            {
                return $szFld;
                break;
            }
            else
                $m++;
        
        reportHacking("Fld no $nFldNo not found in getFieldNoFromRecord()");
        return "";
    }
}

?>
