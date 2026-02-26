<?php


function printTechnicalStatus()
{
	//First display new warnings if any...
	$conn = getConnection();
	$sql = "select warningId, warning, lastWarned from warning where handled is null";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Unhandled warnings:</h2><table><tr><td>Discovered</td><td>Warning</td><td>&nbsp;</td></tr>";
		while($row = $result->fetch_assoc()) 
		{
			print '<tr><td>'.$row["lastWarned"].'</td><td>'.$row["warning"].'</td><td><a href="index.php?f=rmWarn&id='.$row["warningId"].'">[remove]</a></td></tr>';
		}
		print "</table>";
	}

	global $demoRow;
	//misc/crontasks.pl should run as a cron task every minute and update these fields if there's an ongoing demo...
	$szStatusFld = $demoRow["iAm"]."Status";
	print "Status field: $szStatusFld<br>"; 
	if (isset($demoRow[$szStatusFld]))
	{
		checkPerlScriptStatus("Target host", "targetHost");
		checkPerlScriptStatus("Bot host", "botHost");
		checkPerlScriptStatus("Bot", "bot");
		
		print "<p>NOTE! This information should display equally on all computers involved in the demo<br>(updating may not work yet, though)</p>";
	}
	else
		print '<font color="red">Demo status fields are not properly updated. This is most likely because misc/crontasks.pl is not set up as a cron task. See the Gatekeeper manual or contact support to fix this.</font>';
}

