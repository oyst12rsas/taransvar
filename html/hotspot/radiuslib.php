<?php

function isWebmaster()
{
	return isSuperUser();
}

function reportHacking($szTxt)
{	
	print $szTxt;
}


function request($szId)
{
	return (isset($_REQUEST) && isset($_REQUEST[$szId])?$_REQUEST[$szId]:"");
}


$szInternalIpRange = "192.168.0.";

function loggedIn()
{
	return isset($_SESSION["user"]) && strlen($_SESSION["user"]);
}

function onServer()
{
	return ($_SERVER['REMOTE_ADDR'] == "127.0.0.1" || $_SERVER['REMOTE_ADDR'] == "::1");
}

function isInside()
{
	global $szInternalIpRange;
	if (strpos($_SERVER['REMOTE_ADDR'], $szInternalIpRange) === false)
		return false;
	else
		return true; 
		
}


function isInternal()
{
	$szIp = $_SERVER['REMOTE_ADDR'];
	return (strpos($szIp, "192.168.0.") === 0);
}

function func($szFunc)
{
	return "index.php?f=".$szFunc;
}

function isSuperUser() 
{
	if (!isset($_SESSION["superuser"]))
		return false;
		
	if ($_SESSION["superuser"] == true)
		return true;
		
	return false;
}


function printCountDownJs($nSecondsUntilUpdate, $szDivId = "countdowntimer", $szEndOfTimeText = "") 
{
	?><br><div id="endOfTimeTextHere" style="display:none"><?php print $szEndOfTimeText;?></div>  
<script type="text/javascript">
    var timeleft = <?php print $nSecondsUntilUpdate;?>;
    var downloadTimer = setInterval(function(){
    timeleft--;
    document.getElementById("<?php print $szDivId; ?>").textContent = timeleft;
    if(timeleft <= 0) {
        clearInterval(downloadTimer);
	document.getElementById("endOfTimeTextHere").style.display = "block";
      }
    },1000);
</script><?php
}

function getSecondsTillUpdate()
{
	$szSec = date("s");
	return 60-$szSec;
}


function getSetup($szCat = "")
{
	$cFlds = array();
	$cDb = new CDb;
	
	if ($szCat == "print")
		$szExtraFlds = ", printPadding, printFontSize, printNumbersAcross, printNumbers, internalIP";
	else
		$szExtraFlds = "";
	
	return $cDb->fetch("select loginmsg, selfreg, CAST(ananlyzeAll as UNSIGNED) as ananlyzeAll, defaultpurpose, defaultSubscriptionType, internalIP $szExtraFlds from hotspotSetup", $cFlds);
}

function selfRegistrationEnabled($cSetup = false)
{
	if (!$cSetup)
		$cSetup = getSetup();
		
	return (strcmp((isset($cSetup["selfreg"])?$cSetup["selfreg"]:""), "none"));
}


function setLoggedIn($szName)
{
	$_SESSION["loggedin"] = true;
	$_SESSION["user"] = $szName;
	$_SESSION["superuser"] = ($szName == "admin");
}

function getNow()
{
	return date("Y-m-d H:i",time());
}

function expired($szDate)
{
	return strcmp($szDate ,getNow()) < 0;
}

//function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
function random_str($length, $keyspace = '123456789abcdefghijkmnpqrtuwyABCDEFGHJKLMNPQRSTYZ')
{
    $str = '';
    $max = strlen($keyspace)-1;//mb_strlen($keyspace, '8bit') - 1;
    for ($i = 0; $i < $length; ++$i) {
        $str .= $keyspace[random_int(0, $max)];
    }
    return $str;
}

/*function lastInsertId()
{
	$cFlds = array();
	return CDb::getString("SELECT LAST_INSERT_ID()",$cFlds);
}*/

function requireAccessUpdate()
{
	$pDb = new CDb;
	$cParam = array();
	$pDb->execute("update hotspotSetup set requiresAccessUpdate = b'1' ", $cParam);
}

?>
