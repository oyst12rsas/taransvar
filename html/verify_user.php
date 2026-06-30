<?php
/*
	Used to allow users to use their original IP address for logging into the gatekeeper also on computers beyond NAT. 
	Computers send request to sender if it's a registered partner and asks if <ip>:<port> is a legal IP/port at LAN side of sending ISP. 
	If so, this script responds with the internal IP address if it matches the IP specified by sending partner (the one user is trying to log into.)
*/

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

include "../dbfunc.php";
include "../taraLib.php";

$szSenderIp = getSenderIp();

$dbh = getConnection();

//First check if sender is a registered partner. 
$szSQL = "select partnerId from partnerRouter where ip = inet_aton(?)";
$stmt = $dbh->prepare($szSQL);
$stmt->bind_param("s", $szSenderIp);
$stmt->execute();
$result = $stmt->get_result(); // get the mysqli result
if (!$result)
{
	print "Error fetching partner..";
	return;
}

$row = $result->fetch_assoc();

if (!$row)
{
	print "Only responding to partners..";
	return;
}

//Now check if parameter is a known <internalIP>:<assigned_port> combo
//print "Got here...";
$szIpAndPort = $_GET["u"];
//print "About to look for $szIpAndPort";

$cParams = explode(":", $szIpAndPort);

$stmt = $dbh->prepare("select portAssignmentId, inet_ntoa(ipAddress) as ip from unitPort where port = ? order by portAssignmentId desc limit 1");
$stmt->bind_param("i", $cParams[1]); 
$stmt->execute();
$result = $stmt->get_result(); // get the mysqli result

if ($result->num_rows > 0) 
{
	// output data of each row  
	$nCount=0;
	if($row = $result->fetch_assoc()) 
	{
		if (strcmp($cParams[0], $row["ip"]))
			print "Nothing found..(".$cParams[0]."/".$row["ip"].")";
		else
			print "IP:".$row["ip"];
	}
	else
		print "Couldn't fetch row..";
}
else
	print "Nothing found..";

?>