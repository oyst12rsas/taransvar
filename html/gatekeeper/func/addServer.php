<?php

function addServer()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		$szSQL = "insert into internalServers(ip,port,publicPort, protection) values (inet_aton('".$_GET['ip']."'),'".$_GET['port']."','".$_GET['eport']."','".$_GET['protection']."') on duplicate key update protection = protection";
		//print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}

		print '<br><a href="index.php?f=servers">List servers</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Internal port</td><td><input name="port"></td></tr>
<tr><td>External port</td><td><input name="eport"></td></tr>
<tr><td>Protection</td><td><select name="protection">
<option value="clean">Clean<option>
<option value="presumed_clean">Presumed clean<option>
<option value="no_bots">No BOTs<option>
<option value="all">All<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addserver"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
