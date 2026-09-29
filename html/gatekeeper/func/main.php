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

	// The same live dots used on Units belong on Home for operators.
	if (isAdmin()) {
		print '<h2>Node status</h2><p><a href="index.php?f=aiStatus">AI status report</a> · <a href="https://tarasec.org/ops/agent/">Agent approvals</a> · <a href="index.php?f=units">All units</a></p>';
		require_once 'func/units.php';
		vpn_demo();
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
