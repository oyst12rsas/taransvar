
<script>
var szUpdateRoutine = "units";	
</script>
<?php 

function sjekk($field)
{
	return (isset($field) && $field == "1" ? '<font color="green">[Running]</font>':'<font color="red">[STOPPED]</font>');
}

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
			$status = json_decode($row["status"], true);
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


function getDot($bOk)
{
	return '<img src="img/'.($bOk?"green":"red").'_dot.png">';
}

function getDotByInterval($json, $szTag, $nOk, $nError, $szTitleOk, $szTitleWarn, $szTitleError)
{
//	$json = json_decode($status, true);

 /*   echo "<pre>";
    var_dump($szTag);
    var_dump($status);
    var_dump($json);
    var_dump(json_last_error_msg());
    var_dump(isset($json[$szTag]));
    echo "</pre>";
*/


	if (!isset($json[$szTag]))
		return '<span title="Old version. Script not yet checking '.$szTag.'"><img src="img/yellow_dot.png"></span>';

	$nValue = $json[$szTag]+0;
	if ($nValue <= $nOk)
	{
		$szDot = "green";
		$szTitle = $szTitleOk;
	}
	else
		if ($nValue < $nError)
		{
			$szDot = "yellow";
			$szTitle = $szTitleWarn;
		}
		else
		{
			$szDot = "red";
			$szTitle = $szTitleError;
		}

	return '<span title="'.$szTitle.'"><img src="img/'.$szDot.'_dot.png"></span>';
}


function check($json, $field, $ifTrue, $ifFalse)
{
	if (!isset($json[$field]))
		return '<span title="Old version. Scripts is not yet checking \$field\""><img src="img/yellow_dot.png"></span>';

	return '<span title="'.($json[$field] == "1" ? $ifTrue : $ifFalse).'">'.getDot($json[$field] == "1").'</span>';
}

function getGatewayIP()
{
	//Don't know if we should make this more advance.. It's printed when this machine has not received status from a partner.
	return "100.68.165.190";//10.100.0.1
}

function sizeToKB(string $str): int
{
    if (!preg_match('/^\s*([\d.]+)\s*([KMGT]?i)?\s*$/i', $str, $m)) {
        return 0;   // or throw an exception
    }

    $value = (float)$m[1];
    $unit  = strtoupper($m[2] ?? '');

    $multiplier = match ($unit) {
        'KI' => 1,
        'MI' => 1000,
        'GI' => 1000000,
        'TI' => 1000000000,
        default => 1,   // no unit = KB
    };

    return (int) round($value * $multiplier);
}

