<?php


function delHoney()
{
        print "Should save......";
        $szSQL = "delete from honeyport where port = ".$_GET["port"];
	$cConn = getConnection();
	print "<br>SQL: $szSQL<br>";
	$cConn->query($szSQL) or die (mysql_error());
	print 'Honeyport has been deleted...<br><br><a href="index.php?f=honey">Back to list...</a>';
}

?>
