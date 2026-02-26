<?php



function attack()
{
//

	$sql = "select requestId, created, inet_ntoa(ip) as ip, port, category, comment, requestQuality, wantSpoofed, active, purpose, handled from assistanceRequest order by requestId desc limit 20";
	//print "$sql<br>";
	$conn = getConnection();
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Assistance requests:</h2><table><tr><td>Created</td><td>IP:port</td><td>Category</td><td>Comment</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			if ($row["active"]) 
			{
				$szFont = $szFontEnd = "";
				$szAction = "deactivate";
				$szExtraAction = "";
			}
			else
			{
				$szFont = "<font color=\"red\">";
				$szFontEnd = "</font>";
				$szAction = "activate";
				$szExtraAction = "<a href=\"index.php?f=delAttack&id=".$row["requestId"]."&action=delete\">[delete]</a>";
			}
			$szStatus = $row["active"];//"??";
	    		print "<tr><td>".$szFont.$row["purpose"].$szFontEnd."</td><td>".$szFont.$row["created"].$szFontEnd."</td><td>".$szFont.$row["ip"].":".$row["port"].$szFontEnd."</td><td>".$szFont.$row["category"].$szFontEnd."</td><td>".$szFont.$row["comment"].$szFontEnd."</td><td>".$szFont.$szStatus.$szFontEnd."</td><td><a href=\"index.php?f=delAttack&id=".$row["requestId"]."&action=".$szAction."\">[".$szAction."]</a>".$szExtraAction."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
		print "<a href=\"index.php?f=addassreq\">Add assistance request manually</a>";
	} 


}

?>
