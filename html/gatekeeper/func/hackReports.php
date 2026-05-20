<?php
//hackReports.php

function hackReports()
{

    if (!isAdmin())
        return;

    inspectionsMenu();

    $conn = getConnection();
	$sql = "select reportId, created, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, status, handledTime, lastSeen, sentGlobalDB, ipOwnerId, severity, botnetId, partnerId, infectionId, remoteUnitId from hackReport order by reportId desc limit 5";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>hackReports</h2><table>
			<tr><td>ID</td><td>Created</td><td>IP</td><td>Status</td><td>Last seen</td><td>Handled</td><td>Severity</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["reportId"]. "</td><td>".$row["created"]. "</td><td>".$row["ip"].":".$row["port"]."</td><td>".$row["status"]."</td><td>".$row["lastSeen"]."</td><td>".$row["handledTime"]."</td><td>".$row["severity"]."</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No syslogThreats found!<br>";
	} 
	print "</table>";
	$conn->close();
}

?>