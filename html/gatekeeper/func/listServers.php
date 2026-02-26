<?php


function listServers()
{
	$conn = getConnection();

	if ($_GET["f"] == "delserver")
	{
		$sql = "delete from internalServers where ip = ? and port = ?";
		//print "SQL: $sql<br>";
		//$result = $conn->query($sql);
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("ii", $szIp, $nPort);
		$szIp = $_GET['ip'];
		$nPort = $_GET['port'];
                $stmt->execute();
	}
	

	$sql = "SELECT inet_ntoa(ip) as ip, ip as ipn, port, publicPort, protection from internalServers order by ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered servers and required protection:</h2><table>
			<tr><td>IP</td><td>Port</td><td>Public port</td><td>Protection</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["ip"]. "</td><td>".$row["publicPort"]."</td><td>".$row["port"]."</td>";
	    		print '<td>'.$row["protection"]. "</td>";
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
	    		print '<td><a href="index.php?f=delserver&ip='.$row["ipn"].'&port='.$row["port"].'">[Delete]</a></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
		print "</table>";
	} 
	else 
	{
	  echo "No internal servers registered<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	print '<br><a href="index.php?f=addserver">Add server</a>';
}

?>
