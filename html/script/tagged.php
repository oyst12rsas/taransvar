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

function getTheTag($szIp, $szPort)
{
    //NOTE! For now doesn't consider the port number - which it should in a NAT environment...
    $szSQL = "select coalesce(tag,'') as tag from traffic where ipFrom = inet_aton(?) and lastSeen > NOW() - INTERVAL 1 MINUTE order by trafficId desc limit 1";
    //$szSQL = "select inet_ntoa(ipFrom) as aIpFrom, inet_ntoa(ipTo) as aIpTo, whoIsId, created, count, tag from traffic order by trafficId desc limit 10";

	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
    //$stmt->bind_param("si", $szIp, $port); 
	$stmt->bind_param("s", $szIp); 
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	$nCount = 0;

	if ($result && $row = $result->fetch_assoc())
		return $row["tag"];
    return 0;	//Not found
}

function isTagged($szIp, $szPort)
{
	$szTheTag = getTheTag($szIp, $szPort);
    return ($szTheTag && $szTheTag != "");
}

function getUserTagged()
{
    $szIp = getSenderIp();
    $szPort = "0";
    $bTagged = isTagged($szIp, $szPort);
    if ($bTagged)
        return 1;
}

?>