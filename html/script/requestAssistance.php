<?php
print "Hi from requestAssistance..";
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
//config_update.php
//Takes report from partners... 
//config_update.php?ip=<hexip>:<port>&f=hack


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

$szFromIp = getSenderIp();
$nFromPort = $_SERVER['REMOTE_PORT'];
 
if (isset($_GET["f"]) && $_GET["f"]=="request")
{
        if (!isset($_GET["ip"]) || !isset($_GET["port"]) || strlen($_GET["ip"])<7) {
                echo "(missing params)";
                exit;
        }
        if(!filter_var($_GET["ip"], FILTER_VALIDATE_IP)){
                echo '(invalid ip: '.$ip.')';
                exit;
        }
        if(!filter_var($szFromIp, FILTER_VALIDATE_IP) || $szFromIp == '::1'){
                $szFromIp = '127.0.0.1';
        }
        $conn = getConnection();
        $szQual = $_GET["qual"]+0;
        $szSpoofed = $_GET["sp"]+0;
	$sql = "insert into assistanceRequest (purpose, ip, port, senderIp, senderPort, category, requestQuality, wantSpoofed) values ('forDistribution', inet_aton('".$_GET["ip"]."'), ".$_GET["port"].", inet_aton('".$szFromIp."'), ".$nFromPort.",'".$_GET["cat"]."', $szQual, $szSpoofed)";
	print "<br>$sql<br>";
	$result = $conn->query($sql) or die("(error storing)");
	print "ok";
	exit;
}

print "(error in parameters)";

// print "<br>Your ip is: ".$ip.", port: ".$nPort."<br>";
?>


