<?php

//demoNewFunc.php

function isGateway($szIP)
{
	if (str_starts_with($szIP, "10.100.0."))
		return false; //This is a phone or similar unit. 

	if (!strcmp("127.0.0.1", $szIP))
		return false;

	//print "$szIP appears not to be a phone... So assuming it's a TaraSec gateway.<br>"; 
	return true;
}

function printTagStatusExplanation($data, $szLanIp)
{

?>
<style>
.leftCentered {
    display: inline-block;
    text-align: left;
}
</style>
<div style="text-align:center;">
    <div class="leftCentered">
This is what I know about your status:<br>
<ul>
<?php

	if ($data["infectionSeverity"]+0 < 0)
		print "<li>There's no infection registered on you.</li>";
	else
		print "<li>You are registered to be infected on this server. Severity: ".$data["infectionSeverity"]."</li>";

	if ($data["hackReportSeverity"]+0 <= 0)
		print "<li>There are no incidents registerede on your unit.</li>";
	else
		print "<li>Incident has been registered on you. Severity: ".$data["hackReportSeverity"]."</li>";

	if ($data["trafficSeverity"]+0 <= 0)
		print "<li>Incoming data packets are not tagged.</li>";
	else
		print "<li>Incident has been registered on you. Severity: ".$data["trafficSeverity"]."</li>";

	if ($data["severity"]+0 <= 0)
	{
		print "<li>The overall assesment is that your data is clean.";

		if ($data["infectionSeverity"]+0 > 0)
		{
			print 'To clear the infection, you can disable it <a href="index.php?f=infections">here</a>.';
		}

		print "</li></ul>";

		$szGwInfections = "http://".$data["senderIp"]."/gatekeeper/index.php?f=infections";

		if ($data["trafficSeverity"]+0 > 0)
			print "Incoming data from your gadget is tagged. To clear it, go to <a href=\"$szGwInfections\">your gateway</a> and disable the infection there. <br>";
		else
		{
			$szGwLogin = "http://".$data["senderIp"]."/gatekeeper/index.php?f=login";
?>		

Your traffic is not tagged. To get "tagged" you should first check if there's already registered an infection which has been deactivated. If so, just can just re-activate it. Check at <a href="<?php print $szGwInfections; ?>">your gateway's infections page</a>. 
If you're not yet infected, you can go to <a href="<?php print $szGwLogin; ?>">your gateway</a> and "fail" to log in.<br>
<br>
<div class="expandable">	
<div class="short-text">
    To get a more comprehensive explanation on "infections" and how to get tagged: 
    <a href="#" onclick="toggleText(this); return false;">Click here</a>
</div>

<div class="long-text" style="display:none;">
<p>About "tagging":</p>
<p>The key component of TaraSec network is that data traffic between partnering routers coming from units known to probably be infected is being "tagged". All partnering units report "suspicious activity" to a central server that assesses the threat based on collected data. What separates us from most threat analyses is that we collaborate with the sender. The reason why this is significant is that one IP address may hide thousands of computers. By collaborating with the sending ISP, we can identify the one unit that is affected without revealing any confidential information.</p>
<p>How to get "tagged":</p>
<p>Collaborating networks report suspicious traffic:
	<ul>
	<li>Traffic rejected by firewalls</li>
	<li>Suspicious activity in application. Like failure to log in.</li>
	<li>They may also forward traffic to "honeypots" that report traffic</li>
	</ul>
</p>
<p>The easiest way to get yourself tagged is by "failing" to log in to this "gatekeeper" site on your router. To fail, click "Setup Menu" in the menu, then type any user name like "hacker" and press submit. Then resubmit the same info 6 times. You will then get a warning, but you're already registered as potential hacker. Resubmitting can be done in different ways. On Android phone, you can pull the screen down to refresh/resubmit. On computers, there's icon to refresh.</p>
<p>NOTE! You should do this on your router's <a href="<?php print $szGwLogin; ?>">login page</a>.
<p>After failing to log in, you can go to the gateway's <a href="<?php print $szGwInfections; ?>">list of infected units</a> and should find your ip address (<?php print $szLanIp; ?>) listed there. </p>
<p><a href="#" onclick="toggleText(this); return false;">Click here to hide explanation</a></p>
</div>
</div>

<?php
		}
	}
	else
		print "<li>The overall assesment is that you're a security threat. Severity: ".$data["trafficSeverity"]."</li></ul>";

?>
	</div>
</div>
<?php
}

