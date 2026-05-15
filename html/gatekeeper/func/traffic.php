<script>
var szUpdateRoutine = "traffic";	
</script>
<?php

function traffic()
{
	$conn = getConnection();
	$sql = "SELECT trafficId, inet_ntoa(T.ipFrom) as ipFrom, inet_ntoa(T.ipTo) as ipTo, T.whoIsId, CAST(isLan AS UNSIGNED) as isLan, name, portFrom, portTo, created, lastSeen, count, tag from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId order by lastSeen desc limit 50";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print '<h2>Traffic:</h2><table id="trafficTbl">
			<tr><th colspan="2">From</td><td>Last seen</td><td>Count</td><td>Tag</td></tr>';
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			$szName = ($row["isLan"] ? '<font color="gray">LAN traffic</font>' : $row["name"]);
	    		print '<tr id="tr'.$row["trafficId"].'"><td>'.$row["ipFrom"].":".$row["portFrom"]."</td><td>".$szName."</td><td>".$row["lastSeen"]."</td><td>".$row["count"]."</td><td>".$row["tag"]."</td>";
	    		//print '<td><a href="index.php?f=delpartner&ip='.$row["partnerId"].'">[Delete]</a></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "<tr><td colspan=\"2\">No registrations found!<br></td></tr>";
		print "</table>";
	} 
	else 
	{
	  echo "No traffic registered. Make sure absecurity and abmonitor are both running<br>";
	}
	$conn->close();
}

?>
