<script>
	function editInfection(nId)
	{
		alert("....here now with "+nId);
	}
</script>
<?php

/*
function getActivateInfectionsLinks($row)
{
	switch ($row["active"])
	{
		case "1":
			$szAction = "deactivate";
			$szExtraAction = '';
			break;
		case "0":
			$szAction = "activate";
			$szExtraAction = '<a href="index.php?f=delInfection&action=delete&id='.$row["infectionId"].'">[delete]</a>';
			break;
        default:
            $szAction = $szExtraAction = "ERROR (unknown active)";
            break;
	}

	return '<a href="index.php?f=delInfection&action='.$szAction.'&id='.$row["infectionId"].'">['.$szAction.']</a>'.$szExtraAction.'</td>';
}*/

$szIncFile = "include_getActivateInfectionLinks.php";

if (file_exists($szIncFile))
    include $szIncFile;
else
    if (file_exists("func/".$szIncFile))
        include "func/".$szIncFile;


function listInfections()
{
?>

<script>
var szUpdateRoutine = "hackReport";	
</script>
<?php

	$conn = getConnection();

	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description from internalInfections I left outer join unit u on u.unitId = I.unitId order by I.lastSeen desc";
	$result = $conn->query($sql);

	print "<h2>Registered infections in our net:</h2>";
	$nFound = 0;

	if ($result) 
	{
		// output data of each row  
		print '<table id="infectionsTbl">';
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
			print '<tr id="inf'.$row["infectionId"].'"><td>'.$row["lastSeen"].'<td>'.$szFont.$row["ip"].$szFontEnd.'<br>'.$szFont.$row["nettmask"].$szFontEnd.'</td><td></td><td>'.$szWho.'</td><td>'.$szFont.$row["status"].$szFontEnd.'</td><td>';
			$szActivateLinks = getActivateInfectionLinks($row);
			print $szActivateLinks;
			print '</td>';
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

	$sql = "SELECT reportId, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, h.lastSeen, h.unitId, hostname, description from hackReport h left outer join unit u on u.unitId = h.unitId order by h.created desc limit 50";
	$result = $conn->query($sql);

	if ($result) 
	{
		// output data of each row  
		print '<h2><table id="hackReportTbl">';
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{       
			if (++$nCount == 1)
				print "<tr><th>Last seen</th><th>Attacker</th><th>Who</th><th>Status</th></tr>";

			if ($row["unitId"])
	        	$szWhom = ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
			else
				$szWhom = "ISP: ".$row["partnerIp"];

			print '<tr id="hr'.$row['reportId'].'"><td>'.$row["lastSeen"].'</td><td>'.$row["ip"].':'.$row["port"].'</td><td>'.$szWhom.'</td><td>'.$row["status"].'</td>';
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
    		print "</tr>";
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
	print '<br><a href="index.php?f=addinfection">Add infection</a>';
        
    $conn->close();
}

?>

