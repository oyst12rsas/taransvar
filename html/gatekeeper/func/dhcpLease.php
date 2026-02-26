<?php

function dhcpLease()
{
//asdfasdf
	$sql = "select dhcpDumpLogId, dhcpDumpFileId, logTime, unitId, macAddress, inet_ntoa(ipAddress) as ipAddress, dhcpClientId, mac, vci, hostname, comment from dhcpDumpLog order by dhcpDumpLogId desc limit 50";  
	
	$conn = getConnection();
	$result = $conn->query($sql);
	print "<table>";
	print "<tr><td>Time</td><td>UnitId</td><td>mac</td><td>IP</td><td></td><td></td><td>Vendor class id</td></tr>";
	while ($row = $result->fetch_assoc())
	{
		print "<tr><td>".$row["logTime"]."</td><td>".$row["unitId"]."</td><td>".$row["macAddress"]."</td><td>".$row["ipAddress"]."</td><td>".$row["dhcpClientId"]."</td><td>".$row["mac"]."</td><td>".$row["vci"]."</td><td>".$row["hostname"]."</td><td>".$row["comment"]."</td></tr>"; 
	}
	print "</table>";

}

?>
