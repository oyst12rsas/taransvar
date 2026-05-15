<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

include "../gatekeeper/dbfunc.php";

$szF = $_GET["f"];

switch ($szF) 
{
	case "rpt":
		$szJsonString = urldecode($_GET["json"]);
		$cJson = json_decode($szJsonString,true);
		//print "Json: $szJsonString<br>Decoded: <br>";
		//var_dump($cJson); 
	
		$nIp = intval($cJson["ip"]);
		$szHash = $cJson["id"];
		$szOnLine = $cJson["line"];
		$szSSID = $cJson["ssid"];
		$szLineSince = $cJson["lineSince"];	//Datetime for discovered off/online

 // ["ssid"]=> NULL ["line"]=> int(1) }

		$statusDHCP = (isset($cJson["sinceDhcp"]) && intval($cJson["sinceDhcp"]) <65);
		$statusIPFM = 1;
		$statusSleeping = 1;
		$statusDmsgUpdated = (isset($cJson["sinceDmesg"]) && intval($cJson["sinceDmesg"]) < 65);;
		$statusPortusageUpdated = (isset($cJson["sincePortUsage"]) && intval($cJson["sincePortUsage"]) <65);
		
		$df = $cJson["df"];
		$memAvail = $cJson["memAvail"];
		$swap = $cJson["swap"];
	
		$conn = getConnection();
		$stmt = $conn->prepare("insert into partnerRouterStatus (routerIP, statusOnline, statusInternetSince, statusDHCP, statusIPFM, statusSleeping, statusDmsgUpdated, statusPortusageUpdated, df, memAvail, swap, info) values (?,?,?,?,?,?,?,?,?,?,?,?)");
		$stmt->bind_param("iisiiiiiiiis", $nIp, $szOnLine, $szLineSince, $statusDHCP, $statusIPFM, $statusSleeping, $statusDmsgUpdated, $statusPortusageUpdated, $df, $memAvail, $swap, $_GET["json"]); 
	        $stmt->execute();
	        print "Router status report stored.";
	        return;

	case "stats":
		//Test: http://localhost/script/routerstatus.php?f=stats&hash=7a863ee4-000f-11f0-bd4e-1c3947ec3220
		$szHash = $_GET["hash"];
	
		$szSQL = "select statusInternetSince, CAST(statusOnline AS UNSIGNED) as statusOnline, CAST(statusDHCP AS 			UNSIGNED) as statusDHCP, CAST(statusIPFM AS UNSIGNED) as statusIPFM, CAST(statusSleeping AS UNSIGNED) as statusSleeping, CAST(statusDmsgUpdated AS UNSIGNED) as statusDmsgUpdated, CAST(statusPortusageUpdated as UNSIGNED) as statusPortusageUpdated, df, memAvail, swap from partnerRouterStatus where statusID = 296 or hash = ? order by statusID desc limit 1";
		
		$conn = getConnection();
		$stats = $conn->execute_query($szSQL, [$szHash])->fetch_assoc();
		print json_encode($stats);
		return;
}
?>
