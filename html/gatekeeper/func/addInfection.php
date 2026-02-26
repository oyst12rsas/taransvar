<?php

function addInfection()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		$szSQL = "insert into internalInfections(ip,nettmask,status) values (inet_aton('".$_GET['ip']."'),inet_aton('".$_GET['mask']."'),'".$_GET['cat']."')";
		//print "<br>$szSQL<br>";
		$conn->query($szSQL) or die(mysql_error());
		print "Think it's saved now.........<br><br>";
		print '<a href="index.php?f=infections">List infections</a>';
		return;
	}

?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Nettmask</td><td><input name="mask" value="255.255.255.255"></td></tr>
<tr><td>Category</td><td><select name="cat">
<option value="firsttime">First time<option>
<option value="sporadic">Sporadic<option>
<option value="hack">Hack<option>
<option value="dos">DOS-attack<option>
<option value="hotspot">Hotspot<option>
<option value="bot">Bot<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addinfection"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}


?>
