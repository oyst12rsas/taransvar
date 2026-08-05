<?php


function syslogThreatRec()
{
	$nId = $_GET["id"];

	$szSQL = "select t.syslogId, syslogThreatId, rawmessage, owner_id, unit_id, confirmed_unit_id, is_attack, action, inet_ntoa(src_ip) as src_ip, " .
				"src_port, inet_ntoa(dst_ip) as dst_ip, dst_port, protocol, device, botnetId, t.severity, handled, service, description, t.count, t.lastSeen, handling " .
				" from syslogThreat t join syslog s on s.syslogId = t.syslogId where syslogThreatId = ?";

//	print "<h2>Last 10 syslogThreat entries</h2>";

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
	print '<tr><td>ThreatId</td><td>'.$row["syslogThreatId"].'"></td></tr>';
	print "<tr><td>rawmessage</td><td>".$row["rawmessage"]."</td></tr>";

	print "<tr><td>owner_id</td><td>".$row["owner_id"]."</td></tr>";
	print "<tr><td>unit_id</td><td>".$row["unit_id"]."</td></tr>";
	print "<tr><td>confirmed_unit_id</td><td>".$row["confirmed_unit_id"]."</td></tr>";
	print "<tr><td>is_attack</td><td>".$row["is_attack"]."</td></tr>";
	print "<tr><td>action</td><td>".$row["action"]."</td></tr>";
	print "<tr><td>src_ip</td><td>".$row["src_ip"]."</td></tr>";
	print "<tr><td>src_port</td><td>".$row["src_port"]."</td></tr>";
	print "<tr><td>dst_ip</td><td>".$row["dst_ip"]."</td></tr>";
	print "<tr><td>dst_port</td><td>".$row["dst_port"]."</td></tr>";

	print "<tr><td>protocol</td><td>".$row["protocol"]."</td></tr>";
	print "<tr><td>botnetId</td><td>".$row["botnetId"]."</td></tr>";
	print "<tr><td>severity</td><td>".$row["severity"]."</td></tr>";
	print "<tr><td>handled</td><td>".$row["handled"]."</td></tr>";

	print "<tr><td>service</td><td>".$row["service"]."</td></tr>";
	print "<tr><td>description</td><td>".$row["description"]."</td></tr>";
	print "<tr><td>count</td><td>".$row["count"]."</td></tr>";
	print "<tr><td>lastSeen</td><td>".$row["lastSeen"]."</td></tr>";
	print "<tr><td>handling</td><td>".$row["handling"]."</td></tr>";
	print "<tr><td>device</td><td>".$row["device"]."</td></tr>";

	print "<table>";


    $result->free();
    $conn->close();

}

?>
