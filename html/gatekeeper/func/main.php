<?php

function main()
{
	global $setupRow;
	if (!isset($setupRow))
	{
		print "**** ERROR ***** Couldn't check if db server";
		return;
	}

	$value = $setupRow['isDbServer'];

	$bIsDbServer = ((int)$value === 1);

	if ($bIsDbServer)
	{
		require_once("func/dbServer.php");
		dbServer();
	}
	else
	{
		require_once("func/demo.php");
		demo();
	}

	// Keep manager approvals where an administrator is most likely to notice
	// them. Show the section on Home only while this gateway has an actionable
	// manager request waiting for gateway approval.
	if (isAdmin())
	{
		$conn = getConnection();
		$result = $conn->query("SELECT COUNT(*) AS pending FROM managerRequest WHERE gatewayApprovedTime IS NULL AND rejectedTime IS NULL AND (expires IS NULL OR expires > NOW())");
		$row = $result ? $result->fetch_assoc() : null;
		$pending = $row ? (int)$row['pending'] : 0;
		if ($result) $result->free();
		$conn->close();

		if ($pending > 0)
		{
			require_once("func/managerApprovals.php");
			managerApprovals();
		}
	}
}  

?>