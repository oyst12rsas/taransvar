<?php
//hackReports.php

function hackReports()
{

    if (!isAdmin())
        return;

    inspectionsMenu();

    $conn = getConnection();
	$sql = "select reportId, created, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, status, handledTime, lastSeen, sentGlobalDB, ipOwnerId, severity, botnetId, partnerId, infectionId, remoteUnitId from hackReport order by reportId desc limit 25";
	$result = $conn->query($sql);

	print '<h2>hackReports</h2>';
	print '<p><a href="index.php?f=liveAudit">Open Live TaraSec audit</a></p>';

	if ($result->num_rows > 0)
	{
		print "<table>
			<tr><td>ID</td><td>Created</td><td>IP</td><td>Status</td><td>Last seen</td><td>Handled</td><td>Severity</td><td>Audit</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc())
		{
            $szAudit = 'index.php?f=liveAudit&reportId='.intval($row['reportId']);
            if (!empty($row['infectionId']))
                $szAudit .= '&infectionId='.intval($row['infectionId']);

	    		print "<tr><td>".$row["reportId"]. "</td><td>".$row["created"]. "</td><td>".$row["ip"].":".$row["port"]."</td><td>".$row["status"]."</td><td>".$row["lastSeen"]."</td><td>".$row["handledTime"]."</td><td>".$row["severity"]."</td><td><a href=\"".$szAudit."\">[watch live]</a></td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No hackReports found!<br>";
	}
	print "</table>";
	$conn->close();
}

?>