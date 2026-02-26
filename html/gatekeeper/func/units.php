<?php


function units()
{
	$conn = getConnection();
	$cLookup = getConnection();

	//$sql = "select U.unitId, UP.created, mac, vci, inet_ntoa(UP.ipAddress), inet_ntoa(U.ipAddress), hostname from unitPort UP join unit U on U.unitId = UP.unitId where created > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by U.unitId, UP.created desc;";

#	$sql = "select U.unitId, description, greatest(discovered, lastSeen) as discovered, hex(mac) as mac, vci, inet_ntoa(S.ip) as ip, inet_ntoa(U.ipAddress), hostname, hex(dhcpClientId) as dhcpClientId from dhcpSession S join unit U on clientId = unitId where discovered > DATE_SUB( NOW() , INTERVAL 1 DAY ) or lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by greatest(discovered, lastSeen) desc;";
	$sql = "select unitId, description, lastSeen, hex(mac) as mac, vci, inet_ntoa(ipAddress) as ip, hostname, hex(dhcpClientId) as dhcpClientId from unit where  lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by lastSeen desc;";

	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Active units (connected clients in sub network):</h2><table><tr><td>Hostname</td><td>DHCP Client ID</td><td>Vendor</td><td>Nickname</td><td>Mac</td><td>Last seen</td><td>Last IP</td><td>Ports</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			$szM = (isset($row["mac"])?$row["mac"]:"");
		        $szMac = (strlen($szM) > 12 && substr($szM, 12) == "00000000000000000000" ? substr($szM, 0,12) : $szM);
		        $szDescription = $row["description"].'<a href="index.php?f=edtDesc&id='.$row["unitId"].'">[Edit]</a>';
		       
		        //Assemble list of last ports used..
                        $szPorts = '<a href="index.php?f=showPorts&id='.$row["unitId"].'">[Show]</a>';
		       
	    		print "<tr><td>".$row["hostname"]."</td><td>".$row["dhcpClientId"]."</td><td>".$row["vci"]."</td><td>".$szDescription."</td><td>".$szMac."</td><td>".$row["lastSeen"]."</td><td>".$row["ip"]."</td><td>".$szPorts."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No DHCP IP assignments registered. You should make sure misc/crontasks.pl<br>is registered with cron. See the script file for instructions.<br>";
	}

        //******************************* Show prot assignments *******************************
	$sql = "select portAssignmentId, UP.created, ifnull(U.unitId,-1) as unitId, inet_ntoa(UP.ipAddress) as ip, UP.port, description, hostname, hex(dhcpClientId) as dhcpClientId, hR.created as attacked from unitPort UP left outer join unit U on U.unitId = UP.unitId left outer join hackReport hR on hR.port = UP.port and hR.created >  DATE_SUB(NOW(), INTERVAL '1' HOUR)
	order by portAssignmentId desc limit 100";
	//print "$sql<br>";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>NAT - external port assignments:</h2><table><tr><td>Unit</td><td>Time</td><td>IP</td><td>Port</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
		        if (isset($row["description"]) && strlen($row["description"])) {
        		         $szDescription = $row["description"];
		        } else {
		                if (isset($row["hostname"]) && strlen($row["hostname"])) {
        		                $szDescription = $row["hostname"];
        		        } else {
                		        if (isset($row["vci"]) && strlen($row["vci"])) {
                        		        $szDescription = $row["vci"];
                		        } else {
                		        	if (isset($row["dhcpClientId"])) {
                        		        	$szDescription = $row["dhcpClientId"];
                        		        } else {
        		         			$szDescription = ($row["unitId"]+0 == -1?'<font color="red">*** UNKNOWN ***</font>':"'*** ERROR (shouldn't happen) ***'");
        		         		}
                		        }
        		        }
		        }
		        $szAttacked = '<font color="red"><b>'.$row["attacked"].'</b></font>';
	    		print "<tr><td>".$szDescription."</td><td>".$row["created"]."</td><td>".$row["ip"]."</td><td>".$row["port"]."</td><td>".$szAttacked."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No port assignments registered. Run misc/diagnose.pl to debug or <a href=\"index.php?f=warnings\">check error messages</a>.<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	//print '<br><a href="index.php?f=addpartner">Add partner</a>';
	print '<a href="index.php?f=dhcpLease">See dhcp leases</a>';
}


?>
