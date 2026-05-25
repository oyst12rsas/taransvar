<?php

function meOrMine($nIp, $szIp)
{
	$dbh = getConnection();
	$stmt = $dbh->prepare("select adminIP, nettmask, inet_aton(?) as CheckIp from setup");
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
			return (($nIp & $nNettmask) == ($row["adminIP"] & $nNettmask));
		}
	}
	
	return 0;
}

function tagStatus()
{
	//CXmlCommand::setInnerHTML("tagStatus", "", "TESTING");//, $cMoreParamsArr = array())
	//return;


	$szSenderIp = getSenderIp();

	$tagStatus = "Unknown tag status<br>IP: ".$szSenderIp;

	if (meOrMine(0, $szSenderIp))
	{
		//One of my units... Read status from internalInfections table
		$szSQL = "select infectionId, lastSeen, infoSharePartners, severity from internalInfections where ip = inet_aton(?) order by infectionId desc limit 1";
		$conn = getConnection();
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("s", $szSenderIp); 
	    $stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result

		if ($result->num_rows > 0) 
		{
			if($row = $result->fetch_assoc()) 
			{
				if ((int)$row["severity"] > 0)
					$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity: '.$row["severity"];
				else
					$tagStatus = '<font color="green">You are clean<br>Last seen: '.$row["severity"].' id:'.$row["infectionId"].'</font>';
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

		if ($result->num_rows > 0) 
		{
			if($row = $result->fetch_assoc()) 
			{
				if ((int)$row["severity"] > 1)
					$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity: '.$row["severity"]."<br>Contact provider";
				else
					$tagStatus = '<font color="green">You are clean</font>';
				//$tagStatus = "Severity: ".$row["severity"]."<br>".$row["infoSharePartners"];
		  	}
		} 
	}
	//$conn->close();

	CXmlCommand::setInnerHTML("tagStatus", "", $tagStatus);//, $cMoreParamsArr = array())

	
}

?>