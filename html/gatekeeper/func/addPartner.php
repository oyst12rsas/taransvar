<?php

function addPartner()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		//$szSQL = "insert into partner(name, adminEmail, adminPhone, techEmail, techPhone) values ('".$_GET['name']."','".$_GET['amail']."','".$_GET['aphone']."','".$_GET['tmail']."','".$_GET['tphone']."')";
		//$conn->query($szSQL) or die(mysql_error());
		
		$szSQL = "insert into partner(name, adminEmail, adminPhone, techEmail, techPhone) values (?,?,?,?,?)";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("sssss", $name, $adminEmail, $adminPhone, $techEmail, $techPhone);
		$name = $_GET['name'];
                $adminEmail = $_GET['amail'];
                $adminPhone = $_GET['aphone'];
                $techEmail = $_GET['tmail'];
                $techPhone = $_GET['tphone'];
                $stmt->execute();
		
		//print "<br>$szSQL<br>";
		print "Think it's saved now.........<br><br>";
		print '<a href="index.php?f=partners">List partners</a>';
		return;
	}

?>
<form action="index.php"><table>
<tr><td>Name</td><td><input name="name"></td></tr>
<tr><td>Adm Email</td><td><input name="amail"></td></tr>
<tr><td>Adm Phone</td><td><input name="aphone"></td></tr>
<tr><td>Tech Email</td><td><input name="tmail"></td></tr>
<tr><td>Tech Phone</td><td><input name="tphone"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addPartner"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

?>
