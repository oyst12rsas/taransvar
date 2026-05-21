<?php 

require_once("../script/getSenderIp.php");
require_once "../script/reportHacking.php";


function getPartnerRouterOf($szIp)
{
    $conn = getConnection();
    $szSQL = "SELECT name, ip, inet_ntoa(ip) as aIp, nettmask, inet_ntoa(nettmask) as aNettmask, partnerStatusReceived, BIT_COUNT(nettmask) AS mask_bits FROM partnerRouter R join partner P on P.partnerId = R.partnerId WHERE (inet_aton(?) & nettmask) = (ip & nettmask) ORDER BY mask_bits DESC LIMIT 1";
    $stmt = $conn->prepare($szSQL);
    //print "<br>$szSQL<br>";

    if ($stmt)
    {
        $stmt->bind_param("s", $szIp);
        $stmt->execute();

        $result = $stmt->get_result();
        $rec = $result->fetch_assoc();

        if ($rec) 
            return $rec["aIp"];

        return 0;
    }
}

function getUrl($url)
{
	//*************************** Try function getUrl() instead of curl or wget??
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: api.hostip.info'));
	$output = curl_exec($ch);
	curl_close($ch); 	
	return $output;
}

function reportHacking($szMsg)
{
	require_once "../script/getUrl.php";
	require_once "../script/getSenderIp.php";
	$szSenderIp = getSenderIp();
	$conn = getConnection();

	if (strcmp($szSenderIp, "127.0.0.1"))
	{
		$nFromPort = $_SERVER['REMOTE_PORT'];
		$urlTemplate = "http://[URL_HERE]/script/config_update.php?f=report&ip=".$szSenderIp."&port=".$nFromPort."&wt=".$szMsg;

		$szSQL = "select inet_ntoa(globalDb1ip) as db1, inet_ntoa(globalDb2ip) as db2, inet_ntoa(globalDb3ip) as db3 from setup";
		$stmt = $conn->prepare($szSQL);
		//$stmt->bind_param("s", $_GET["email"]);
		$stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result
		if ($result)
		{
			if($row = $result->fetch_assoc())
			{
				for ($n=1; $n < 4; $n++)
				{
					$szDbIp = isset($row["db".$n])?$row["db".$n]:0;

					if ($szDbIp !== 0)
					{
						$szUrl = str_replace("[URL_HERE]", $szDbIp, $urlTemplate);
						print "About to send: $szUrl<br>";
						$szReply = getUrl($szUrl);
						print "Reply from DB server: $szDbIp: $szReply<br>";
					}
				}	
			}
		}

		$szRouterIp = getPartnerRouterOf($szSenderIp);
		if (isset($szRouterIp) && strcmp($szRouterIp, "0"))
		{
			$szUrl = str_replace("[URL_HERE]", $szRouterIp, $urlTemplate);
			print "About to send: $szUrl<br>";
			$szReply = getUrl($szUrl);
			print "Reply from router: $szRouterIp: $szReply<br>";
		}
	}
	else
	{
		print "localhost user.. just logging locally<br>";
	}

	//Report it to our own hackReport table
	$szSQL = "insert into hackReport (ip, port, sentByIp, status) values (inet_aton(?), ?, inet_aton('127.0.0.1'), ?)";
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("sis", $szSenderIp, $nFromPort, $szmsg);
	$stmt->execute();
}


function checkIfTooManyLoginAttemptFromIp($szIp)
{
	if ($szIp === 0)
		$szSenderIp = getSenderIp();

	//Check how many reported from this IP
	$szSQL = "SELECT ".
			"SUM(theTime > NOW() - INTERVAL 1 MINUTE) AS last1Minute, ".
			"SUM(theTime > NOW() - INTERVAL 5 MINUTE) AS last5Minutes ".
			"FROM loginAttempt where ip = inet_aton(?)";

	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $szSenderIp);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
	{
		$rec = $result->fetch_assoc();
		if ($rec)
		{
			print "Lately from this IP: 1 min: ".$rec["last1Minute"].", 5min: ".$rec["last5Minutes"]."<br>";

			if ($rec["last1Minute"]+0 > 5 || $rec["last5Minutes"]+0 > 10)
			{
				reportHacking("This IP tried to log in ".$rec["last1Minute"]."/".$rec["last5Minutes"]." last 1/5 min");
			}
		}
	}
}

?>