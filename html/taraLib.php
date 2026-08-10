<?php 

if (file_exists("../script/getSenderIp.php"))
	$szScriptFolder = "../script/";
else
	$szScriptFolder = "script/";

	require_once($szScriptFolder."getSenderIp.php");
	require_once($szScriptFolder."reportHacking.php");


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

function getUrl($baseUrl, $params = [])
{
	//************* Better use getUrlArray() below */
    if (!empty($params)) {
        $baseUrl .= '?' . http_build_query($params);
    }

    $ch = curl_init($baseUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $output = curl_exec($ch);

    if ($output === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return "CURL ERROR: $err";
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return "HTTP $httpCode\n$output";
}

function getUrlArray($baseUrl, $params = [])
{
	/* USAGE:

	$result = getUrlArray($url,$params);

	if ($result['success']) {
    	echo $result['output'];
	} else {
    	echo $result['error'];
	}
	
	*/


    if (!empty($params)) {
        $baseUrl .= '?' . http_build_query($params);
    }

    $ch = curl_init($baseUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $output = curl_exec($ch);

    if ($output === false) {
        $err = curl_error($ch);
        curl_close($ch);

        return [
            'success' => false,
            'error'   => $err,
            'http'    => null,
            'output'  => null
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'success' => true,
        'http'    => $httpCode,
        'output'  => $output
    ];
}

function logMsg($szMsg)
{
	$conn = getConnection();
	$stmt = $conn->prepare("insert into systemMessage (message) values (?)");
	$stmt->bind_param("s", $szMsg);
	$stmt->execute();
}

function reportHacking($szCode, $szMsg)
{
	require_once "../script/getUrl.php";
	require_once "../script/getSenderIp.php";
	$szSenderIp = getSenderIp();
	$nFromPort = $_SERVER['REMOTE_PORT'];

	$cParamArray = 	[
					    "f"    => "report",
    					"ip"   => $szSenderIp,
    					"port" => $nFromPort,
						"code" => $szCode,
    					"wt"   => $szMsg,
					];

	$conn = getConnection();

	if (strcmp($szSenderIp, "127.0.0.1"))
	{
		print "<br>**** WARNING *** Trying to log in too many times may cause problems logging in elsewhere as well!";
		//$urlTemplate = "http://[URL_HERE]/script/config_update.php?f=report&ip=".$szSenderIp."&port=".$nFromPort."&wt=".$szMsg;

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
						$url = "http:$szDbIp/script/config_update.php";
						//print "About to send: $url<br>";

						$szReply = getUrl($url, $cParamArray);

						//$szReply = getUrl($szUrl);
						//print "Reply from DB server: $szDbIp: $szReply<br>";
					}
				}	
			}
		}

		$szRouterIp = getPartnerRouterOf($szSenderIp);
		if (isset($szRouterIp) && strcmp($szRouterIp, "0"))
		{
			//$szUrl = str_replace("[URL_HERE]", $szRouterIp, $urlTemplate);
			$url = "http://$szRouterIp/script/config_update.php";
			//print "About to send: $url<br>";
			$szReply = getUrl($url, $cParamArray);
			//print "Reply from router: $szRouterIp: $szReply<br>";
		}
	}
	else
	{
		print "localhost user.. just logging locally<br>";
	}

	//Report it to our own hackReport table (if already registered, upgrade count field)

	$szSQL = "insert into hackReport (ip, port, sentByIp, status, why) 
		values (inet_aton(?), ?, inet_aton('127.0.0.1'), 'www', ?)";
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("sis", $szSenderIp, $nFromPort, $szMsg);
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
			//print "Lately from this IP: 1 min: ".$rec["last1Minute"].", 5min: ".$rec["last5Minutes"]."<br>";

			if ($rec["last1Minute"]+0 > 5 || $rec["last5Minutes"]+0 > 10)
			{
				$szMsg = "This IP tried to log in ".$rec["last1Minute"]."/".$rec["last5Minutes"]." last 1/5 min";
				reportHacking($szMsg);
			}
		}
	}
}

