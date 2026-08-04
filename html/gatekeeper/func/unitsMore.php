<?php
//unitsMore.php
    print "More info about node in network.";


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
	/*if (!isAdmin())
	{
		print "You have to login as admin to view this info.";
		return;
	}*/

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

		$status = $row["status"];
		//$status = '{"ld":"0.00 0.00 0.00","knl":"1","df":"19G 9.8G 8.6G","updates":"3;0","boot":1000000,"cron":1,"mem":"552Mi/1.9Gi","sqlThrds":"3","nett":0,"dmesg":1,"msg":null,"lnk":1,"usr":0,"rsyslog":"log:1,byte:360,log:10,burst:20,prefix:TARASEC_tomato,rsyslog:active,setup:@100.68.181.35","trfc":58,"bootReq":0,"ip":0,"lstUp":885}';

		$status = json_decode($status, true);

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

		//*********** rsyslog ***************** */
		$szRsyslog = $status["rsyslog"];
		$data = [];
		print "<tr><td>rsyslog</td><td><table>";

		foreach (explode(',', $szRsyslog) as $item) {
    		[$key, $value] = explode(':', $item, 2); // limit to 2 in case value contains ':'
    		$data[$key] = $value;
			print '<tr><td>'.$key.'</td><td>'.$value.'</td></tr>';
		}
		print "</table></td></tr>";

		unset($status["rsyslog"]);

		//********** unattended updates ************/
		$szUpdates = $status["updates"];
		$cUpdates = explode(";", $szUpdates);
		$szSecUpdates = ($cUpdates[1]? '<font color="red">'.$cUpdates[1].' security updates</font>':'no security update');
		print "<tr><td>Unattended updates</td><td>$cUpdates[0] regular updates and $szSecUpdates are waiting. ";
		print ($status["bootReq"]?"<font color=\"red\">System requires a boot after updates</font>. ":"");
		print "Last update was ".$status["lstUp"]." seconds ago.";
		unset($status["updates"]);
		unset($status["bootReq"]);
		unset($status["lstUp"]);

		//*********** Services not running ******************/
		print "<tr><td>Services</td><td>";
		if (!isset($status["srvcNtOk"]))
			print "Please upgrade to newest version of tarasec";
		else
		{
			$szServices = $status["srvcNtOk"];
			if (strlen($szServices))
				print 'Services that are not running: <font color="red">'.$szServices.'</font>';
			else
				print "All listed services are running.";

			print "<br>sudo nano tarasec.conf and list additional services to check: SERVICES=list,of,services";
		}
		print "</td><tr>";


		unset($status["srvcNtOk"]);
		

		//************ remaining (unhandled) */

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

		//if (isset($row["len"]) && $row["len"]+0 > 200)	//NOTE! Field is being changed to text so this test will soon be obsolete
		//	print '<tr><td colspan="2"><font color="red">'.$row["len"].' characters of 255 available used for status! Consider switch to text field.</font></td></tr>';

		print "<table>";

	}
}
?>