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
}  

?>