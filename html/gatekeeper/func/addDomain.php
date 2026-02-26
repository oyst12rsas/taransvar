<?php

include_once "dnsLookup.php"	//updateIPsForDomain() is defined there

function addDomain()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into domain(domainName, color, active) values ('".$_GET['dname']."','".$_GET['color']."',b'1')";
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
	
		$nNewId = last_insert_id($conn);

		updateIPsForDomain($nNewId, $_GET['dname']);

		print '<br><a href="index.php?f=domains">List domains</a>';
		return;
	}
?>
<h2>Register domain for white/black listing</h2>
<form action="index.php"><table>
<tr><td>Domain</td><td><input name="dname"></td></tr>
<tr><td>Color</td><td><select name="color">
<option value="white">White<option>
<option value="black">Black<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="adddomain"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
