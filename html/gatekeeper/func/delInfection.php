<?php

function delInfection()
{
	if (isset($_GET["id"]))
	{
		$conn = getConnection();
		switch ($_GET["action"])
		{
			case "activate":
				$szSQL = "update internalInfections set active = b'1', handled = b'0' where infectionId = ?";
				break;
			case "deactivate":
				$szSQL = "update internalInfections set active = b'0', handled = b'0' where infectionId = ?";
				break;
			case "delete":
				//Don't let user delete Infection that is not yet handled (which would mean the infection would remain active with no way to deactivate than restart kernel)
				$szSQL = "select if (handled,1,0) as handled from internalInfections where infectionId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				if ($result = $stmt->get_result()) // get the mysqli result
					if ($row = $result->fetch_assoc())
	 					if ($row["handled"]+0 == 0)
	 					{
	 						print "You're not allowed to delete infection that is not yet sent to kernel. Please wait 10sek.";
	 						return;
	 					}
				
				$szSQL = "delete from internalInfections where infectionId = ?";
				break;
		}
	}	
	else
		print "******** ERROR in parameters...";

		$stmt = $conn->prepare($szSQL);
		if (!$stmt)
		{
			print "Error binding... aborting.";
			return;
		}
		$stmt->bind_param("i", $_GET["id"]); 
	        $stmt->execute();

       	listInfections();

}
?>