function getTagData()
{
	//READ THIS FROM TAGDATA INSTEAD:
	$szSenderIp = getSenderIp();
	$clientPort = $_SERVER['REMOTE_PORT']+0;

	$szSQL = "select infectionId, lastSeen, infoSharePartners, severity, CAST(active AS UNSIGNED) as active from internalInfections where ip = inet_aton(?) order by infectionId desc limit 1";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $szSenderIp); 
    $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	$retval = [];

	//Default values
	$nInfectionSeverity = -1;	//Not found
	$nInfectionDisabled = 0;

	if (($result->num_rows > 0) && ($row = $result->fetch_assoc())) 
	{
		if (((int)$row["active"] == 0))
		{
			$nInfectionSeverity = 1;
			$nInfectionDisabled = 1;
		}
		else
			$nInfectionSeverity = $row["severity"];
	}

	$result->close();
	$stmt->close();

	$retval["infectionSeverity"] = $nInfectionSeverity;
	$retval["infectionDisabled"] = $nInfectionDisabled;

	//READ THIS FROM TAGDATA INSTEAD: (hackReport)
//	$conn = getConnection();

	$szSQL = "select reportId, severity, infoSharePartners, why, inet_ntoa(partnerIp) as reportedByIp, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from hackReport where ip = inet_aton(?) and (port = 0 || port = ?) order by lastSeen desc limit 1";
	$stmt = $conn->prepare($szSQL);

	$stmt->bind_param("si", $szSenderIp, $clientPort); 

	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	$nTagStatusBasedOnHackReport = 0;
	$nHackReportSecondsSince = -1;
	$nHackSeverity = 0;

	if ($result->num_rows > 0) 
	{
		if($row = $result->fetch_assoc()) 
		{
			$nHackSeverity = (int)$row["severity"];
			$nHackReportSecondsSince = $row["seconds_since"];

			if ($nHackSeverity > 1)
				$nTagStatusBasedOnHackReport = 1;
	  	}
	} 

	$result->close();
	$stmt->close();

	$retval["hackReportSeverity"] = $nHackSeverity;
	$retval["hackReportSecondsSince"] = $nHackReportSecondsSince;

	//READ THIS FROM TAGDATA INSTEAD: (traffic)

	$nTagStatusBasedOnTraffic = 0;
	$nTrafficSecondsSince = -1;

	$sql = "SELECT trafficId, created, lastSeen, count, tag,
               TIMESTAMPDIFF(SECOND, COALESCE(lastSeen, created), NOW()) AS seconds_since
        FROM traffic T
        WHERE ipFrom = INET_ATON(?) AND portFrom = ?
        ORDER BY trafficId DESC
        LIMIT 1";

	$stmt = $conn->prepare($sql);
	if (!$stmt) {
	    throw new Exception("Prepare failed: " . $conn->error);
	}

	if (!$stmt->bind_param("si", $szSenderIp, $clientPort)) {
    	throw new Exception("Bind failed: " . $stmt->error);
	}

	$retval["senderIp"] = $szSenderIp;
	$retval["senderPort"] = $clientPort;

	if (!$stmt->execute()) {
    	throw new Exception("Execute failed: " . $stmt->error);
	}

	$result = $stmt->get_result();
	if (!$result) {
    	throw new Exception("get_result failed: " . $stmt->error);
	}

	$nTrafficSeverity = 0;

	if ($row = $result->fetch_assoc()) {
    	$nTrafficSecondsSince = (int)$row["seconds_since"];
    	$tag = (int)$row["tag"];

    	if ($tag > 1) {
	        $nTagStatusBasedOnTraffic = 1;
    	}

    	$version_no        =  $tag        & 0x3;
    	$presumed_infected = ($tag >> 2)  & 0xF;
    	$owners_id         = ($tag >> 6)  & 0x3FF;

	    $nTrafficSeverity = $presumed_infected;
	}

	$result->close();
	$stmt->close();

	$retval["trafficSeverity"] = $nTagStatusBasedOnTraffic;
	$retval["trafficSecondsSince"] = $nTrafficSecondsSince;

	//Sum it all up.
	$nSeverity = $nInfectionSeverity;

	//If recent infected traffic discovered, then that's more important.

	if ($nTrafficSecondsSince >= 0 && $nTrafficSecondsSince < 45)
	{
		if ($nTagStatusBasedOnTraffic > $nSeverity)
			$nSeverity = $nTagStatusBasedOnTraffic;
	}
	else
		if ($nHackSeverity > $nSeverity)
			$nSeverity = $nHackSeverity;

	$retval["severity"] = $nSeverity;

	//Usage
	//$data = getTagData();
	//$json = json_encode($data);
	//return $json;				
	return $retval;
}



?>