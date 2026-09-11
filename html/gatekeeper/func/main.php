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