<?php
//unitsMore.php
    print "More info about router in network.";


function unitsMore()
{
	if (!isAdmin())
		return;
	
	$conn = getConnection();
	$sql = "select routerId, partnerStatusReceived as time, inet_ntoa(ip) as ip, status from partnerRouter";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) 
		{
			print "<table><tr><td>IP</td><td>Reported</td><td>Kernel</td><td>Link</td><td>Load<br>(1 5 15min)</td><td>Mem<br>(used tot)</td><td>Disk<br>(tot used free)</td><td>&nbsp;</td></tr>";
			while ($row = $result->fetch_assoc()) 
			{
				$status = json_decode($row["status"], true);
				print '<tr><td>'.$row["ip"].'</td><td>'.$row["time"].'</td>';
				print '<td>'.sjekk($status["knl"]).'</td>';
				print '<td>'.sjekk($status["lnk"]).'</td>';
				$szLoad = $status["ld"];
				print '<td>'.$szLoad.'</td>';
				$szMem = $status["mem"];
				print '<td>'.$szMem.'</td>';
				$szDisk = $status["df"];
				print '<td>'.$szDisk.'</td>';
				print '<td><a href="index.php?f=unitsMore&id='.$row["routerId"].'">[More info]</a></td></tr>'; 


?>