<?php

function partner()
{
	$szSQL = "select name, adminEmail, adminPhone, techEmail, techPhone from partner where partnerId = ".$_GET["id"];
	$conn = getConnection();
	$result = $conn->query($szSQL);
        $nCount =0;

	if ($result->num_rows > 0) 
	{
		if($row = $result->fetch_assoc()) 
		{
		        $szPartnerName = $row["name"];
        ?>
        <table>
                <tr><td>Name</td><td><?php print $row["name"]; ?></td></tr>
                <tr><td>Adm Email</td><td><?php print $row["adminEmail"]; ?></td></tr>
                <tr><td>Adm Phone</td><td><?php print $row["adminPhone"]; ?></td></tr>
                <tr><td>Tech Email</td><td><?php print $row["techEmail"]; ?></td></tr>
                <tr><td>Tech Phone</td><td><?php print $row["techPhone"]; ?></td></tr>
        </table>  <?php
                print "<table>";
                	//$szSQL = "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask from partnerRouter where partnerId = ".$_GET["id"];
                	//Intended to print the ip and nettmask hexadecimalt  $szSQL = 
                	$szSQL = "select routerId, hex(ip) as ip, inet_ntoa(ip) as aip, hex(nettmask) as nettmask from partnerRouter where partnerId = ".$_GET["id"];
	                $result = $conn->query($szSQL); 

	                if ($result->num_rows > 0) 
	                {
	                        print ('<tr><th colspan="2">Registered routers</th></tr>');
                		while ($row = $result->fetch_assoc()) 
		                {
                                        print '<tr><td>'.$row["aip"].'</td><td>'.$row["ip"].'</td><td>'.$row["nettmask"].'</td><td><a href="index.php?f=delRouter&id='.$row["routerId"].'">[Delete]</a></td></tr>';
                                        $nCount++;
                                }
                        }
                        if (!$nCount)
                                print "<tr><td>No routers found</td></tr>";
                        print "</table>";

                        if (!$nCount)
                                print '<a href="index.php?f=delPartner&id='.$_GET["id"].'">[Delete]</a><br><br>';
                                //print '<tr><td>&nbsp;</td><td><a href="index.php?f=delPartner&id='.$_GET["id"].'">Delete partner</a></td></tr>';
                        
                        print '<a href="index.php?f=addRouter&id='.$_GET["id"].'">Add router for '.$szPartnerName.'</a>'; 	
	  	}
                else
	    		print '<tr><td colspan="2">ERROR! Couldn\'t find the partner!</td></tr>';
	} 
	else 
	{
    		print '<tr><td colspan="2">ERROR! Couldn\'t find the partner!</td></tr>';
 	}
}

?>
