<?php

function logs_setupnetwork()
{
	$pDb = new CDb;
	$cFlds = array();
	$pDb->execute("update adminSetup set maintenanceRequest = 'setup_network'", $cFlds);

	print red("Request for network setup is registered! Check the status again in 30 secondes")."<br><br>";
	listServerStatu();
}

?>
