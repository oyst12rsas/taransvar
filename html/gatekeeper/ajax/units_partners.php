<?php
error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

$szLib = "XmlCommand.class.php";
if (file_exists($szLib))
	require_once $szLib;	//So that can test call the php file directly for debugging
else
	require_once("../".$szLib);	



function getDot($bOk)
{
	return '<img src="img/'.($bOk?"green":"red").'_dot.png">';
}

function getTitledDot($bOk, $szTitleOk, $szTitleNotOk)
{
	return '<span title="'.($bOk?$szTitleOk:$szTitleNotOk).'"><img src="img/'.($bOk?"green":"red").'_dot.png"></span>';
}

function getDotByInterval($json, $szTag, $nOk, $nError, $szTitleOk, $szTitleWarn, $szTitleError)
{
//	$json = json_decode($status, true);

 /*   echo "<pre>";
    var_dump($szTag);
    var_dump($status);
    var_dump($json);
    var_dump(json_last_error_msg());
    var_dump(isset($json[$szTag]));
    echo "</pre>";
*/


	if (!isset($json[$szTag]))
		return '<span title="Old version. Script not yet checking '.$szTag.'"><img src="img/yellow_dot.png"></span>';

	$nValue = $json[$szTag]+0;
	if ($nValue <= $nOk)
	{
		$szDot = "green";
		$szTitle = $szTitleOk;
	}
	else
		if ($nValue < $nError)
		{
			$szDot = "yellow";
			$szTitle = $szTitleWarn;
		}
		else
		{
			$szDot = "red";
			$szTitle = $szTitleError;
		}

	return '<span title="'.$szTitle.'"><img src="img/'.$szDot.'_dot.png"></span>';
}


function check($json, $field, $ifTrue, $ifFalse)
{
	if (!isset($json[$field]))
		return '<span title="Old version. Scripts is not yet checking &quot;'.$field.'&quot;"><img src="img/yellow_dot.png"></span>';

	return '<span title="'.($json[$field] == "1" ? $ifTrue : $ifFalse).'">'.getDot($json[$field] == "1").'</span>';
}

function getGatewayIP()
{
	//Don't know if we should make this more advanced.. It's printed when this machine has not received status from a partner.
	return "100.68.165.190";//10.100.0.1
}

function sizeToKB(string $str): int
{
    if (!preg_match('/^\s*([\d.]+)\s*([KMGT]?I?)?\s*$/i', $str, $m)) {
        return 0;
    }

    $value = (float)$m[1];
    $unit  = strtoupper($m[2] ?? '');

    $multiplier = match ($unit) {
        'K', 'KI' => 1,
        'M', 'MI' => 1000,
        'G', 'GI' => 1000000,
        'T', 'TI' => 1000000000,
        default   => 1,
    };

    return (int) round($value * $multiplier);
}

