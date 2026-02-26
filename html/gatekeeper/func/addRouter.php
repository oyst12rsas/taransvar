<?php

function addRouter()
{
	if (isset($_GET["submit"]))
	{
		if (filter_var(trim($_GET['ip']), FILTER_VALIDATE_IP) && filter_var(trim($_GET['nett']), FILTER_VALIDATE_IP)) 
		{
			$conn=getConnection();
			/*$szSQL = "insert into partnerRouter(partnerId, ip, nettmask) values (?,INET_ATON(?),INET_ATON(?))";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("iss", $nId, $szIp, $szNett);
			$nId = $_GET['id'];
			$szIp = trim($_GET['ip']);
			$szNett = trim($_GET['nett']);
	                $stmt->execute();*/
	                $nId = $_GET['id']+0;
			$szSQL = "insert into partnerRouter(partnerId, ip, nettmask) values (".$nId.",INET_ATON('".trim($_GET['ip'])."'),INET_ATON('".trim($_GET['nett'])."'))";
			//print "$szSQL<br>";
	                $result = $conn->query($szSQL);
	                if (!$result)
	                {
	                	print "***** SQL FAILED ******<br><br>";
	                }
	                else
	                {
				print "Think it's saved now.........<br><br>";
				print '<a href="index.php?f=partner&id='.$_GET['id'].'">Back to partner</a>';
				return;
			}
	        }
	        else
	        {
	        	print "Error in IP address or netmask:<br>IP: ".$_GET['ip']."<br>Nett: ".$_GET['nett']."<br>";
	        }
		
	}

?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip" value="<?php print (isset($_GET['ip'])?$_GET['ip']:"");  ?>"></td></tr>
<tr><td>Nettmask</td><td><input name="nett" value="<?php print (isset($_GET['nett'])?$_GET['nett']:"");  ?>"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addRouter"><input type="submit" name="submit" value="Submit"><input type="hidden" name="id" value="<?php print $_GET['id']; ?>"></td></tr>
</table></form>
<?php
}

?>