function demoNewFunc()
{
	//Also in caller... remove when merged...
	$szIP = getSenderIp();

	//Check if senders IP is a TaraSec gateway (no need to send this request to a phone...)
	if (isGateway($szIP))
	{
		$url = "http://$szIP/script/config_update.php";
		//print "$url<br>";
		$params = [];
		$params["f"] = "unitIp";
		$params["ip"] = $szIP; //Not necessary.. should be same as this server
		$params["port"] = $_SERVER['REMOTE_PORT'];

		$result = getUrlArray($url,$params);

		if ($result['success']) 
		{
    		//echo $result['output']."<br>";

			$data = json_decode($result['output'], true);

			if (isset($data["error"]))
			{
				//print "An error occurred..<br>";
				if (isset($data["found"]) && !strcmp($data["found"], "-1"))
				{
					if ($data["updated"]+0 == 1)
						print "<tr><td colspan=\"2\">Gateway doesn't have any data on port ".$params["port"]."<br>Waiting up to a minute might help.</td></tr>";
					else
						print "<tr><td colspan=\"2\">The server is having issues. Port assignment data is currently not updated. Please try again later or inform support team.</td></tr>";
				}
				else
					print "Unknown error... should be investigated..<br>";
			}
			else
			{
				$szLanIp = $data["ip"];
				print "<tr>";
				print "<td>LAN IP: </td><td>".$szLanIp."</td</tr>";
				print "";
				if ($data["sec"]+0 > 100)
					print "<tr><td>Seconds since seen:</td><td>It's ".$data["sec"]." seconds since registered data on gateway. Refreshing may help.</td</tr>";
				

				$szServerIP = $_SERVER['SERVER_ADDR'];

				//requre_once ".."
				$data = getTagData();
				$json = json_encode($data);

?>
<tr><td colspan="2">

<div class="expandable">	
<div class="short-text">
    To get an explanation about IP addresses involved..
    <a href="#" onclick="toggleText(this); return false;">Click here</a>
</div>

<div class="long-text" style="display:none;">
<p>To explain about IP addresses:</p>
<p>You connected to our VPN and received the IP address <?php print $szLanIp; ?> by our WireGuars server (maybe by scanning QR code). Then you visited this site (ip <?php print $szServerIP; ?>) and your traffic was routed through a gateway that assigned you the IP address <?php print $szIP; ?>. This is called Network Address Translation (NAT). In our system, gateways only have one IP address, so you get the same IP address as the gateway.</p>
<p>This works the same way with IPSs. You may check the IP address on your phone. But seen from Internet, you have an IP address belonging to your provider.</p>
<p>You can check your public IP address here: <a href="https://nodedata.io/whoami">nodedata.ip whoami</a></p>
	<br>
    <a href="#" onclick="toggleText(this); return false;">Click here to hide explanation</a>
</div>
</div>

</td></tr>

<tr><td colspan="2">
<?php
				printTagStatusExplanation($data, $szLanIp);

				if (isAdmin())
					print "<p><b>Info for admin:</b></p><p>Current status: <br>".str_replace(",","<br>",$json)."</p>";
			}

		} 
		else 
		{
    		print '<tr><td colspan="2">'.$result['error']."</td></tr>";

			if (json_last_error() !== JSON_ERROR_NONE) {
				print 'Server didn\'t return a valid reply. Aborting.</td></tr>';
				return;
			}
		}
	}
	else
	{
		print '<tr><td colspan="2">For a guided demo, go to one of our servers with cheese name in <a href="index.php?f=units">the involved sites list<a>. From there, click "Demo" in menu.</td></tr>';
	}

}
?>