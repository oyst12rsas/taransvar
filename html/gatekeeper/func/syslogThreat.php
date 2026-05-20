<?php
//syslogThreat.php

function syslogThreat()
{
    if (!isAdmin())
        return;

    inspectionsMenu();

    $conn = getConnection();
	$sql = "select syslogThreatId, created, inet_ntoa(src_ip) as src_ip, inet_ntoa(dst_ip) as dst_ip, service, description, CAST(coalesce(handled, b'0') AS UNSIGNED) as handled from syslogThreat order by syslogThreatId desc limit 10";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>SystlogThreats:</h2><table>
			<tr><td>ID</td><td>Created</td><td>From</td><td>To</td><td>Description</td><td>&nbsp;</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["syslogThreatId"]. "</td><td>".$row["created"]. "</td><td>".$row["src_ip"]."</td><td>".$row["dst_ip"]."</td><td>".$row["description"]."</td>";
                print "<td>".($row["handled"]+0?"Handled":"Unhandled")."</td>";
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