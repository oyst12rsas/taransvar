<?php
function workshops()
{
//asdfasdf
	$sql = "select workshopId, inet_ntoa(ip) as ip, role, inet_ntoa(publicIp) as publicIp, created, lastseen from workshop order by created desc limit 50";  
	$conn = getConnection();
	$result = $conn->query($sql);
	print "<table>";
	//print "<tr><td>Time</td><td>UnitId</td><td>mac</td><td>IP</td><td></td><td></td><td>Vendor class id</td></tr>";
	$nFound = 0;
	while ($row = $result->fetch_assoc())
	{
		if (!$nFound)
			print "<tr><td>Id</td><td>IP</td><td>Role</td><td>Public IP</td><td>Created</td><td>Seen</td></tr>";
			
		print "<tr><td>".$row["workshopId"]."</td><td>".$row["ip"]."</td><td>".$row["role"]."</td><td>".$row["publicIp"]."</td><td>".$row["created"]."</td></tr>";
		$nFound++;
	}
	print "</table>";
	if (!$nFound)
		print "No workshop records found.";
}

?>
