<?php

require_once("../taraLib.php");

function attack()
{
//

	$sql = "select requestId, created, TIMESTAMPDIFF(SECOND, created, NOW()) AS seconds_since, inet_ntoa(ip) as ip, port, category, comment, requestQuality, wantSpoofed, active, senttime, purpose, handled from assistanceRequest order by requestId desc limit 20";
	//print "$sql<br>";
	$conn = getConnection();
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Assistance requests:</h2><table><tr><td>Purpose</td><td>Age</td><td>IP:port</td><td>Category</td><td>Comment</td><td>Active</td><td>&nbsp;</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			if (!strcmp($row["purpose"], "forDistribution"))
			{
				$szFont = $szFontEnd = "";
				$szAction = "";
				$szExtraAction = "";
				$szActionUrl = "";
			}
			else
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

				$szActionUrl = "<a href=\"index.php?f=delAttack&id=".$row["requestId"]."&action=".$szAction."\">[".$szAction."]</a>".$szExtraAction;
			}			

			$szStatus = ($row["active"]+0?"Active":"Inactive");//"??";
			if ($row["senttime"])
				$szComment = $szFont.$row["comment"].$szFontEnd;
			else
				$szComment = '<font color="red"><b>NOTE! Request for assistance is not yet sent (by DB server?). This is meant to happen immediately!</b></font>';

	    	print "<tr><td>".$szFont.$row["purpose"].$szFontEnd."</td><td>".$szFont.age($row["seconds_since"]).$szFontEnd."</td><td>".$szFont.$row["ip"].":".$row["port"].$szFontEnd."</td><td>".$szFont.$row["category"].$szFontEnd."</td><td>".$szComment."</td><td>".$szFont.$szStatus.$szFontEnd."</td><td>".$szActionUrl."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
		print "<a href=\"index.php?f=addassreq\">Add assistance request manually</a>";
	} 


}

?>
