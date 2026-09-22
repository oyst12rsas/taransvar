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


function addUnitIssue(&$issues, $severity, $label, $message)
{
    $issues[] = array("severity" => $severity, "label" => $label, "message" => $message);
}

function collectUnitIssues($status, $secondsSince)
{
    $issues = array();

    if ($secondsSince > 200)
        addUnitIssue($issues, "red", "Status reporting", "No status received for ".$secondsSince." seconds.");
    elseif ($secondsSince > 130)
        addUnitIssue($issues, "yellow", "Status reporting", "Last status was ".$secondsSince." seconds ago.");

    foreach (array(
        "knl" => array("Tarakernel", "tarakernel is not running"),
        "lnk" => array("Taralink", "taralink is not running"),
        "cron" => array("Cron task", "crontasks.pl is not running"),
        "sshListen" => array("Administrative SSH", "Administrative SSH is not listening"),
        "dmesgOk" => array("Kernel log collection", "dmesg collection worker is not running"),
        "trfcOk" => array("Traffic reporting", "Traffic reporting pipeline is not running")
    ) as $field => $info) {
        if (array_key_exists($field, $status) && (string)$status[$field] !== "1")
            addUnitIssue($issues, "red", $info[0], $info[1].".");
    }

    if (isset($status["sqlThrds"])) {
        $v = $status["sqlThrds"] + 0;
        if ($v >= 25) addUnitIssue($issues, "red", "SQL threads", $v." SQL threads are busy.");
        elseif ($v > 12) addUnitIssue($issues, "yellow", "SQL threads", $v." SQL threads are busy.");
    }
    if (!empty($status["bootReq"]))
        addUnitIssue($issues, "red", "Reboot required", "The system requires a reboot after upgrading.");

    if (isset($status["updates"])) {
        $u = explode(";", $status["updates"]);
        $total = intval($u[0] ?? 0); $security = intval($u[1] ?? 0);
        if ($security >= 1) addUnitIssue($issues, "red", "Security updates", $security." security update(s) are waiting.");
        elseif ($total >= 30) addUnitIssue($issues, "red", "Updates", $total." updates are waiting.");
        elseif ($total > 15) addUnitIssue($issues, "yellow", "Updates", $total." updates are waiting.");
    }
    if (isset($status["lstUp"])) {
        $v = $status["lstUp"] + 0;
        if ($v >= 60*60*24*30) addUnitIssue($issues, "red", "System update", "Last update was ".round($v/86400)." days ago.");
        elseif ($v > 60*60*24*7) addUnitIssue($issues, "yellow", "System update", "Last update was ".round($v/86400)." days ago.");
    }
    if (isset($status["ld"])) {
        $loads = array_map("floatval", explode(" ", trim($status["ld"])));
        $v = max(array_slice($loads, 0, 2));
        if ($v >= 2) addUnitIssue($issues, "red", "Server load", "Load is ".$v.".");
        elseif ($v > .7) addUnitIssue($issues, "yellow", "Server load", "Load is ".$v.".");
    }
    foreach (array("cpu" => array(70,90,"CPU usage","%"), "cpuWait" => array(10,25,"CPU I/O wait","%"), "dbScan" => array(100,1000,"DB scan rate"," rows/second")) as $field => $cfg) {
        if (!isset($status[$field])) continue;
        $v = $status[$field] + 0;
        if ($v >= $cfg[1]) addUnitIssue($issues, "red", $cfg[2], $v.$cfg[3].".");
        elseif ($v > $cfg[0]) addUnitIssue($issues, "yellow", $cfg[2], $v.$cfg[3].".");
    }
    if (isset($status["srvcNtOk"])) {
        $services = array_values(array_filter(array_map("trim", explode(",", $status["srvcNtOk"])), function($s) { return $s !== "" && $s !== "tarasec-gateway.service"; }));
        if (count($services)) addUnitIssue($issues, "red", "Services", "Not running: ".implode(", ", $services).".");
    }
    if (isset($status["usr"])) {
        $v=$status["usr"]+0;
        if ($v >= 3) addUnitIssue($issues, "red", "Active users", $v." users are active.");
        elseif ($v > 1) addUnitIssue($issues, "yellow", "Active users", $v." users are active.");
    }

    usort($issues, function($a,$b) { return ($a["severity"] === $b["severity"]) ? 0 : ($a["severity"] === "red" ? -1 : 1); });
    return $issues;
}

function printUnitIssues($status, $secondsSince)
{
    $issues = collectUnitIssues($status, $secondsSince);
    print '<h2>Issues</h2>';
    if (!count($issues)) {
        print '<p><b><font color="green">No issues detected.</font></b></p>';
        return;
    }
    print '<table>';
    foreach ($issues as $issue) {
        $color = $issue["severity"] === "red" ? "red" : "#9a6b00";
        print '<tr><td><span style="color:'.$color.'">&#9679;</span> '.htmlspecialchars($issue["label"]).'</td><td>'.htmlspecialchars($issue["message"]).'</td></tr>';
    }
    print '</table>';
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

		// Mobile-first summary: tapping the status dots lands here and shows only non-green checks first.
		printUnitIssues($status, $row["seconds_since"]+0);
		print "<h2>All status details</h2>";

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