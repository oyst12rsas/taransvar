<?php

function main_shutdown()
{

	$pDb = new CDb;
	$cFlds = array();
	$pDb->execute("update setup set requestShutdown = b'1'", $cFlds);

        print b("The system is set to shut down.. It should happen within 10 seconds.");

}

?>
