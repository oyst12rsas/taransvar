
<script>
var szUpdateRoutine = "units";	
</script>
<?php 


function listServerStatus()
{
	//print "About to list server status.<br>";
	$conn = getConnection();
	$sql = "select routerId, partnerStatusReceived as time, inet_ntoa(ip) as ip, status from partnerRouter";
	$result = $conn->query($sql);
	if ($result->num_rows > 0) 
	{
		print "<table><tr><td>IP</td><td>Reported</td><td>Kernel</td><td>Link</td><td>Crontask</td><td>Load<br>(1 5 15min)</td><td>Mem<br>(used tot)</td><td>Disk<br>(tot used free)</td><td>&nbsp;</td></tr>";
		while ($row = $result->fetch_assoc()) 
		{
			$status = json_decode($row["status"] ?? "", true);
			print '<tr><td>'.$row["ip"].'</td><td>'.$row["time"].'</td>';
			print '<td>'.(isset($status)?sjekk($status["knl"]):"??").'</td>';
			print '<td>'.(isset($status)?sjekk($status["lnk"]):"??").'</td>';
			print '<td>'.(isset($status)?sjekk($status["cron"]):"??").'</td>';
 			$szLoad = isset($status)?$status["ld"]:"??";
			print '<td>'.$szLoad.'</td>';
 			$szMem = isset($status)?$status["mem"]:"??";
			print '<td>'.$szMem.'</td>';
 			$szDisk = isset($status)?$status["df"]:"??";
			print '<td>'.$szDisk.'</td>';
			print '<td><a href="index.php?f=unitsMore&id='.$row["routerId"].'">[More info]</a></td></tr>'; 
		}
		print "</table>";
	}
	else	
		print "No partner routers found!<br>";
}

function printServerStatus($seconds_since, $status, $nId)
{
	print "&nbsp;";
	//print getServerStatus($seconds_since, $status, $nId);
}


function getNetworkStatusThisComputer($conn)
{
	$szSQL = "select networkStatus as status, TIMESTAMPDIFF(SECOND, networkStatusChecked, NOW()) AS seconds_since, nickname as name, length(networkStatus) as len from setup";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);
	$setupRow = false;

	if ($result)
	{
		if ($result->num_rows > 0) 
			$setupRow = $result->fetch_assoc();
		$result->free();
	}
	return $setupRow;
}


function vpn_demo()
{
	$conn=getConnection();

	$setupRow = getNetworkStatusThisComputer($conn);

	$szWhere = !isAdmin()?"where showToAdminsOnly = b'0'":"";
	$szSQL = "select routerId, name, inet_ntoa(ip) as ip, partnerStatusReceived, status, TIMESTAMPDIFF(SECOND, partnerStatusReceived, NOW()) AS seconds_since from partnerRouter R join partner P on P.partnerId = R.partnerId $szWhere";
	//print "<br>$szSQL<br>";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Involved sites:</h2>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			if (!$nCount) 
			{
				print "<b>NOTE</b> ! If you have problems opening these, then check that you VPN setup, Allowed IPs contain 100.68.0.0/16 (add if not)<br>";

				print '<table id ="unitsTbl">';
				print "<tr><td>Site</td><td>IP</td><td>Status *)</td><td>Gatekeeper</td><td>Sample bank</td><td>Honey</td></tr>";
				print "<tr><td>Me (".$setupRow["name"].")</td><td>&nbsp;</td></div><td><div id=\"s0\">";

				print "&ngsp";

				print "</div></td><td>&nbsp;</td><td><a href=\"../samplebank/index.php\">[go to]</a></td><td><a href=\"../honeypot/index.php\">[go to]</a></td></tr>";
			}

			$szGatekeeper = "http://".$row["ip"]."/gatekeeper/index.php";
			$szSamplebank = "http://".$row["ip"]."/samplebank/index.php";
			$szHoneypot =  "http://".$row["ip"]."/honeypot/index.php";
			print "<tr><td>".$row["name"]."</td><td>".$row["ip"]."</td><td><div id=\"s".$row["routerId"]."\">";
			
			printServerStatus($row["seconds_since"], $row["status"], $row["routerId"]);
			print "</div></td><td><a href=\"".$szGatekeeper."\">[go to]</td><td><a href=\"".$szSamplebank."\">[go to]</h></td><td><a href=\"".$szHoneypot."\">[go to]</h></td></tr>";
			$nCount++;
		}
		print "</table>";
		print "*) Dots: tarakernel, taralink, crontasks, dmesg, traffic data, sql connections. Number: Active users last 5 minutes.";
	}
	$result->free();//260717
	$conn->close();
}


function units()
{

	vpn_demo();

	$conn = getConnection();
	if (isAdmin())
	{
		//If logged in as admin and this is a DB server, list reported status.
		$sql = "select adminIP, nettmask, globalDb1ip, globalDb1ip, globalDb1ip from setup";
		$result = $conn->query($sql);
		if ($result->num_rows > 0) 
			if ($row = $result->fetch_assoc()) 
			{
				$adminNett = $row["adminIP"] & $row["nettmask"];
				/*if ($adminNett == ((isset($row["globalDb1ip"])?$row["globalDb1ip"]:-1) & $row["nettmask"]) 
						or $adminNett == ((isset($row["globalDb2ip"])?$row["globalDb2ip"]:-1) & $row["nettmask"])
						or $adminNett == ((isset($row["globalDb3ip"])?$row["globalDb3ip"]:-1) & $row["nettmask"])) */
				if (($row["adminIP"] == isset($row["globalDb1ip"])?$row["globalDb1ip"]:-1)
					or ($row["adminIP"] == isset($row["globalDb2ip"])?$row["globalDb2ip"]:-1)
					or ($row["adminIP"] == isset($row["globalDb3ip"])?$row["globalDb3ip"]:-1)
					)
				{
					print "<br><br><b>You're logged in as admin on DB server... So these units have reported status:</b>";
					listServerStatus();
				}
				else
					print "Admin but not on DB server<br>";
			}
		$result->free();
	}

	print '<h2>Active units (connected clients in sub network):</h2>
	<table id="unitsTbl"><tr><th>Hostname</th><th>DHCP Client ID</th><th>Vendor</th><th>Nickname</th><th>Mac</th><th>Last seen</th><th>Last IP</th><th>Ports</th></tr>';
	print "</table>";
	print '<div id="updateTime">Not yet updated</div>';


    //******************************* Show NAT port assignments *******************************
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
	$result->free();
	$conn->close();
	//print 'Supposed to list servers';
	//print '<br><a href="index.php?f=addpartner">Add partner</a>';
	print '<a href="index.php?f=dhcpLease">See dhcp leases</a>';
}


?>
