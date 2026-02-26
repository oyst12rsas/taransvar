<?php


function addColorListing()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into colorListings(ip, color) values (inet_aton('".$_GET['ip']."'),'".$_GET['color']."')";
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

		print '<br><a href="index.php?f=domains">List IP color listings</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Color</td><td><select name="color">
<option value="white">White<option>
<option value="black">Black<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addColorListing"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
