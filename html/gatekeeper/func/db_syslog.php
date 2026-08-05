<?php

function printStatistics($szSQL)
{
	$conn = getConnection();

	$result = $conn->query($szSQL);
	if (!$result ||!$result->num_rows)
		return 0;
	$nCount = 0;
	while ($row = $result->fetch_assoc())
	{
		if (!$nCount++)
			print "<table><tr><th>IP</th><th>Count</th></tr>";


		print '<tr><td>'.$row["value"].'</td><td>'.$row["count"].'</td><tr>';
	}

	if ($nCount)
		print "</table>";
	else 
		print "<b>No records found</b>";

	$conn->close();
	return $nCount;
}

function printSql($szSQL)
{
    $conn = getConnection();

    $result = $conn->query($szSQL);
    if (!$result || $result->num_rows == 0) {
        if ($conn) 
			$conn->close();
        print "<b>No records found</b>";
        return 0;
    }

    $nCount = 0;

    print "<table border='1'>";
    
    // Header row from field names
    print "<tr>";
    while ($field = $result->fetch_field()) {
        print "<th>" . htmlspecialchars($field->name) . "</th>";
    }
    print "</tr>";

    // Data rows
    while ($row = $result->fetch_assoc()) {
        print "<tr>";
        foreach ($row as $value) {
            print "<td>" . htmlspecialchars((string)$value) . "</td>";
        }
        print "</tr>";
        $nCount++;
    }

    print "</table>";

    $result->free();
    $conn->close();

    return $nCount;
}

function db_syslog()
{
	print "<h1>Remote Syslog entries</h1>";

	$conn = getConnection();

	//Number of records per source IP:
	$szSQL = "select src_ip, inet_ntoa(src_ip) as value, count(syslogThreatId) as count from syslogThreat where TIMESTAMPDIFF(HOUR, coalesce(lastSeen, created), NOW()) <= 48 group by dst_ip";
	print "<h2>Syslog entries last 48 hours per IP</h2>";
	printStatistics($szSQL);

	$szSQL = "select syslogId, created, TIMESTAMPDIFF(SECOND, coalesce(lastSeen, created), NOW()) as seconds_ago, rawmessage from syslog order by syslogId desc limit 10";
	print "<h2>Last 10 syslog entries</h2>";

	//printSql($szSQL);

    $conn = getConnection();

    $result = $conn->query($szSQL);
    if (!$result || $result->num_rows == 0) {
        if ($conn) $conn->close();
        print "<b>No records found</b>";
        return 0;
    }

    $nCount = 0;

    print "<table border='1'>";
    
    // Header row from field names
    print "<tr>";
	print "<th>ID</th>";
	print "<th>#sec</th>";
	print "<th>raw message</th>";
    print "</tr>";

    // Data rows
    while ($row = $result->fetch_assoc()) {
        print "<tr>";
		print '<td><a href="index.php?f=syslogRec&id='.$row["syslogId"].'">'.$row["syslogId"].'</td>';
		print '<td>'.$row["seconds_ago"].'</td>';
		print '<td>'.$row["rawmessage"].'</td>';
        print "</tr>";
        $nCount++;
    }

    print "</table>";

    $result->free();
    $conn->close();





# Number of records per dst_ip (how many different IPs have they targeted)
# select src_ip, inet_ntoa(src_ip), count(distinct dst_ip) from syslogThreat group by dst_ip;

# How many src_ip have checked each target IP
# select dst_ip, inet_ntoa(dst_ip), count(distinct src_ip) from syslogThreat group by src_ip;	
}
?>