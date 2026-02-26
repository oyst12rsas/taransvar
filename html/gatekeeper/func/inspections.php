<?php


function inspections()
{
	$conn = getConnection();

	$sql = "SELECT hex(ip) as ip, inet_ntoa(ip) as aip, hex(nettmask) as nettmask, inet_ntoa(nettmask) as anett, handling, active from inspection order by handling, ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Special packet inspection handling:</h2><table>
			<tr><td colspan=\"2\">IP</td><td colspan=\"2\">Nettmask</td><td>Handling</td><td>Active</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["aip"]. "</td><td>".$row["ip"]. "</td><td>".$row["anett"]."</td><td>".$row["nettmask"]. "</td>";
	    		print "<td>".$row["handling"]. "</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
	} 
	else 
	{
	        print "<table>";
	        print "<tr><td>No inspections registered</td></tr>";
	}
	print "</table>";
	$conn->close();
	print '<br><a href="index.php?f=addInspection">Add packet inspection</a>';
}

?>
