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
			if (!$row["internalIP"] || !$row["internalIP"])
				return 0;

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

	//****************** NOTE ************************
	// READ THIS FROM TAGDATA INSTEAD:  function getTagData() defubed ub html_root/taraLib.php


	//CXmlCommand::setInnerHTML("tagStatus", "", "TESTING");//, $cMoreParamsArr = array())
	//return;

	$szSenderIp = getSenderIp();	//If $szSenderIp is "::1" or "127.0.0.1", then should use setup->adminIP instead for the search (but this won't happen in real life..). Can test by putting those IPs in internalInfections....

	$tagStatus = "Unknown tag status<br>IP: ".$szSenderIp;

	$bMeOrMine = meOrMine(0, $szSenderIp);

//	$tagStatus = "MeOrmine: $bMeOrMine. IP: ".$szSenderIp;
//	CXmlCommand::setInnerHTML("tagStatus", "", $tagStatus);//, $cMoreParamsArr = array())
//	return;

	if ($bMeOrMine)
	{
		//One of my units... Read status from internalInfections table
		//READ THIS FROM TAGDATA INSTEAD:
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


		//READ THIS FROM TAGDATA INSTEAD:

		$conn = getConnection();

		/*NOTE! This is wrong.. If partner is doing NAT, then search only on the same port, otherwise return only if portnumber == 0 (applies to all on same IP)
		for now, not setting port = 0 if not NAT, but that's probably ok for this function - treat as if no NAT */
		//$szSQL = "select severity, infoSharePartners, why, inet_ntoa(partnerIp) as reportedByIp from hackReport where ip = inet_aton(?) order by lastSeen desc limit 1";
		//$stmt = $conn->prepare($szSQL);
		//$stmt->bind_param("s", $szSenderIp); 

		$szSQL = "select reportId, severity, infoSharePartners, why, inet_ntoa(partnerIp) as reportedByIp from hackReport where ip = inet_aton(?) and (port = 0 || port = ?) order by lastSeen desc limit 1";
		$stmt = $conn->prepare($szSQL);
		$clientPort = $_SERVER['REMOTE_PORT']+0;

		$stmt->bind_param("si", $szSenderIp, $clientPort); 

		$stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result
		$nTagStatusBasedOnHackReport = 0;
		$nHackSeverity = 0;
		$nSeverity = 0;

		if ($result->num_rows > 0) 
		{
			if($row = $result->fetch_assoc()) 
			{
				$nHackSeverity = (int)$row["severity"];
				if ($nHackSeverity > 1)
				{
					$nTagStatusBasedOnHackReport = 1;
					$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity (hackReport): '.$nHackSeverity."<br>Contact provider";
				}
				else
					$tagStatus = '<font color="green">You are clean</font>';
				//$tagStatus = "Severity: ".$row["severity"]."<br>".$row["infoSharePartners"];
		  	}
		} 
		//********************* traffic ********************** 

	//READ THIS FROM TAGDATA INSTEAD: (traffic)


		$nTagStatusBasedOnTraffic = 0;
		$nTrafficSecondsSince = -1;

		//$sql = "SELECT trafficId, created, lastSeen, count, tag, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from traffic T where T.ipFrom = inet_aton(?) order by trafficId desc limit 1";
		$sql = "SELECT trafficId, created, lastSeen, count, tag, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from traffic T where ipFrom = inet_aton(?) and portFrom = ? order by trafficId desc limit 1";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("si", $szSenderIp, $clientPort); 
		$stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result
		$nTrafficSeverity = 0;

		if ($result) 
		{
			if ($row = $result->fetch_assoc()) 
			{
				$nTrafficSecondsSince = $row["seconds_since"];
				$tag = $row["tag"]+0;

				if ($tag > 1)	//Note! tag contains more than just severity... but omit that for now...
					$nTagStatusBasedOnTraffic = 1;

				//**** WARNING **** Check struct _Tag definition in tarakernel/module_globals.h */
				$version_no        =  $tag        & 0x3;       // 2 bits
				$presumed_infected = ($tag >> 2)  & 0xF;       // 4 bits
				$owners_id         = ($tag >> 6)  & 0x3FF;     // 10 bits

				$nTrafficSeverity = $presumed_infected;
		  	}
			$result->close();
		} 

		//if ($nTagStatusBasedOnTraffic != $nTagStatusBasedOnHackReport)
		if ($nTrafficSeverity != $nHackSeverity)
		{
			$tagStatus = "Hack: $nHackSeverity<br>Traffic: $nTrafficSeverity<br>";
			
			if ($nTrafficSecondsSince < 30 && $nTrafficSeverity <= 1)
				//$tagStatus .= "<br>Traffic data are recent. So most likely ".($nTagStatusBasedOnTraffic?'<font color="red">TAGGED</font>':'<font color="green">CLEAN</font>');
				$tagStatus = '<font color="green">You are clean</font><br>(Though: '.$tagStatus.')';
				//Traffic data are recent. So most likely ".($nTagStatusBasedOnTraffic?'<font color="red">TAGGED</font>':'<font color="green">CLEAN</font>');
				//$tagStatus = '<font color="yellow">Traffic log contradicts hack reports!</font>';
			else
			{
				$tagStatus = "Contradicting info!<br>".($nTrafficSecondsSince >= 30?"<b>Traffic data is not updated. <br>Please try another server.</b>":"(Tap for more info)").$tagStatus;
			}
		}
		else
		{
			//if ($nTagStatusBasedOnTraffic)
			if ($nTrafficSeverity > 1)
				$tagStatus = '<br><font color="red">YOU ARE TAGGED</font></b><br>Severity: '.$nTrafficSeverity."<br>Contact provider";
			else
				if ($nTrafficSeverity == 1)
					$tagStatus = '<font color="green">You are clean</font><br>(though tagged - you can\'t ssh)';
				else
					$tagStatus = '<font color="green">You are clean</font><br>(no history)';

			if ($nTrafficSecondsSince >= 0)
				$tagStatus .= "<br>Traffic $nTrafficSecondsSince sec ago.";
			else
				$tagStatus .= "<br>No traffic registered.";
		}
	}

	CXmlCommand::setInnerHTML("tagStatus", "", $tagStatus);//, $cMoreParamsArr = array())
}

?>