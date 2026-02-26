<?php


function saveDemoInfo()
{
	global $demoRow;
	//Check first if there's a record already..
	$conn = getConnection();
	if (!$demoRow)
	{
		$szSQL = "insert into demo (userId, ipTargetHost, ipBotHost, ipBot,status, activeDemo) values (?, inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), 'to be replaced', b'1')";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $_SESSION["userid"]); 
                $stmt->execute();
	}

	//$szSQL = "update demo set ipTargetHost = inet_aton(?), ipBotHost = inet_aton(?), ipBot = inet_aton(?), iAm = ?";
	$szSQL = "update demo set ipTargetHost = inet_aton(?), ipBotHost = inet_aton(?), ipBot = inet_aton(?), iAm = ? where userId = ?";
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("ssssi", $_GET["targethostip"], $_GET["bothostip"], $_GET["botip"], $_GET["iam"], $_SESSION["userid"]);  
	//$stmt->bind_param("s", $_GET["iam"] );  
        $stmt->execute();
	//ipAdditionalBot1, ipAdditionalBot2, ipAdditionalBot3, 
	$cDemoPartners = array();
	if ($_GET["iam"] <> "botHost")  	$cDemoPartners[] = $_GET["bothostip"];
	if ($_GET["iam"] <> "targetHost") 	$cDemoPartners[] = $_GET["targethostip"];
	if ($_GET["iam"] <> "bot") 		$cDemoPartners[] = $_GET["botip"];
		
	//Send message to the others involved...
	foreach ($cDemoPartners as $szIp)
	{
		$szUrl = "http://".$szIp."/demo.php?bothostip=".$_GET["bothostip"]."&targethostip=".$_GET["targethostip"]."&botip=".$_GET["botip"];
		print "$szUrl<br>";
		//wget($szUrl);
		file_get_contents($szUrl);
	}
}




function addDemo()
{
	//$demoRow = getDemo();	Is being read when page opens....
	global $demoRow;
	
	if (!loggedIn())
		return;
	
	if (isset($_GET["submit"]))
	{
		saveDemoInfo();
		return;		
	}

	if ($demoRow && $demoRow["activeDemo"]+0 == 0)
	{
		print "There's registered demo setup but it's inactive... 
		<ul>
			<li><a href=\"index.php?f=activateDemo\">Activate it...</a></li>
			<li><a href=\"index.php?f=addDemo\">Change information...</a></li>
		</ul>";
	}

	printDemoForm(0, "addDemo");
}

?>
