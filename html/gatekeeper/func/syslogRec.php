<?php


function syslogRec()
{
	$nId = $_GET["id"];

	$szSQL = "select s.syslogId, syslogThreatId, inet_ntoa(s.senderIp) as senderIp, s.senderPort, s.created, s.pri, s.facility, s.severity, s.hostname, s.tag, s.message, s.rawmessage, isSyslog, s.lastSeen, s.count from syslog s left outer join syslogThreat t on t.syslogId = s.syslogId where s.syslogId = ?";
	print "<h2>Last 10 syslog entries</h2>";

	//printSql($szSQL);

    $conn = getConnection();

	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("i", $nId);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if (!$result)
	{
		print "ERROR GETTING RESULT";
		return;
	}

	$row = $result->fetch_assoc();

	if (!$row)
	{
		print "ERROR FETCHING";
		return;
	}

	print "<table>";

	print "<tr><td>syslogId</td><td>".$row["syslogId"]."</td></tr>";
	print '<tr><td>ThreatId</td><td><a href="index.php?f=syslogThreatRec&id='.$row["syslogThreatId"].'">'.$row["syslogThreatId"].'</td></tr>';
	print "<tr><td>senderIp</td><td>".$row["senderIp"]."</td></tr>";
	print "<tr><td>senderPort</td><td>".$row["senderPort"]."</td></tr>";
	print "<tr><td>created</td><td>".$row["created"]."</td></tr>";
	if ($row["pri"])
		print "<tr><td>pri</td><td>".$row["pri"]."</td></tr>";

	if ($row["facility"])
		print "<tr><td>facility</td><td>".$row["facility"]."</td></tr>";

	print "<tr><td>severity</td><td>".$row["severity"]."</td></tr>";
	print "<tr><td>hostname</td><td>".$row["hostname"]."</td></tr>";
	print "<tr><td>tag</td><td>".$row["tag"]."</td></tr>";
	print "<tr><td>message</td><td>".$row["message"]."</td></tr>";
	print "<tr><td>rawmessage</td><td>".$row["rawmessage"]."</td></tr>";
	print "<tr><td>isSyslog</td><td>".$row["isSyslog"]."</td></tr>";
	print "<tr><td>lastSeen</td><td>".$row["lastSeen"]."</td></tr>";
	print "<tr><td>count</td><td>".$row["count"]."</td></tr>";

	print "<table>";


    $result->free();
    $conn->close();

}

?>
