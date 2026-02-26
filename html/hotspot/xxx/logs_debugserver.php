<?php

function logs_debugserver()
{
	$pDb = new CDb;
	$cFlds = array();
	$pDb->execute("update adminSetup set maintenanceRequest = 'debugserver'", $cFlds);

	print red("Request for updated debug information is registered! Check the status again in 30 secondes using the link below.")."<br><br>";
	listServerStatu();
}

?>
