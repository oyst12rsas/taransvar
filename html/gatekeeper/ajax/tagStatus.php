<?php

function meOrMine($nIp, $szIp)
{
	if (!strcmp($szIp, '127.0.0.1') || !strcmp($szIp, '::1'))
		return 1;

	$dbh = getConnection();
	$stmt = $dbh->prepare("select adminIP, internalIP, nettmask, inet_aton(?) as CheckIp from setup");
	$stmt->bind_param("s", $szIp); 
    $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		$nCount=0;
		if($row = $result->fetch_assoc()) 
		{
			$nNettmask = (int)$row["nettmask"];
			if ($nIp == 0)
				$nIp = (int) $row["CheckIp"];
			return (($nIp & $nNettmask) == ($row["internalIP"] & $nNettmask));
		}
	}
	
	return 0;
}

function tagStatus()
{
	//CXmlCommand::setInnerHTML("tagStatus", "", "TESTING");//, $cMoreParamsArr = array())
	//return;

	$szSenderIp = getSenderIp();	//If $szSenderIp is "::1" or "127.0.0.1", then should use setup->adminIP instead for the search (but this won't happen in real life..). Can test by putting those IPs in internalInfections....

	$tagStatus = "Unknown tag status<br>IP: ".$szSenderIp;

	if (meOrMine(0, $szSenderIp))
	{
		//One of my units... Read status from internalInfections table
		$szSQL = "select infectionId, lastSeen, infoSharePartners, severity, CAST(active AS UNSIGNED) as active from internalInfections where ip = inet_aton(?) order by infectionId desc limit 1";
		$conn = getConnection();
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("s", $szSenderIp); 
	    $stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result

		if ($result->num_rows > 0) 
		{
			if($row = $result->fetch_assoc()) 
			{
				if (((int)$row["active"] == 1) && ((int)$row["severity"] > 0))
					$tagStatus = '<br><font color="red">YOU ARE LISTED AS INFECTED</font></b><br>Severity: '.$row["severity"];
				else
					$tagStatus = '<font color="green">You are clean<br>'.(((int)$row["active"] == 1)? 'Severity: '.$row["severity"]:'(infection disabled)').'</font>';	//.' id:'.$row["infectionId"]
			}
		}
		else
		{
			$tagStatus = '<font color="green">You are clean<br>(not listed)</font>';

		}

	}
	else
	{
		$conn = getConnection();
		$szSQL = "select severity, infoSharePartners, why, inet_ntoa(partnerIp) as reportedByIp from hackReport where ip = inet_aton(?) order by lastSeen desc limit 1";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("s", $szSenderIp); 
	    $stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result
		$nTagStatusBasedOnHackReport = 0;
		$nSeverity = 0;

		if ($result->num_rows > 0) 
		{
			if($row = $result->fetch_assoc()) 
			{
				$nSeverity = (int)$row["severity"];
				if ($nSeverity > 1)
				{
					$nTagStatusBasedOnHackReport = 1;
					$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity: '.$nSeverity."<br>Contact provider";
				}
				else
					$tagStatus = '<font color="green">You are clean</font>';
				//$tagStatus = "Severity: ".$row["severity"]."<br>".$row["infoSharePartners"];
		  	}
		} 
		//********************* traffic ********************** 

		$nTagStatusBasedOnTraffic = 0;
		$nTrafficSecondsSince = -1;

		//$sql = "SELECT trafficId, created, lastSeen, count, tag, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from traffic T where T.ipFrom = inet_aton(?) order by trafficId desc limit 1";
		$sql = "SELECT trafficId, created, lastSeen, count, tag, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from traffic T order by trafficId desc limit 1";
		$stmt = $conn->prepare($sql);
		//$stmt->bind_param("s", $szSenderIp); 
		$stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result

		if ($result) 
		{
			if ($row = $result->fetch_assoc()) 
			{
				$nTrafficSecondsSince = $row["seconds_since"];
				if ((int)$row["tag"] > 1)	//Note! tag contains more than just severity... but omit that for now...
					$nTagStatusBasedOnTraffic = 1;
		  	}
			$result->close();
		} 

		if ($nTagStatusBasedOnTraffic != $nTagStatusBasedOnHackReport)
		{
			if ($nTrafficSecondsSince < 30)
				//$tagStatus .= "<br>Traffic data are recent. So most likely ".($nTagStatusBasedOnTraffic?'<font color="red">TAGGED</font>':'<font color="green">CLEAN</font>');
				$tagStatus = '<font color="green">You are clean</font><br>(Some contradiction, though. Tap to see.)';
				//Traffic data are recent. So most likely ".($nTagStatusBasedOnTraffic?'<font color="red">TAGGED</font>':'<font color="green">CLEAN</font>');
				//$tagStatus = '<font color="yellow">Traffic log contradicts hack reports!</font>';
			else
				$tagStatus = "Contradicting info. Traffic data is not updated. Please try another server. (Tap for more info)";
		}
		else
		{
			if ($nTagStatusBasedOnTraffic)
				$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity: '.$nSeverity."<br>Contact provider";
			else
				$tagStatus = '<font color="green">You are clean</font>';

			if ($nTrafficSecondsSince >= 0)
				$tagStatus .= "<br>Traffic $nTrafficSecondsSince sec ago.";
			else
				$tagStatus .= "<br>No traffic registered.";
		}
	}

	CXmlCommand::setInnerHTML("tagStatus", "", $tagStatus);//, $cMoreParamsArr = array())
}

?>