function getServerStatus($seconds_since, $status, $nId)
{
	if (!isset($status) || !strlen($status))
		return '<a href="http://'.getGatewayIP().'/gatekeeper/index.php?f=demo">Check status on router</a>';

	$bOldScript = 0;


	//ØT 260720 - enable this...
	//if ($seconds_since+0 > 65)
	//	return '<font color="red">Server is troubled. Better use another. Status: '.$status.'</font>';

	$cTemp = array();
	$cTemp["secSince"] = $seconds_since;

	$szServerStatus = getDotByInterval($cTemp, "secSince", 71, 130, 
					"Receiving status messages",
					"A bit long since received status message. There may be communication problems.", 
					"Not been sending status message for $seconds_since seconds. Please inform tech team");

	$json = json_decode($status, true);

	$szServerStatus .= check($json, "knl", "tarakernel is running", "tarakernel is NOT running");

	$szServerStatus .= check($json, "lnk", "taralink is running", "taralink is NOT running");

	$szServerStatus .= check($json, "cron", "crontask.pl (perl task) is running", "crontask.pl (perl task) is NOT running");

	$nSeconds = $json["dmesg"] ?? "";
	$szServerStatus .= getDotByInterval($json, "dmesg", 60, 130, 
					"Receiving dmesg messages",
					"$nSeconds seconds since received dmesg. Refresh in a minute may help", 
					"Not receiving dmesg ($nSeconds seconds). Please inform tech team");
	
	$nSeconds = $json["trfc"] ?? "";
	$szServerStatus .= getDotByInterval($json, "trfc", 60, 130, 
					"Receiving traffic reports",
					"$nSeconds seconds since received traffic. If no other issue is reported, it's probably just because there's no users.", 
					"Not receiving traffic reports ($nSeconds seconds). If no other issue is reported, it's probably just because there's no users.");

	//Open mysql connections
	$szServerStatus .= getDotByInterval($json, "sqlThrds", 12, 25, 
					"Normal number of sql threads busy",
					"A bit too many sql threads. System may be in trouble", 
					"Too many sql threads. Please inform tech team");

	//Boot required and updates
	$cTemp = array();
	$cTemp["bootOk"] = (isset($json["bootReq"]) && ($json["bootReq"]==1) ? 0:1);
	$szServerStatus .= check($cTemp, "bootOk", "boot is not required", "the system requires a boot after upgrading");

	$szServerStatus .= "<br>";

	if (isset($json["updates"]))
	{
		$cTemp = explode(";",$json["updates"]);
		$cUpdates = array();
		$cUpdates["total"] = $cTemp[0];
		$cUpdates["security"] = $cTemp[1];

		$szServerStatus .= getDotByInterval($cUpdates, "total", 15, 30, 
					$cUpdates["total"]." updates available",
					$cUpdates["total"]." updates available", 
					$cUpdates["total"]." updates available. You should run sudo apt update");

		$szServerStatus .= getDotByInterval($cUpdates, "security", 0, 1, 
					$cUpdates["security"]." security updates available",
					$cUpdates["security"]." security updates available", 
					$cUpdates["security"]." security updates available. You should run sudo apt update");
	}
	else
		$bOldScript = 1;

	if (isset($json["lstUp"]))
	{
		//Last update run
		$nDays = round($json["lstUp"] / (60*60*24));
		$szServerStatus .= getDotByInterval($json, "lstUp", 60*60*24*7, 60*60*24*30, 
					$nDays." days since update",
					$nDays." days since update. You should run sudo apt update", 
					$nDays." days since update. You should run sudo apt update");
	}
	else
		$bOldScript = 1;

	//Load, disk and memory
	if (isset($json["ld"]))
	{
		$cRes = explode(" ", $json["ld"]);
		unset($cRes[2]);	//Remove 15 minutes load.. present is more interesting than history
		$cMax["max"] = max($cRes);
		$szServerStatus .= getDotByInterval($cMax, "max", 0.7, 2, 
					"Server load is ".$cMax["max"].". This is normal",
					"Server load is ".$cMax["max"].". This is a bit high but may be temporary",
					"Server load is ".$cMax["max"].". This is high and should be checked");
	}
	else
		$bOldScript = 1;

	if (isset($json["df"]))
	{

		$cRes = explode(" ", $json["df"]);
		$nUsed = sizeToKB($cRes[1]);
		$nTotal = sizeToKB($cRes[0]);
		if ($nTotal)
			$nPercentUsed = round(($nUsed / $nTotal) * 100);
		else
			$nPercentUsed = 100;
		$cMax = array();
		$cMax["max"] = $nPercentUsed;
		$szServerStatus .= getDotByInterval($cMax, "max", 70, 90, 
					$nPercentUsed."% of disk used. This is normal",
					$nPercentUsed."% of disk used. This should be monitored",
					$nPercentUsed."% of disk used. Consider freeing space or upgrading.");
		//$szServerStatus .= "<br>Disk: $nUsed of $nTotal ($cRes[1]/$cRes[0])<br>";
	}
	else
		$bOldScript = 1;

	//Memory usage
	if (isset($json["mem"]))
	{
		$cRes = explode("/", $json["mem"]);
		$szUsage = $cRes[0]." of ".$cRes[1]." is free";
		//$szMem = str_replace("Gi","000000", $json["mem"]);	//E.g: "mem":"7.2Gi/30Gi"
		//$szMem = str_replace("Mi","000", $szMem);	//If given in M, also change..
		//$cRes = explode("/", $json["mem"]);
		$nFree = sizeToKB($cRes[0]);
		$nTotal = sizeToKB($cRes[1]);
		$nUsed = $nTotal - $nFree;
		$nPercentUsed = round(($nUsed / $nTotal) * 100);
		$cMax = array();
		$cMax["max"] = $nPercentUsed;
		$szServerStatus .= getDotByInterval($cMax, "max", 80, 95, 
					"$nPercentUsed% of memory used ($szUsage). This is normal",
					"$nPercentUsed% of memory used ($szUsage). This should be monitored",
					"$nPercentUsed% of memory used ($szUsage). Consider relieving tasks or upgrading. Used $nUsed of $nTotal.");

	}
	else
		$bOldScript = 1;

	//rsyslog activated. When set up: "log:0,rsyslog:active,setup:@100.68.181.35"
	if (isset($json["rsyslog"]))
	{
		$data = [];

		foreach (explode(',', $json["rsyslog"]) as $item) 
		{
		    [$key, $value] = explode(':', $item, 2); // limit to 2 in case value contains ':'
	    	$data[$key] = $value;
		}
		$bOk = ($data["log"] == 1 && !strcmp(($data["rsyslog"] ?? ""), "active") && strlen($data["setup"] ?? ""));

		$szMainDbServer = "100.68.126.0";

		if (strlen(strlen($data["setup"] ?? "")) && !strstr($data["setup"], $szMainDbServer))
			$szServerStatus .= getTitledDot(0, "N/A", "rsyslog is set up but not to send to primary DB server: $szMainDbServer");
		else		
			$szServerStatus .= getTitledDot($bOk, 
					"rsyslog is set up: ".$data["setup"],
					"rsyslog is not set up");
	}
	else
		$bOldScript = 1;

	//Services
	if (!isset($json["srvcNtOk"])) {
		//$szServerStatus .= getTitledDot(false, "N/A", "Check of services not implemented. Please upgrade tarasec systems");
		$bOldScript = 1;
	} else {
		$szServerStatus .= getTitledDot(strlen($json["srvcNtOk"]) == 0, "All specified services are running", "Services not running: ".$json["srvcNtOk"]);
	}

	//Active users
	if (isset($json["usr"]))
	{
		$szServerStatus .= getDotByInterval($json, "usr", 1, 3, 
					$json["usr"]." user is active on this computer",
					$json["usr"]." users are active on this computer",
					$json["usr"]." users are active on this computer. Consider choosing one less congested.");

		//$nActiveUsers = isset($json["usr"])?$json["usr"]:"?";
		//$szServerStatus .= "&nbsp;$nActiveUsers";
	}
	else
		$bOldScript = 1;

	if ($bOldScript)
		$szServerStatus .= '<span title="This computer should be upgraded with latest TaraSec software">'.getDot(false).'</span>';			//<img src="img/_dot.png">

	//if ($nId)
	return '<a href="index.php?f=unitsMore&id='.$nId.'">'.$szServerStatus.'</a>';
	//else
	//	print $szServerStatus;
}//getServerStatus();

