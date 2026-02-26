<?php

function colorListings()
{
	$conn = getConnection();

	$sql = "SELECT ip, color, active from colorListings order by ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered IP white/blacklisting:</h2><table>
			<tr><td>IP</td><td>Color</td><td>Active</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["ip"]. "</td><td>".$row["color"]."</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
		print "</table>";
	} 
	else 
	{
	  echo "0 results";
	}
	$conn->close();
	print '<br><a href="index.php?f=addColorListing">Add IP white/blacklist</a>';
}

?>