function printServerStatus($seconds_since, $status, $nId)
{
	if (!isset($status) || !strlen($status))
		return '<a href="http://'.getGatewayIP().'/gatekeeper/index.php?f=demo">Check status on router</a>';

	$bOldScript = 0;


	//ØT 260720 - enable this...
	//if ($seconds_since+0 > 65)
	//	return '<font color="red">Server is troubled. Better use another. Status: '.$status.'</font>';

	$szNotSet = '<span title="Old version. Scripts not yet updated"><img src="img/yellow_dot.png"></span>';

	$json = json_decode($status, true);
	//$szServerStatus = (isset($status)?check($json["knl"]):$szNotSet);
	$szServerStatus = check($json, "knl", "tarakernel is running", "tarakernel is NOT running");

	//$szServerStatus .= (isset($status)?check($json["lnk"]):$szNotSet);
	$szServerStatus .= check($json, "lnk", "taralink is running", "taralink is NOT running");

	//$szServerStatus .= (isset($status)?check($json["cron"]):$szNotSet);
	$szServerStatus .= check($json, "cron", "crontask.pl (perl task) is running", "crontask.pl (perl task) is NOT running");

	$szServerStatus .= getDotByInterval($json, "dmesg", 20, 90, 
					"Receiving dmesg messages",
					"A bit long since received dmesg. Refresh in a minute may help", 
					"Not receiving dmesg. Please inform tech team");
	//$nSeconds = (isset($json["dmesg"])?$json["dmesg"]:1000000);
	//$szServerStatus .= getDot($nSeconds < 90);
	
	$szServerStatus .= getDotByInterval($json, "trfc", 20, 90, 
					"Receiving traffic reports",
					"A bit long since received traffic. Refresh in a minute may help", 
					"Not receiving traffic reports. Please inform tech team");
	//$nSeconds = (isset($json["trfc"])?$json["trfc"]:1000000);
	//$szServerStatus .= getDot($nSeconds < 90);	

	//Open mysql connections
	$szServerStatus .= getDotByInterval($json, "sqlThrds", 12, 25, 
					"Normal number of sql threads busy",
					"A bit too many sql threads. System may be in trouble", 
					"Too many sql threads. Please inform tech team");
	//$nCount = (isset($json["sqlThrds"])?$json["sqlThrds"]:0);
	//$szServerStatus .= getDot($nCount < 20);	//Current limit is 150, normal < 10

	//Boot required and updates
	//$szServerStatus .= (isset($json["bootReq"])?check($json["bootReq"]):$szNotSet);
	$cTemp = array();
	$cTemp["bootOk"] = (isset($json["bootReq"]) && ($json["bootReq"]==1) ? 0:1);
	$szServerStatus .= check($cTemp, "bootOk", "boot is not required", "the system requires a boot after upgrading");

	$szServerStatus .= "<br>";

	if (isset($json["updates"]))
	{
		$cTemp = explode(";",$json["updates"]);
		$cUpdates = array();
		$cUpdates["total"] = $cTemp[0];
		$cUpdates["security"] = $cTemp[1];

		$szServerStatus .= getDotByInterval($cUpdates, "total", 12, 30, 
					$cUpdates["total"]." updates available",
					$cUpdates["total"]." updates available", 
					$cUpdates["total"]." updates available. You should run sudo apt update");

		$szServerStatus .= getDotByInterval($cUpdates, "security", 0, 1, 
					$cUpdates["security"]." security updates available",
					$cUpdates["security"]." security updates available", 
					$cUpdates["security"]." security updates available. You should run sudo apt update");
	}
	else
		$bOldScript = 1;

	if (isset($json["lstUp"]))
	{
		//Last update run
		$nDays = round($json["lstUp"] / (60*60*24));
		$szServerStatus .= getDotByInterval($json, "lstUp", 60*60*24*7, 60*60*24*30, 
					$nDays." days since update",
					$nDays." days since update. You should run sudo apt update", 
					$nDays." days since update. You should run sudo apt update");
	}
	else
		$bOldScript = 1;

	//Load, disk and memory
	if (isset($json["ld"]))
	{
		$cRes = explode(" ", $json["ld"]);
		$cMax["max"] = max($cRes);
		$szServerStatus .= getDotByInterval($cMax, "max", 0.7, 2, 
					"Server load is ".$cMax["max"].". This is normal",
					"Server load is ".$cMax["max"].". This is a bit high but may be temporary",
					"Server load is ".$cMax["max"].". This is a high and should be checked");
	}
	else
		$bOldScript = 1;

	if (isset($json["df"]))
	{
		$szDF = str_replace("G","000000", $json["df"]);	//E.g: "df":"771G 456G 277G"
		$szDF = str_replace("M","000", $szDF);	//If given in M, also change..
		$cRes = explode(" ", $szDF);
		$nPercentUsed = round((($cRes[1]+0) / ($cRes[0]+0)) * 100);
		$cMax = array();
		$cMax["max"] = $nPercentUsed;
		$szServerStatus .= getDotByInterval($cMax, "max", 70, 90, 
					$nPercentUsed."% of disk used. This is normal",
					$nPercentUsed."% of disk used. This should be monitored",
					$nPercentUsed."% of disk used. Consider freeing space or upgrading.");

	}
	else
		$bOldScript = 1;

	if (isset($json["mem"]))
	{
		$cRes = explode("/", $json["mem"]);
		$szUsage = $cRes[0]." of ".$cRes[1]." is free";
		//$szMem = str_replace("Gi","000000", $json["mem"]);	//E.g: "mem":"7.2Gi/30Gi"
		//$szMem = str_replace("Mi","000", $szMem);	//If given in M, also change..
		//$cRes = explode("/", $json["mem"]);
		$nFree = sizeToKB($cRes[0]);
		$nTotal = sizeToKB($cRes[1]);
		$nUsed = $nTotal - $nFree;
		$nPercentUsed = round(($nUsed / $nTotal) * 100);
		$cMax = array();
		$cMax["max"] = $nPercentUsed;
		$szServerStatus .= getDotByInterval($cMax, "max", 80, 95, 
					"$nPercentUsed% of memory used ($szUsage). This is normal",
					"$nPercentUsed% of memory used ($szUsage). This should be monitored",
					"$nPercentUsed% of memory used ($szUsage). Consider relieving tasks or upgrading. Used $nUsed of $nTotal.");

	}
	else
		$bOldScript = 1;


	if (isset($json["usr"]))
	{
		$szServerStatus .= getDotByInterval($json, "usr", 1, 3, 
					$json["usr"]." user is active on this computer",
					$json["usr"]." users are active on this computer",
					$json["usr"]." users are active on this computer. Consider choosing one less congested.");

		//$nActiveUsers = isset($json["usr"])?$json["usr"]:"?";
		//$szServerStatus .= "&nbsp;$nActiveUsers";
	}
	else
		$bOldScript = 1;

	if ($bOldScript)
		$szServerStatus .= '<span title="This computer should be upgraded with latest TaraSec software">'.getDot(false).'</span>';			//<img src="img/_dot.png">

	//if ($nId)
	print '<a href="index.php?f=unitsMore&id='.$nId.'">'.$szServerStatus.'</a>';
	//else
	//	print $szServerStatus;

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
		print "<h2>Involved sites:</h2><table>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			if (!$nCount) 
			{
				print "<b>NOTE</b> ! If you have problems opening these, then check that you VPN setup, Allowed IPs contain 100.68.0.0/16 (add if not)<br>";

				print "<tr><td>Site</td><td>IP</td><td>Status *)</td><td>Gatekeeper</td><td>Sample bank</td><td>Honey</td></tr>";
				print "<tr><td>Me (".$setupRow["name"].")</td><td>&nbsp;</td><td>";
				
				printServerStatus($setupRow["seconds_since"], $setupRow["status"], 0);

				print "</td><td>&nbsp;</td><td><a href=\"../samplebank/index.php\">[go to]</a></td><td><a href=\"../honeypot/index.php\">[go to]</a></td></tr>";
			}

			$szGatekeeper = "http://".$row["ip"]."/gatekeeper/index.php";
			$szSamplebank = "http://".$row["ip"]."/samplebank/index.php";
			$szHoneypot =  "http://".$row["ip"]."/honeypot/index.php";
			print "<tr><td>".$row["name"]."</td><td>".$row["ip"]."</td><td>";
			
			printServerStatus($row["seconds_since"], $row["status"], $row["routerId"]);
			print "</td><td><a href=\"".$szGatekeeper."\">[go to]</td><td><a href=\"".$szSamplebank."\">[go to]</h></td><td><a href=\"".$szHoneypot."\">[go to]</h></td></tr>";
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
