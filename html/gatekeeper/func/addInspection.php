<?php

function addInspection()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into inspection(ip, nettmask, handling, active) values (inet_aton('".$_GET['ip']."'),inet_aton('".$_GET['nett']."') ,'".$_GET['handling']."', b'1')";
		print "<br>SQL to run:<br>$szSQL<br><br>";

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

		print '<br><a href="index.php?f=inspections">List inspections</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Nettmask</td><td><input name="nett"></td></tr>
<tr><td>Handling</td><td><select name="handling">
<option value="Drop">Drop<option>
<option value="Inspect">Inspect<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addInspection"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
