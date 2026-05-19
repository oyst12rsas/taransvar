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

include "../dbfunc.php";

$ip = getSenderIp();
$numIp = sprintf("%u", ip2long($ip));  // safer unsigned
$nPort = $_SERVER['REMOTE_PORT'];




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

//First check if connected to me...

$szSQL = "select adminIP, nettmask from setup";
$stmt = $conn->prepare($szSQL);
$stmt->execute();
$result = $stmt->get_result();
$rec = $result->fetch_assoc();

if ($rec) 
{
    if (($numIp & $rec["nettmask"]) == ($rec["adminIP"] & $rec["nettmask"]))
    {
        echo json_encode([
            "status" => "fail",
            "message" => "This is the right place to request untagging. Click the URL below to confirm.",
            "routerURL" => "http://localhost/honepot/index.php?func=untag",
            "value" => 0
        ]);
        return;
    }

}

//$szSQL = "select name, ip, inet_ntoa(ip) as aIp, nettmask, inet_ntoa(nettmask) as aNettmask, partnerStatusReceived from partnerRouter R join partner P on P.partnerId = R.partnerId";

$szSQL = "SELECT name, ip, inet_ntoa(ip) as aIp, nettmask, inet_ntoa(nettmask) as aNettmask, partnerStatusReceived, BIT_COUNT(nettmask) AS mask_bits FROM partnerRouter R join partner P on P.partnerId = R.partnerId WHERE (? & nettmask) = (ip & nettmask) ORDER BY mask_bits DESC LIMIT 1";
$stmt = $conn->prepare($szSQL);
//print "<br>$szSQL<br>";

if (!$stmt) {
    echo json_encode([
        "status" => "fail",
        "message" => "SQL prepare failed: " . $conn->error,
        "value" => 0
    ]);
    return;
}


$stmt->bind_param("s", $numIp);
$stmt->execute();

$result = $stmt->get_result();
$rec = $result->fetch_assoc();

if ($rec) 
{
    echo json_encode([
        "status" => "ok",
        "message" => "Partner router: ".$rec["name"]."(".$rec["aIp"]."). Contact them to get cleared.", //"Registering the click. Internal systems will handle",
	"routerURL" => "http://".$rec["aIp"]."/honeypot/index.php",
        "value" => 123
    ]);
    return;

}
else
{
    echo json_encode([
        "status" => "ok",
        "message" => "You're not connected to a partner router (so traffic doesn't get tagged)." , //"Registering the click. Internal systems will handle",
	"routerURL" => "",
        "value" => 123
    ]);
    return;

}




?>
