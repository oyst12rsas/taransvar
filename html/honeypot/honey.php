<?php

function getSenderIp()
{
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
    return $ip;
 }
 return 0;
} 

header('Content-Type: application/json');

// prevent PHP warnings breaking JSON
//error_reporting(0);

$szHost = $_SERVER['SERVER_ADDR'];
if (!strcmp($szHost, "::1"))
	$szHost = "localhost";

$reply = "";
$szParterIspIp= 0;

$ip = getSenderIp();
$nPort = $_SERVER['REMOTE_PORT'];
$szSubdir = "/script";	//The script might be places elsewhere than root....
$szUrl = "http://".$szHost.$szSubdir."/config_update.php?f=report&ip=".$ip."&port=".$nPort."&wt=honey.php";
//print "Calling: $szUrl<br><br>";
$szReply = file_get_contents($szUrl);
if ($szReply === false)
{
	echo json_encode([
    	"status" => "fail",
    	"message" => "Error sending http request",
    	"value" => 0
	]);
	return;
}

if (!strcmp($szReply, "\n\nok"))	//Note.. don't know why config_update.php returns 2 linebreaks.. And this may be altered...
	$szReply = "Your unit/net have been reported as probably infected because you clicked a honeypot. Our network will be notified when you visit their units.";

/*

$conn=getConnection();
if (!$conn)
{
	echo json_encode([
    	"status" => "fail",
    	"message" => "Error reading from database",
    	"value" => 0
	]);
	return;
}
$szSQL = "select name, ip, inet_ntoa(ip) as aIp, nettmask, inet_ntoa(nettmask) as aNettmask, partnerStatusReceived from partnerRouter R join partner P on P.partnerId = R.partnerId";
//print "<br>$szSQL<br>";
$conn->query($szSQL) or die(mysql_error());
$result = $conn->query($szSQL);
$num = ip2long($ip);		
$nPartnerNum = 0;

if ($result->num_rows > 0) 
{
	// output data of each row  
	$nCount=0;
    while($row = $result->fetch_assoc()) 
    {
		if (($num & $row["nettmask"]) == ($row["ip"] & $row["nettmask"]))
		{
			$szParterIspIp = $row["aIp"];
			$nPartnerNum = $row["ip"];
			break;
		}
	}
}
$szMsg = "";

if ($szParterIspIp)
{
	$szUrl = "http://".$szParterIspIp.$szSubdir."/config_update.php?f=report&ip=".$ip."&port=".$nPort."&partner=".$nPartnerNum."&wt=honey.php";
	print "Calling: $szUrl<br><br>";
	$szReply = file_get_contents($szUrl);
	$szMsg ="Warning sent owner: ".$szParterIspIp;

} 

//Sending to DBserver(s)
*/

echo json_encode([
    "status" => "ok",
    "message" => $szReply, //"Registering the click. Internal systems will handle",
    "value" => 123
]);