<script>
	function editInfection(nId)
	{
		alert("....here now with "+nId);
	}
</script>
<?php

$szIncFile = "include_getActivateInfectionLinks.php";

if (file_exists($szIncFile))
    include $szIncFile;
else
    if (file_exists("func/".$szIncFile))
        include "func/".$szIncFile;

function listInfections()
{
?>
<script>
var szUpdateRoutine = "hackReport";
</script>
<?php
	$conn = getConnection();
	print "<h2>Registered infections in our net:</h2>";
	print '<p><a href="index.php?f=liveAudit">Open Live TaraSec audit</a></p>';
	print '<table id="infectionsTbl">';
	print '<tr><th>When</th><th>Who</th><th>Nettmask</th><th>&nbsp;</th><th>Severity</th><th>Botnet</th><th>Info priv</th><th>Info pub</th></tr>';
	print "</table>";

	$sql = "SELECT reportId, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, h.lastSeen, h.unitId, hostname, description, why, severity, infectionId from hackReport h left outer join unit u on u.unitId = h.unitId order by h.created desc limit 25";
	$result = $conn->query($sql);

	print "<h2>Hacking attempts reported by partners and fans:</h2><table id=\"hackReportTbl\"><tr><th>Last seen</th><th>Attacker</th><th>Who</th><th>Severity</th><th>Status</th><th>Why</th><th>Audit</th></tr>";

	if ($result)
	{
		$nCount=0;
		while($row = $result->fetch_assoc())
		{
			++$nCount;
			if ($row["unitId"])
	        	$szWhom = ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
			else
				$szWhom = "ISP: ".$row["partnerIp"];

            $szAudit = 'index.php?f=liveAudit&reportId='.intval($row['reportId']);
            if (!empty($row['infectionId']))
                $szAudit .= '&infectionId='.intval($row['infectionId']);

			print '<tr id="hr'.$row['reportId'].'"><td>'.$row["lastSeen"].'</td><td>'.$row["ip"].':'.$row["port"].'</td><td>'.$szWhom.'</td><td>'.$row["severity"].'</td><td>'.$row["status"].'</td><td>'.$row["why"].'</td><td><a href="'.$szAudit.'">[watch live]</a></td>';
    		print "</tr>";
	  	}
		if (!$nCount)
			print "No hacking attempts reported.<br>";
	}
	else
	{
		print "No hacking attempts reported.<br>";
	}

	print "</table>";
	print "<a href=\"index.php?f=reportHack\">Register hacking attempt</a>";
	print '<br><a href="index.php?f=addinfection">Add infection</a>';

    $conn->close();
}

?>
