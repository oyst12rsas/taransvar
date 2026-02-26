<?php

include_once "saveDemoInfo.php";

function editDemo()
{
	if (isset($_GET["submit"]))
	{
		saveDemoInfo();
		return;		
	}

	$conn = getConnection();

	$sql = "SELECT inet_ntoa(ipTargetHost) as ipTargetHost, inet_ntoa(ipBotHost) as ipBotHost, inet_ntoa(ipBot) as ipBot, iAm from demo limit 1";
	$result = $conn->query($sql);
	$bOk = $result->num_rows > 0 && $row = $result->fetch_assoc(); 
	$conn->close();

	if ($bOk)
		printDemoForm($row, "editDemo");
}


?>
