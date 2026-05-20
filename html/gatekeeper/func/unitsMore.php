<?php
//unitsMore.php
    print "More info about router in network.";


function unitsMore()
{
	if (!isAdmin())
		return;
	
	$conn = getConnection();
	$sql = "select routerId, name, partnerStatusReceived as time, inet_ntoa(ip) as ip, status from partnerRouter r join partner p on p.partnerId = r.partnerId where routerId = ?";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("i", $_GET["id"]);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
		$row = $result->fetch_assoc();
	else
		$row = 0;

	if ($row) 
	{
		print "<table>";

		$status = json_decode($row["status"], true);
		print '<tr><td>Name</td><td>'.$row["name"].'</td></tr>';
		print '<tr><td>Status</td><td>'.$row["status"].'</td></tr>';
	/*	print '<td>'.sjekk($status["lnk"]).'</td>';
		$szLoad = $status["ld"];
		print '<td>'.$szLoad.'</td>';
		$szMem = $status["mem"];
		print '<td>'.$szMem.'</td>';
		$szDisk = $status["df"];
		print '<td>'.$szDisk.'</td>';
		print '<td><a href="index.php?f=unitsMore&id='.$row["routerId"].'">[More info]</a></td></tr>'; 
	*/
		print "<table>";

	}
}
?>