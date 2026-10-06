<?php

function main()
{
	global $setupRow;
	if (!isset($setupRow))
	{
		print "**** ERROR ***** Couldn't check if db server";
		return;
	}

	// Keep Home focused on operational information. Demo activity belongs under
	// the existing top-level Demo menu choice.
	$bIsDbServer = ((int)$setupRow['isDbServer'] === 1);
	if ($bIsDbServer)
	{
		require_once("func/dbServer.php");
		dbServer();
	}

	// Show only this server's status on Home. Units owns the network-wide list.
	if (isAdmin()) {
		print '<h2>This computer</h2>';
        if ($bIsDbServer) {
            print '<p><a href="index.php?f=partnerObservation">Partner observation and alarms</a></p>';
            try {
                $alarmDb=getConnection();
                $alarm=$alarmDb->query("SELECT COUNT(*) n FROM partnerObservation WHERE status='alarm' AND created>DATE_SUB(NOW(),INTERVAL 1 DAY)")->fetch_assoc();
                if ((int)$alarm['n']>0) print '<p><a href="index.php?f=partnerObservation"><img src="img/red_dot.png" alt="Alarm"> Partner tagging alarms require review</a></p>';
                $alarmDb->close();
            } catch(Throwable $e) {}
        }
		print '<p><a href="index.php?f=aiStatus">AI status report</a> · <a href="https://tarasec.org/ops/agent/">Agent approvals</a> · <a href="index.php?f=units">All units</a></p>';
		require_once 'ajax/units_partners.php';
		require_once 'func/unitsMore.php';
		$conn = getConnection();
		$result = $conn->query('SELECT networkStatus AS status, TIMESTAMPDIFF(SECOND, networkStatusChecked, NOW()) AS seconds_since FROM setup LIMIT 1');
		$local = $result ? $result->fetch_assoc() : null;
		if ($result) $result->free();
		$conn->close();
		if ($local && is_string($local['status']) && $local['status'] !== '') {
			$age = (int)$local['seconds_since'];
			print '<table><tr><td><a href="index.php?f=unitsMore">Status details</a></td><td>'.getServerStatus($age, $local['status'], 0).'</td></tr></table>';
			$status = json_decode($local['status'], true);
			if (is_array($status) && count(collectUnitIssues($status, $age)))
				printUnitIssues($status, $age);
		} else {
			print '<p><a href="index.php?f=unitsMore"><img src="img/yellow_dot.png" alt="Status unavailable"> Status unavailable for this computer</a></p>';
		}
	}

	// Administrators can see recent manager requests on Home, including
	// completed requests, so approval results remain visible.
	if (isAdmin())
	{
		$conn = getConnection();
		$result = $conn->query("SELECT COUNT(*) AS total FROM managerRequest");
		$row = $result ? $result->fetch_assoc() : null;
		$total = $row ? (int)$row['total'] : 0;
		if ($result) $result->free();
		$conn->close();

		if ($total > 0)
		{
			require_once("func/managerApprovals.php");
			managerApprovals();
		}
	}
}

?>

