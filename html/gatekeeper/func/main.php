<?php

function main()
{
	global $setupRow;
	if (!isset($setupRow))
	{
		print "**** ERROR ***** Couldn't check if db server";
		return;
	}

	// The Demo has its own menu choice. Keep Home focused on operational
	// information instead of duplicating the Demo contents here.
	$bIsDbServer = ((int)$setupRow['isDbServer'] === 1);
	if ($bIsDbServer)
	{
		require_once("func/dbServer.php");
		dbServer();
	}

	// Administrators can see the latest non-hidden manager requests on Home,
	// including completed requests, so approval results remain visible.
	if (isAdmin())
	{
		$conn = getConnection();
		$result = $conn->query("SELECT COUNT(*) AS visible FROM managerRequest WHERE hidden=b'0'");
		$row = $result ? $result->fetch_assoc() : null;
		$visible = $row ? (int)$row['visible'] : 0;
		if ($result) $result->free();
		$conn->close();

		if ($visible > 0)
		{
			require_once("func/managerApprovals.php");
			managerApprovals();
		}
	}
}

?>