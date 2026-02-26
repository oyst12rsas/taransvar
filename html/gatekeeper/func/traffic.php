<?php

function traffic()
{
	$conn = getConnection();
	$sql = "SELECT inet_ntoa(T.ipFrom) as ipFrom, inet_ntoa(T.ipTo) as ipTo, T.whoIsId, CAST(isLan AS UNSIGNED) as isLan, name, portFrom, portTo, created, count from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId order by trafficId desc limit 50";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Traffic:</h2><table>
			<tr><th colspan=\"2\">From</td><th colspan=\"2\">Port from/to</td><td>Time</td><td>Count</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			$szName = ($row["isLan"] ? '<font color="gray">LAN traffic</font>' : $row["name"]);
	    		print "<tr><td>".$row["ipFrom"]. "</td><td>".$szName."</td><td>".$row["portFrom"]."</td><td>".$row["portTo"]."</td><td>".$row["created"]."</td><td>".$row["count"]."</td>";
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
