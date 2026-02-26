<?php



function listDomains()
{
	$conn = getConnection();

	$sql = "SELECT domainId, domainName, color, if(active,'Active','Inactive') as active from domain order by domainName";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered domains and white/blacklisting:</h2><table>
			<tr><td>DomainName</td><td>Color</td><td>Active</td><td>&nbsp;</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print '<tr><td><a href="index.php?f=domainInfo&id='.$row["domainId"].'">'.$row["domainName"]. "</a></td><td>".$row["color"]."</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print '<td><a href="index.php?f=domainDel&id='.$row["domainId"].'">[Delete]</td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
        	print "</table>";
	} 
	else 
	{
	  echo "No domain found<br>";
	}
	$conn->close();
	print '<br><a href="index.php?f=adddomain">Add domain</a>';
}

?>
