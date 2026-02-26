<?php

function domainDel()
{
	if (isset($_GET["id"]))
	{
		$cConn = getConnection();
		$szSQL = "delete from domainIp where domainId = ".$_GET["id"];
		$cConn->query($szSQL) or die (mysql_error());
		$szSQL = "delete from domain where domainId = ".$_GET["id"];
		$cConn->query($szSQL) or die (mysql_error());
		print 'Domain should have been deleted now.<br><br><a href="index.php?f=domains">List domains</a>';
	}
	else
		print "Error deleting....";
}

?>
