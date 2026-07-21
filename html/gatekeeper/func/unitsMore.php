<?php
//unitsMore.php
    print "More info about router in network.";


function getBitStatus($szStatus)
{
	if (!strcmp($szStatus, "1" ))
	//if (!$szStatus == 1 )
		return "[running]";
	else 
		return '<font color="red">[stopped]</font>';
}

function checkPrint(&$status, $szFld, $szLabel)
{
	if (!array_key_exists($szFld, $status))
		return;

	print '<tr><td>'.$szLabel.'</td><td>'.$status[$szFld].'</td></tr>';
	unset($status[$szFld]);
}


function unitsMore()
{
	if (!isAdmin())
		return;

	$conn = getConnection();
	
	if (!isset($_GET["id"]) || !($_GET["id"]+0))
	{
		require_once("func/units.php");		//To get getNetworkStatusThisComputer()
		$row = getNetworkStatusThisComputer($conn);
	}
	else
	{
		$sql = "select routerId, name, partnerStatusReceived as time, TIMESTAMPDIFF(SECOND, partnerStatusReceived, NOW()) AS seconds_since, inet_ntoa(ip) as ip, status from partnerRouter r join partner p on p.partnerId = r.partnerId where routerId = ?";
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("i", $_GET["id"]);
		$stmt->execute();
		$result = $stmt->get_result(); // get the mysqli result
		if ($result)
			$row = $result->fetch_assoc();
		else
			$row = 0;
	}

	if ($row) 
	{
		print "<table>";

		$status = json_decode($row["status"], true);

		print '<tr><td>Name</td><td>'.$row["name"].'</td></tr>';

		$nSecondsSince = $row["seconds_since"]+0;
		print '<tr><td>Reported</td><td>'.($nSecondsSince > 65?'<font color="red">':'').$nSecondsSince.($nSecondsSince > 65?'</font>':'').'</td></tr>';

	//	print '<tr><td>Status</td><td>'.$row["status"].'</td></tr>';
		print '<tr><td>Kernel</td><td>'.getBitStatus($status["knl"]).'</td></tr>';
		unset($status["knl"]);

		print '<tr><td>Taralink</td><td>'.getBitStatus($status["lnk"]).'</td></tr>';
		unset($status["lnk"]);

		print '<tr><td>Cron task</td><td>'.getBitStatus($status["cron"]).'</td></tr>';
		unset($status["cron"]);

		checkPrint($status, "dmesg", "Seconds since dmesg");
		checkPrint($status, "ld", "Load avg");

		print '<tr><td>Memory (used/total)</td><td>'.$status["mem"].'</td></tr>';
		unset($status["mem"]);

		print '<tr><td>Disk free (tot/used/free)</td><td>'.$status["df"].'</td></tr>';
		unset($status["df"]);

		checkPrint($status, "trfc", "Seconds since traffic");
		checkPrint($status, "usr", "Active users");
		checkPrint($status, "msg", "Msg (??)");

		print '<tr><td>Seconds since boot<br>(Not working)</td><td>'.$status["boot"].'</td></tr>';
		unset($status["boot"]);

		print '<tr><td>Internal IP</td><td>'.$status["ip"].'</td></tr>';
		unset($status["ip"]);
		print '<tr><td>Nettmask</td><td>'.$status["nett"].'</td></tr>';
		unset($status["nett"]);

		print '<tr><td>SQL connections</td>';
		if (isset($status["sqlThrds"]))
		{
			print '<td>'.$status["sqlThrds"].'</td>';
			unset($status["sqlThrds"]);
		}
		else
			print '<td>Not set (old version)</td>';

		print '</tr>';

		if (count($status))
			print '<tr><td>Remaining</td><td>'.json_encode($status).'</td></tr>';

	/*	print '<td>'.sjekk($status["lnk"]).'</td>';
		$szLoad = $status["ld"];
		print '<td>'.$szLoad.'</td>';
		$szMem = $status["mem"];
		print '<td>'.$szMem.'</td>';
		$szDisk = $status["df"];
		print '<td>'.$szDisk.'</td>';
		print '<td><a href="index.php?f=unitsMore&id='.$row["routerId"].'">[More info]</a></td></tr>'; 
	*/

		if (isset($_GET["json"]))
			print '<tr><td colspan="2">'.$row["status"].'</td></tr>';
		else
			print '<tr><td colspan="2"><a href="index.php?f=unitsMore&id='.$_GET["id"].'&json">See full json</a></td></tr>';

		if ($row["len"]+0 > 200)
			print '<tr><td colspan="2"><font color="red">'.$row["len"].' characters of 255 available used for status! Consider switch to text field.</font></td></tr>';

		print "<table>";

	}
}
?>