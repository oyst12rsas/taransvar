<?php

function listrouters()
{
	$conn=getConnection();
	$szSQL = "select inet_ntoa(ip) as ip, partnerStatusReceived from partnerRouter";
	//print "<br>$szSQL<br>";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);

        if ($result->num_rows > 0) 
        {
        	// output data of each row  
        	print "<h2>All registered routers:</h2><table>";
	        $nCount=0;
	        while($row = $result->fetch_assoc()) 
	        {
	        	if (!$nCount)
	        		print "<tr><td>IP</td><td>Status received</td></tr>";
	                print "<tr><td>".$row["ip"]."</td><td>".$row["partnerStatusReceived"]."</td></tr>";
	                $nCount++;
	        }
	        print "</table>";
        }
}

?>
