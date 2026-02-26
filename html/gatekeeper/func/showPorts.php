<?php


function showPorts()
{
        //
        $szSQL = "select unitId, port, created from unitPort where unitId = ".$_GET["id"] ;
        $conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Port assignments:</h2><table><tr><td>Time</td><td>Port</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["created"]."</td><td>".$row["port"]."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
        } 
        else
                print "No port assignments found...<br><br>";
        
        print "<a href=\"index.php?f=units\">Go back to units</a>";
}

?>
