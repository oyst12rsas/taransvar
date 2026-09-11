<?php

function main()
{
	global $setupRow;
	if (!isset($setupRow))
	{
		print "**** ERROR ***** Couldn't check if db server";
		return;
	}

	$bIsDbServer = ((int)$setupRow['isDbServer'] === 1);
	if ($bIsDbServer)
	{
		require_once("func/dbServer.php");
		dbServer();

		// The DB server is the central authority for demo sessions, so Home gives
		// operators a compact view of both live demos and completed history.
		require_once("func/dbServerDemos.php");
		dbServerDemos();
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