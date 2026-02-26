<?php

function delAttack()
{
	if (isset($_GET["id"]))
	{
		$conn = getConnection();
		switch ($_GET["action"])
		{
			case "deactivate":
				$szSQL = "update assistanceRequest set active = b'0', handled = NULL where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
			case "activate":
				$szSQL = "update assistanceRequest set active = b'1', handled = NULL where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
			case "delete":
				$szSQL = "delete from assistanceRequest where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
		}
        	//print "I think it's ".$_GET["action"]."d...<br><br><a href=\"index.php?f=attack\">See list</a>";
        	attack();
	}	
	else
		print "******** ERROR in parameters...";
}

?>
