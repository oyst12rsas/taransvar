<?php
//db_ai.php

require_once("../taraLib.php");

function db_ai()
{
	$conn = getConnection();
	//$sql = "select aiAssessment, aiAssessmentTime, TIMESTAMPDIFF(SECOND, aiAssessmentTime, NOW()) AS seconds_since from setup";
	$sql = "select aiResponseId, TIMESTAMPDIFF(SECOND, created, NOW()) AS age, seconds, response from aiResponse order by aiResponseId desc limit 5";

	$stmt = $conn->prepare($sql);
	//$stmt->bind_param("si", $szSenderIp, $clientPort); 
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	print "<table>";
	print "<tr><td>ID</td><td>Time</td><td>... summary here.... </td></tr>";
	if ($result) 
	{
		while ($row = $result->fetch_assoc()) 
		{
			$time = ($row["age"]?age($row["age"]):"Error");
			print '<tr><td><a href="index.php?f=aiRecord&id='.$row["aiResponseId"].'">'.$row["aiResponseId"].'</a></td><td>'.$time.'</td><td>... summary here.... </td></tr>';

		}
	}

	$result->free();
	$conn->close();

}

?>