function getBotStatus()
{
	global $demoRow;
	?><h1>This is supposed to run on a computer connected to wifi router, switch or other connected to a \"bot-host\"</h1>
	To check the system, you should do as follows (after it's properly set up):
	<ul>
	<li>Open the dashboard on the "bot-host" (router where you should be connected). Normally <a href="http://<?php print $demoRow["bot"]; ?>">this will be the link</a>. The background should be from server room.</li>
	<li>Open the dashboard on the "target-host" That should be <a href="http://<?php print $demoRow["targetHost"]; ?>">like this</a>. The background should be gold coins, indicating that this is where we're planning to break in.</li>
	<li>Now, as you click in the menu there, check on the "target-host" and "bot-host" that you should be able to see the packages being sent in the terminal window. If you don't or don't know how, then we suggest you read the document with the title ""  If there's too much traffic, you can set them up to show only forwarded traffic in the network. You can do that with these links:<br>
		<a href="http://<?php print $demoRow["targetHost"]; ?>/f=demoSetup">Set up the "target-host" here</a><br> 
		<a href="http://<?php print $demoRow["botHost"]; ?>/f=demoSetup">Set up the "bot-host" here</a><br> 
	</li>
	</ul>

<p>
Once it's properly set up and you see that your traffic gets routed via the "bot-host" (with the server background) to the "target-host" (with the gold coin background), you can simulate an attack from your computer/phone on the "target-host". 
</p>
<p>
To simulate an attack on "target-host": <a href="http://<?php print $demoRow["targetHost"]; ?>/honey.php">Click this link to simulate an attack</a> (or as here visiting a very simple "honeypot"-script) 
</p>
<p>
Once you've clicked the link, you should see a line if you go to "Infections" in the meny, both on the "target-host"(with gold) and "bot-host" (server background). If you now study the terminal windows, the messages should now reflect that traffic is tagged. 
</p>
<p>
Now let's say that a server in the "target-host"-network comes under a D-DOS or bruteforce attack. They can then request help from all partners in our network to stop traffic from their units that are tagged (as you just got by clicking the honey.php script). Do do this, go to the "target-host", open a terminal window, change directory (cd) to programming/misc and run sudo perl checkload.pl. The logic here is that you can have a script running that automatically requests assistance from all partners if e.g a web server gets overloaded. Then we don't want traffic from botnets, either because we're under attack or because we need to prioritize presumed clean traffic. 
</p>

<?php
	//asdf
	printTechnicalStatus();
	
}


function getTargetHostStatus()
{
	print "<h1>Not yet learned to assemble target host status</h1>";
	print "This is the computer that is target of simulated attack...";
	//Check attack status... 
	printTechnicalStatus();
}


function getBotHostStatus()
{
	global $demoRow;
	//print "<h1>Not yet learned to assemble bot host status</h1>";
	print "This is the computer where the wifi router is connected...<br><br>";
	
	//****************************************** START OF MAIN TABLE *************************************
	print "<table><tr><td>";	//Main table with list of active units to the left and status of each computer to the right....
	//Check connected units...
	$szSQL = "select unitId, description, hostname, inet_ntoa(ipAddress) as ipAddress, lastSeen, unix_timestamp(now()) - unix_timestamp(lastSeen) as SecondsAgo from unit where lastSeen > DATE_SUB(NOW(), INTERVAL 24 HOUR) order by lastSeen desc limit 5";
	
	$conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		$nHours = 0;
		$bRecentDataFound = 0;
		// output data of each row  
		print "<h2>Last seen connected units:</h2><table>";
		while($row = $result->fetch_assoc()) 
		{
			$szDescription = "";
			if ($row["description"] && strlen($row["description"]) > 0)
				$szDescription = $row["description"];
			else
				if ($row["hostname"] && strlen($row["hostname"]) > 0)
					$szDescription = $row["hostname"];
					
			if (!strlen($szDescription))
				$szDescription = $row["ipAddress"];
			else
				$szDescription .= " (".$row["ipAddress"].")";
	
			if ($row["SecondsAgo"] < 120)
			{
				$szTimeAgo = $row["SecondsAgo"]." seconds ago.";
				$bRecentDataFound = 1;	//If not recent data found: Explain what may be wrong...
			}
			else
				if ($row["SecondsAgo"] < 60*60)	//Less than an hour...
				{
					$nMinutes = floor($row["SecondsAgo"]/60);
					$szTimeAgo = $nMinutes." minutes ago.";
				}
				else
				{
					$nHours = floor($row["SecondsAgo"]/(60*60));
					$szTimeAgo = $nHours." hours ago.";
				}
	    		print "<tr><td>".$szDescription. "</td><td>".$row["SecondsAgo"]. " seconds ago</td>";
	    		print "</tr>";
	  	}
	  	print "</table>";

		if (!$bRecentDataFound)
		{
			print "No recent traffic from connected units. You should first check if the unit is connected. Check your IP address there. It should normally be something like 192.168.50.100. Then try to open 192.168.50.1 in your browser. If that works, then the most likely reason is import of data on the \"bot-host\". To check, you should first open a terminal (Ctrl-Alt-T) and type:<br>&nbsp;&nbsp;&nbsp; \"sudo crontab -u root -e\"<br>";   
			print "First you need to choose editor. \"nano\" is probably the best.. Then there should be at least two lines there:<br><br>  
* * * * * sudo perl /home/<your user name>/programming/misc/crontasks.pl<br>
Those lines make sure new connected units are being important and also their usage of various ports (which is how we can track what computers are \"attacking\" other computers on Internet.<br>"; 
			
		}
			$nHours = 0;

	}
	else
		print "<h1>**** WARNING! - No active connected units found..</h1><font color=\"red\" You should open the bot computer and visit localhost there. If you have a connected phone, you should check if your IP address starts with 192.168.50 and if so visit http://192.168.50.1, which should lead to this page.</font>";

	//Check tagging of traffic
	
	//Check recent infected units in network
	$szSQL = "select infectionId, inet_ntoa(ip) as ip, inserted, I.unitId, unix_timestamp(now()) - unix_timestamp(inserted) as secondsSince, active, description, hostname from internalInfections I left outer join unit U on U.unitId = I.unitId having active = b'1' or secondsSince < 24*60*60";
	
	$conn = getConnection();
	$result = $conn->query($szSQL);
	$nFound = 0;

	if ($result->num_rows > 0) 
	{

		while($row = $result->fetch_assoc()) 
		{
			if (!$nFound)
				print "<h2>Recent or still active infections in our net:</h2><table><tr><td>IP</td><td>Inserted</td></tr>";
				
			$nFound = $nFound +1;	
			$szDesc = "";
			if ($row["description"] && strlen($row["description"]))
				$szDesc = $row["description"];
			else
				if ($row["hostname"] && strlen($row["hostname"]))
					$szDesc = $row["hostname"];
			if (!strlen($szDesc))
				$szDesc = $row["ip"];
			else
				$szDesc .= " (".$row["ip"].")";
					
			print "<tr><td>".$row["ip"]."</td><td>".$row["inserted"]."</td><td>".$szDesc."</td>";
			
			if ($row["secondsSince"] > 5*60*60)	//More than 5 hours since infection reported...
				$szRemove = '<a href="index.php?f=delInfection&action=deactivate&id='.$row["infectionId"].'">It\'s old. Remove it</a>';
			else
				$szRemove = '';
			print '<td>'.$szRemove.'</td>';
			
			print "</tr>";
		}
		if ($nFound)
			print "</table>";
	}

	if (!$nFound)
		print "<b>No recent or still active infections found in our net..<br>To register infection, use the browser on the \"bot\" (computer/phone connected to the wifi router) and click link from the demo page.</b>";

	//****************************************** MIDDLE OF MAIN TABLE *************************************
	print "</td><td>";	//End of left column (connected units and infections) and start of right one

	//Check Blocking of traffic based on partners asking for assistance
	print "Status of the other computers goes here...";
	
	print "target-host: <a href=\"http://".$demoRow["targetHost"]."\">".$demoRow["targetHost"]."</a><br><br>";
	print "Best way to find out what's going on between this computer and the target host is by checking the dmesg log. Open a terminal window with Ctrl-Alt-T and type:<br><br>sudo dmesg -w | grep -v \"^[[:space:]]*$\"<br><br>";
 	print "The last part is to avoid annoying blank lines.<br> Then go to the connected computer and open localhost there, click \"Demo\" and do as described. If it says there's no ongoing demo, then register one with the same IP addresses as here:<br>Target host: ".$demoRow["targetHost"]."<br>Bot host: ".$demoRow["botHost"]."<br>Bot: ".$demoRow["bot"]; 
	print "<br><br>You should also be able to find the bot IP address (".$demoRow["bot"].") in the tables to the left.<br>"; 
	
/*
alter table setup add networkStatusChecked timestamp null;
alter table setup add networkStatus varchar[255] null;
alter table demo add targetHostChecked timestamp null;
alter table demo add targetHostStatus varchar[255] null;
alter table demo add botHostChecked timestamp null;
alter table demo add botHostStatus varchar[255] null;
alter table demo add botChecked timestamp null;
alter table demo add botStatus varchar[255] null;
*/

	printTechnicalStatus();
	//****************************************** END OF MAIN TABLE *************************************
	print "</td></tr></table>"; //End of main table (with list of units and infections on left side)
}


function demo()
{
	global $demoRow;
	//First check status

/*ipTargetHost int unsigned not null,
ipBotHost int unsigned not null,
ipBot int unsigned null, 
ipAdditionalBot1 int unsigned null, 
ipAdditionalBot2 int unsigned null, 
ipAdditionalBot3 int unsigned null, 
iAm enum('targetHost','botHost','bot') null,
status text, 
error bit(1) not null default b'0',
warning bit(1) not null default b'0',
activeDemo bit(1) not null default b'0',
statusInstalled bit(1) not null default b'0',
statusConnected bit(1) not null default b'0',
statusTaggingOk bit(1) not null default b'0',
statusTaggingReceivedOk bit(1) not null default b'0',
statusInfectedOk bit(1) not null default b'0',
statusRequestAssistanceOk bit(1) not null default b'0'
*/
	if ($demoRow && $demoRow["activeDemo"]+0)
	{
		//echo 'Not yet ready.. <a href="index.php?f=editDemo">Edit demo info</a>';
		//echo "I am: ".$demoRow["iAm"]."<br>";

		$conn = getConnection();

		if ($demoRow["iAm"] <> "targetHost" && $demoRow["ipTargetHost"] +0 > 0)
			print '<a href="http://'.$demoRow["targetHost"].'/index.php?f=demo">Open target host page</a>&nbsp;&nbsp;&nbsp;&nbsp;';
		if ($demoRow["iAm"] <> "botHost" && $demoRow["ipBotHost"] +0 > 0)
			print '<a href="http://'.$demoRow["botHost"].'/index.php?f=demo">Open bot host page</a>&nbsp;&nbsp;&nbsp;&nbsp;';
		if ($demoRow["iAm"] == "botHost" && $demoRow["ipBot"] +0 > 0)
			print '<a href="http://'.$demoRow["bot"].'/index.php?f=demo">Open bot page (if available from here)</a>&nbsp;&nbsp;&nbsp;&nbsp;';

		$szEditDemoUrl = '<a href="http://localhost?f=editDemo">Edit demo setup</a>'; 
		print $szEditDemoUrl;

		//Check that targetHost and botHost are on same net
		$nTargetNet = ($demoRow["ipTargetHost"] & hexdec("ffffff00"));
		$nBotNet = ($demoRow["ipBotHost"] & hexdec("ffffff00"));
		if ($nTargetNet <> $nBotNet)
			print '<br><font color="red">Target host and bot host are not on same net! (and probably can\'t communicate: '.$demoRow["targetHost"].' & '.$demoRow["botHost"].')</font>. '.$szEditDemoUrl.'<br>';


		checkCronTaskRunning($demoRow, "TargetHost");
			
		//Assemble the current demo status depending on what unit...:
		switch ($demoRow["iAm"])
		{
			case "targetHost":
				getTargetHostStatus();
				break;
			case "botHost":
				getBotHostStatus();
				//Uncomment to test this on the development computer...
				//print "--------------------------- BOT -----------------------<br>";
				//getBotStatus();
				break;
			case "bot":
				break;
		}
		
	} 
	else 
	{
	  	echo "There's currenly no registered ongoing demo setup..<br><br><a href=\"index.php?f=addDemo\">Set up one</a>";
	}

}

?>
