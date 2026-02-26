<?php

function main_reboot()
{

	$pDb = new CDb;
	$cFlds = array();
	$pDb->execute("update setup set requestReboot = b'1'", $cFlds);

        print b("The system is set to reboot.. It should happen within 10 seconds.");

}

?>
