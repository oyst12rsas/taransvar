<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
//partnerRequest.php
//Runs at router and takes requests from central DB... 
//config_update.php?ip=<hexip>:<port>&f=hack
//partnerRequest.php?f=assistance&ip=7F000001&port=0&cat=bruteForce&qual=0&sp=0
//print "Hi from partnerRequest..";

//Put this directly into database and process later... e.g in 10 minutes when dhsp leases and conntrack is loaded.... 
//
include "dbfunc.php";

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
} 

//NOTE! Should check if sender is a global DB (they are registered in the setup)
$szFromIp = getSenderIp();
$nFromPort = $_SERVER['REMOTE_PORT'];
 
if (isset($_GET["f"]) && $_GET["f"]=="assistance")
{
        if (!isset($_GET["ip"]) || !isset($_GET["port"]) || strlen($_GET["ip"])<7) {
                echo "(missing params)";
                exit;
        }

	$ip = long2ip(hexdec($_GET["ip"]));

	if(!filter_var($ip, FILTER_VALIDATE_IP)){
                echo '(invalid ip: '.$ip.' ('.$_GET["ip"].')';
                exit;
        }
        if(!filter_var($szFromIp, FILTER_VALIDATE_IP) || $szFromIp == '::1'){
                $szFromIp = '127.0.0.1';
        }
        $conn = getConnection();
        $szQual = $_GET["qual"]+0;
        $szSpoofed = $_GET["sp"]+0;
	//$sql = "insert into assistanceRequest (purpose, ip, port, senderIp, senderPort, category, requestQuality, wantSpoofed, comment, fromOther, handled) values ('fromPartner', CONV('".$_GET["ip"]."', 16, 10), ".$_GET["port"].", inet_aton('".$szFromIp."'), ".$nFromPort.",'".$_GET["cat"]."', $szQual, $szSpoofed, 'From DB server', b'1', b'1')";
	//print "<br>$sql<br>";
	//$result = $conn->query($sql) or die("(error storing)");

	$sql = "insert into assistanceRequest (purpose, ip, port, senderIp, senderPort, category, requestQuality, wantSpoofed, comment, fromOther, handled) values ('fromPartner', CONV(?, 16, 10), ?, inet_aton(?), ?,?,?,?, 'From DB server', b'1', b'1')";
	//print "<br>$sql<br>";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("sdsdsdd", $_GET["ip"], $_GET["port"], $szFromIp, $nFromPort, $_GET["cat"], $szQual,  $szSpoofed); 
        $stmt->execute();

	print "ok";
	exit;
}



print "(error in parameters)";

// print "<br>Your ip is: ".$ip.", port: ".$nPort."<br>";
?>