function getNetworkStatusThisComputer($conn)
{
	$szSQL = "select networkStatus as status, TIMESTAMPDIFF(SECOND, networkStatusChecked, NOW()) AS seconds_since, nickname as name, length(networkStatus) as len from setup";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);
	$setupRow = false;

	if ($result)
	{
		if ($result->num_rows > 0) 
			$setupRow = $result->fetch_assoc();
		$result->free();
	}
	return $setupRow;
}


function units_partners()
{

	$conn=getConnection();

	$setupRow = getNetworkStatusThisComputer($conn);
	$status = getServerStatus($setupRow["seconds_since"], $setupRow["status"], 0);
	
   	//CXmlCommand::addTableRow("dmesgTbl", "top", $nRowId, $cArr, "", $nRowId);//$szHTML)
    CXmlCommand::setInnerHTML("s0", "", $status);//, $cMoreParamsArr = array())
	//CXmlCommand::alert("Er her nå");

	$szWhere = "";//!isAdmin()?"where showToAdminsOnly = b'0'":"";
	$szSQL = "select routerId, name, inet_ntoa(ip) as ip, partnerStatusReceived, status, TIMESTAMPDIFF(SECOND, partnerStatusReceived, NOW()) AS seconds_since from partnerRouter R join partner P on P.partnerId = R.partnerId $szWhere";
	//print "<br>$szSQL<br>";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		while($row = $result->fetch_assoc()) 
		{
			$szDots = getServerStatus($row["seconds_since"], $row["status"], $row["routerId"]);
		    CXmlCommand::setInnerHTML("s".$row["routerId"], "", $szDots);//, $cMoreParamsArr = array())

		}
	}
}


?>
