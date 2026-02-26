<?php
function delPartner()
{
		$conn=getConnection();
		//$szSQL = "delete from partner where partnerId = ".$_GET["id"];
		//print "<br>$szSQL<br>";
//		$conn->query($szSQL) or die(mysql_error());
		
		$szSQL = "delete from partner where partnerId = ?";
		
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $id);
		$id = $_GET['id'];
                $stmt->execute();
		
		print "Think it's deleted now.........<br><br>";
		print '<a href="index.php?f=partners">List partners</a>';
		return;
}

?>
