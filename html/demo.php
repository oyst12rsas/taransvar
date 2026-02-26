<?php
include "dbfunc.php";

function getDemo()
{
	$conn = getConnection();

	$sql = "SELECT * from demo";
	$result = $conn->query($sql);
	$bOk = $result->num_rows > 0 && $row = $result->fetch_assoc(); 
	$conn->close();
	if ($bOk)
		return $row;
	else
		return 0;
}

$demoRow = getDemo();



$conn = getConnection();



if (!$demoRow)
{
	$szSQL = "insert into demo (ipTargetHost, ipBotHost, ipBot,status, activeDemo) values ( inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), 'to be replaced', b'1')";
	$stmt = $conn->prepare($szSQL);
	//$stmt->bind_param("i", $_GET["port"]); //$_GET["ip"], 
        $stmt->execute();

}



$szSQL = "update demo set ipTargetHost = inet_aton(?), ipBotHost = inet_aton(?), ipBot = inet_aton(?)";

$stmt = $conn->prepare($szSQL);

$stmt->bind_param("sss", $_GET["targethostip"], $_GET["bothostip"], $_GET["botip"]);  
$stmt->execute();


?>ok
