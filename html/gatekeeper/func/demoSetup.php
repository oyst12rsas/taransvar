<?php

function demoSetup()
{
	if (isset($_GET["conf"]) && $_GET["conf"]+0 == 1)
	{
		switch($demoRow["iAm"])
		{
		
		case "targetHost":
			$conn = getConnection();
			$sql = "update setup set  
		        	statusIntervalSec = 60, showStatus = b'1', showPreRoutePartner = b'1',showPreRouteNonPartner = b'0',
        			showForwardPartner = b'1', showForwardNonPartner = b'0', showUrgentPtrUsage = b'0',
        			showOwnerless = b'0', showOther = b'0', showNew1 = b'0', showNew2 = b'0',
        			doTagging = b'1', doInspection = b'0', doBlocking = b'0', handled = null";
			$result = $conn->query($sql);
			$conn->close();
			break;
		case "botHost":
			//Only difference from "targetHost" is that we don't want the preRoute messages on the "bothost".
			$conn = getConnection();
			$sql = "update setup set  
		        	statusIntervalSec = 60, showStatus = b'1', showPreRoutePartner = b'0',showPreRouteNonPartner = b'0',
        			showForwardPartner = b'1', showForwardNonPartner = b'0', showUrgentPtrUsage = b'0',
        			showOwnerless = b'0', showOther = b'0', showNew1 = b'0', showNew2 = b'0',
        			doTagging = b'1', doInspection = b'0', doBlocking = b'0', handled = null";
			$result = $conn->query($sql);
			$conn->close();
			break;
		case "bot":
			print "<h1>No setup to be done on this computer with the current demo setup..</h1>";
			break;
		}
		
		print "<h2>Setup is changed. Now you can go back to the bot (computer/phone) and continue simulating the attack.</h2>";
	}

?>
	Not yet finalized...<br><br>
	You want to change the setup...<br><br>
			But while doing so, you might like to see how the abmonitor (a user space program) sends updated setup information to absecurity (a linux kernel module).<br>On the "<?php print $demoRow["iAm"]; ?>" computer, there should be two terminal windows running (Ctrl-Alt-T to open new window):
			<li>Check first om you have one terminal window where the last line is "Waiting for message from kernel". If so, that's the abmonitor. If you can't find it then check the "Akili Bomba Gatekeeper" document on how to start is.</li>
			<li>The other window should show lines like this:<br>
[124187.261689] Absecurity: FW to partner: 192.168.50.100->192.168.39.195: Tag: (00000000)<br>
[124187.289499] Absecurity: FW from partner: 192.168.39.195->192.168.50.100: Tag: (00000000)<br></li>
		</ul>
		Now, you can put up the two windows beside each others and when you click the links to change the setting, the traffic will show in the absecurity window and the new setup will appear in both.<br><br>
		<a href="http://<?php print $demoRow["targetHost"]; ?>/index.php?f=demoSetup&conf=1">Click here to do the setup changes</a><br>
			
<?php
			
}

?>

