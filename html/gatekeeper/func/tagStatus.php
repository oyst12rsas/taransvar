<?php

function getPrintableSecondsSince($nSecondsSince)
{
	if ($nSecondsSince < 120)
		return "$nSecondsSince sec";
	else
		if ($nSecondsSince < 60 * 120)
		{
			$nMinutsSince = round($nSecondsSince/60);
			return "$nMinutsSince min";
		}

	$nHoursSince = round($nSecondsSince/(60*60));
	return "$nHoursSince hour";
}

function tagStatus()
{
	if (isAdmin())
	{
		?>
		<form>
		<?php
	}


	$szIP = getSenderIp();
	print "Your IP: ".$szIP."<br>";

	$conn = getConnection();
	$nInfectionsCount = 0;

	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description from internalInfections I left outer join unit u on u.unitId = I.unitId where ip = inet_aton(?) order by I.lastSeen desc";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("s", $szIP);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
	{
		while ($row = $result->fetch_assoc())
		{
			if (!$nInfectionsCount)
				print "<h2>Registered infections on [name]:</h2><table>";

			switch ($row["active"])
			{
				case "1":
					$szAction = "deactivate";
					$szExtraAction = '';
					$szFont = $szFontEnd = "";
					break;
				case "0":
					$szAction = "activate";
					$szExtraAction = '<a href="index.php?f=delInfection&action=delete&id='.$row["infectionId"].'">[delete]</a>';
					$szFont = '<font color="red">';
					$szFontEnd = "</font>";
					break;
			}
			$szWho = $row["hostname"].$row["description"];
			print '<tr id="inf'.$row["infectionId"].'"><td>'.$row["lastSeen"].'<td>'.$szFont.$row["ip"].$szFontEnd.'<br>'.$szFont.$row["nettmask"].$szFontEnd.'</td><td></td><td>'.$szWho.'</td><td>'.$szFont.$row["status"].$szFontEnd.'</td>';
			//print '<tr><td>'.$row["ip"].'</td><td>'.$row["nettmask"].'</td><td>'.$row["status"].'</td><td></td>';
	    		print "</tr>";
			$nInfectionsCount++;
	  	}
		if ($nInfectionsCount)
			print "</table>";
		//else
		//	print "No registered infection found!<br>";

		$result->close();
	} 
	else 
	{
		print "Error fetching infections data!<br>";
	}


	if (!$nInfectionsCount)
		print "No infections found.. Which may be good..<br>";


	//********************* hackReports ********************** */

	$sql = "SELECT reportId, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, severity, partnerPort, status, h.created, h.lastSeen, h.unitId, hostname, description, why, TIMESTAMPDIFF(SECOND, coalesce(h.lastSeen, h.created), NOW()) AS seconds_since from hackReport h left outer join unit u on u.unitId = h.unitId where ip = inet_aton(?) order by h.created desc limit 5";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("s", $szIP);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	$nHackReportCount=0;

	if ($result) 
	{
		// output data of each row  
		while($row = $result->fetch_assoc()) 
		{       
			$szTimeSince = getPrintableSecondsSince((int)$row["seconds_since"]);

			if (++$nHackReportCount == 1)
				print "<h2>Hack reports</h2><table id=\"hackReportTbl\"><tr><th>Last seen</th><th>Attacker</th><th>Severity</th><th>Who</th><th>Status</th><th>Why</th></tr>";

			if ($row["unitId"])
	        	$szWhom = ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
			else
				$szWhom = "ISP: ".$row["partnerIp"];

			print '<tr id="hr'.$row['reportId'].'"><td>'.$szTimeSince.'</td><td>'.$row["ip"].':'.$row["port"].'</td><td>'.$row["severity"].'</td><td>'.$szWhom.'</td><td>'.$row["status"].'</td><td>'.$row["why"].'</td>';
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
    		print "</tr>";
	  	}
		if (!$nHackReportCount)
			print "No hacking attempts recorded. To get tagged, \"fail\" to login to gatekeeper. Then try again.<br>";
		else
			print "</table>";

		$result->close();
	} 
	else 
	{
		print "Error trying to read hack reports!"; //No hacking attempts reported.<br>";
	}

	//********************* traffic ********************** */

	$sql = "SELECT trafficId, inet_ntoa(T.ipFrom) as ipFrom, inet_ntoa(T.ipTo) as ipTo, portFrom, portTo, coalesce(lastSeen, created) as lastSeen, count, tag, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) AS seconds_since from traffic T where T.ipFrom = inet_aton(?) order by trafficId desc limit 5";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("s", $szIP);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	$nTrafficCount=0;

	if ($result) 
	{
		while($row = $result->fetch_assoc()) 
		{
			if ($nTrafficCount == 0)
				print '<h2>Traffic</h2><table id="trafficTbl"><tr><th>From</td><td>Last seen</td><td>Count</td><td>Tag</td></tr>';

			$szTimeSince = getPrintableSecondsSince((int)$row["seconds_since"]);
    		print '<tr id="tr'.$row["trafficId"].'"><td>'.$row["ipFrom"].":".$row["portFrom"]."</td><td>".$szTimeSince."</td><td>".$row["count"]."</td><td>".$row["tag"]."</td>";
    		//print '<td><a href="index.php?f=delpartner&ip='.$row["partnerId"].'">[Delete]</a></td>';
    		print "</tr>";
			$nTrafficCount++;
	  	}
		if ($nTrafficCount)
			print "</table>";
		else
			print "No registered traffic found!<br></td></tr>";
		$result->close();
	} 
	else 
	{
		echo "Failed to list reported traffic."; //No traffic registered. Make sure absecurity and abmonitor are both running<br>";
	}
	$conn->close();
}

?>
