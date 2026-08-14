<?php


function dbServerMenu()
{ ?>
<table>
<tr>
<td bgcolor="white"><a href="index.php?f=db_syslog">syslog</a></td>
<td bgcolor="white"><a href="index.php?f=db_ai">AI</a></td>
</tr>
</table>
<?php
}


function dbServer()
{
	dbServerMenu();

	print "<h1>DB server</h1>";
}

?>