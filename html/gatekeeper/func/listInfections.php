<?php


function listInfections()
{
	$conn = getConnection();

	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description from internalInfections I left outer join unit u on u.unitId = I.unitId order by I.lastSeen desc";
	$result = $conn->query($sql);

        print "<h2>Registered infections in our net:</h2>";

	if ($result) 
	{
		// output data of each row  
		print "<table>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
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
			print '<tr><td>'.$row["lastSeen"].'<td>'.$szFont.$row["ip"].$szFontEnd.'</td><td>'.$szFont.$row["nettmask"].$szFontEnd.'</td><td>'.$szWho.'</td><td>'.$szFont.$row["status"].$szFontEnd.'</td><td><a href="index.php?f=delInfection&action='.$szAction.'&id='.$row["infectionId"].'">['.$szAction.']</a>'.$szExtraAction.'</td>';
			//print '<tr><td>'.$row["ip"].'</td><td>'.$row["nettmask"].'</td><td>'.$row["status"].'</td><td></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registered infections found!<br>";
	} 
	else 
	{
		print "Error fetching data!<br>";
	}
	print "</table>";


        print "<h2>Hacking attempts reported by partners and fans:</h2>";



	$sql = "SELECT inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, hostname, description from hackReport h left outer join unit u on u.unitId = h.unitId order by h.created desc";
	$result = $conn->query($sql);

	if ($result) 
	{
		// output data of each row  
		print "<h2><table>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{       
		        if ($nCount == 0)
		        {
		                print '<tr><td>Reported</td><td colspan="3">Attacker</td><td colspan="2">Reporter</td></tr>';
		        }
		        $szWhom =       ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
			print '<tr><td>'.$row["created"].'</td><td>'.$row["ip"].'</td><td>'.$row["port"].'</td><td>'.$szWhom.'</td><td>'.$row["partnerIp"].'</td><td>'.$row["partnerPort"].'</td><td>'.$row["status"].'</td>';
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No hacking attempts reported.<br>";
	} 
	else 
	{
			print "No hacking attempts reported.<br>";
	}
	print "</table>";

        print "<a href=\"index.php?f=reportHack\">Register hacking attempt</a>";


        
        $conn->close();

	print '<br><a href="index.php?f=addinfection">Add infection</a>';
}

?>

