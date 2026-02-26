<?php

function delRouter()
{//
		$conn=getConnection();
		$nPartnerId = getString("select partnerId from partnerRouter where routerId = ".$_GET["id"]+0);
		$szSQL = "delete from partnerRouter where routerId = ?";
		//print "<br>$szSQL<br>";
		//$conn->query($szSQL) or die(mysql_error());
		
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $nId);
		$nId = $_GET['id'];
                $stmt->execute();
		
		print "Think it's deleted now.........<br><br>";
		print '<a href="index.php?f=partner&id='.$nPartnerId.'">Back to partner</a>';
		return;
}

?>
