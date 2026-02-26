<?php

function warnings()
{
	$conn = getConnection();
	
	if (isset($_GET["id"]))
	{
		$szSQL = "update warning set handled = now() where warningId = ?";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("d", $_GET["id"]);
		$stmt->execute();
	}
	
	$sql = "select warningId, lastWarned, warning from warning where handled is null order by lastWarned desc limit 20";
	$result = $conn->query($sql);
	print "<table>";
	while ($row = $result->fetch_assoc())
	{
		print "<tr><td>".$row["lastWarned"]."</td><td>".$row["warning"]."</td><td>".getCloseWarningLink($row["warningId"])."</td></tr>"; 
	}
	print "</table>";
}

?>
