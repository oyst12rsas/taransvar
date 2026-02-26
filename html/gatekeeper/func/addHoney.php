<?php


function addHoney()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into honeyport(port, handling, description) values (".$_GET['port'].",'".$_GET['handling']."','".$_GET['desc']."')";
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

		print '<br><a href="index.php?f=honey">Back to honeypots</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>Port</td><td><input name="port"></td></tr>
<tr><td>Handling</td><td><select name="handling">
<option value="block">Block<option>
<option value="normal">Normal<option>
<option value="ssh">SSH<option>
<option value="SQL-server">SQL-server<option>
<option value="samba">Samba<option>
</select></td></tr>
<tr><td>Description</td><td><input name="desc"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addHoney"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
