<?php


function dbServerMenu()
{ ?>
<table>
<tr>
<td bgcolor="white"><a href="index.php?f=db_syslog">syslog</a></td>
<td bgcolor="white"><a href="index.php?f=servers">Server</a></td>
<td bgcolor="white"><a href="index.php?f=domains">Domains</a></td>
<td bgcolor="white"><a href="index.php?f=colorListings">W/B List</a></td>
<td bgcolor="white"><a href="index.php?f=inspections">Inspections</a></td>
<td bgcolor="white"><a href="index.php?f=assistance">Assistance</a></td>
<td bgcolor="white"><a href="index.php?f=honey">Honey</a></td>
<!----------------- <td bgcolor="white"><a href="index.php?f=workshops">Workshop</a></td> ------------->
<td bgcolor="white"><a href="index.php?f=setup">Setup</a></td>
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