<?php

require_once "radiuslib.php";


function fwMenu()
{
	if (!isSupervisor())
		return;
		
	print h1("Dashboard");

	listTemplates();
	//print "<br>This function is not yet finalzed";
	
}


function listTemplates()
{
	if (!isSuperUser())
		return;

	$szTxt = h1("Firewall template rules");
	$pDb = new CDb;
	$szIpTables = "/sbin/iptables";
	$szIpTablesFromWebFile = "temp/iptablesTemplates.txt";
	
	$cFlds = array();
	$szRows = "";
	$nFound = 0;
	$szRules = "";
	$szLineBreak = "\n"; //"<br>";
	
	$szToggle = request("toggle");
	
	if (strlen($szToggle) == 2)
	{
		$szService = request("svc");
		$szFldName = (!strcmp(substr($szToggle,0,1),"o")?"outwards":"incoming");
		$szFldName .= (substr($szToggle,1,1) == "I"?"Inside":"Outside");
		print "$szService $szFldName toggled....<br>";
		
		$szFlds = array(":template" => $szService);
		$pUpdate = new CDb;
		$pUpdate->execute("update fw_acceptTemplate set $szFldName = if($szFldName = b'0',b'1',b'0') where ruleTemplate = :template", $szFlds);
		
		$nSecondsUntilUpdate = getSecondsTillUpdate();
		print "<br>Firewall setup will be implemented in <span id=\"countdowntimer\">$nSecondsUntilUpdate</span> seconds.<br>";
		printCountDownJs($nSecondsUntilUpdate, "countdowntimer");
	}

	if (intval(request("togglelogging")) == 1)
	{
		$szLogRejected = request("log");
		print "Logging. Log = ".$szLogRejected. "<br>";
		$pUpdate = new CDb;
		$pUpdate->execute("update hotspotSetup set logRejected = if(logRejected = b'0',b'1',b'0')", $cFlds);
	}

	//NOTE!!!!This section being moved to perl....
	$szLAN = $szWAN = "";

	$pSetupDb = new CDb;
	$cSetup = $pSetupDb->fetchNext("select coalesce(WAN, externalNic) as WAN, coalesce(LAN, internalNic) as LAN, cast(logRejected AS UNSIGNED) as logRejected from hotspotSetup, setup", $cFlds);
	
	if (!$cSetup)
	{
		print "Error reading setup!";
		return;
	}
	
	$szLAN = $cSetup["LAN"];
	$szWAN = $cSetup["WAN"];
	
	if (!strlen(isset($szLAN)?$szLAN:"") || !strlen(isset($szWAN)?$szWAN:""))
		print '<font color="red">Network setup is not fully registered. Can\'t assemble the fw setup!</font>';
	
	$cServices = array("SSH" => array("tcp^22"), "Samba"=>array("tcp^139", "tcp^445", "udp^137", "udp^138"),"HTTP"=>array("tcp^80","tcp^443"));
	
	$pScan = new CDb;
	
	$cSetupFlds = array("outwardsInside", "incomingInside", "outwardsOutside", "incomingOutside");
	
	while ($cFetched = $pScan->fetchNext("select ruleTemplate, CAST(outwardsInside as UNSIGNED) as outwardsInside, CAST(incomingInside as UNSIGNED) as incomingInside, CAST(outwardsOutside as UNSIGNED) as outwardsOutside, CAST(incomingOutside as UNSIGNED) as incomingOutside from fw_acceptTemplate", $cFlds))
	{
		$nFound++;
		//$szRows .= tr(td($cFetched["ruleTemplate"]).td($cFetched["outwardsInside"]).td($cFetched["incomingInside"]).td($cFetched["outwardsOutside"]).td($cFetched["incomingOutside"]));
		$szService = $cFetched["ruleTemplate"];
		$szRow = td($szService);
		foreach ($cSetupFlds as $szFld)
		{
			$szKey = substr($szFld,0,1).substr($szFld,8,1);
			$szRow .= td(a(($cFetched[$szFld]?"Open":"Blocked"),"index.php?f=fw_db&toggle=$szKey&svc=".$szService));
		}
		$szRows .= tr($szRow);
		
		$szService = $cFetched["ruleTemplate"]; 
		
		foreach ($cFetched as $szKey => $szValue)
		{
		
			if (intval($szValue) && !is_int($szKey) && strcmp($szKey, "ruleTemplate"))
			{
			
				$szDirection = substr($szKey, 0, 8);	//incoming or outwards
				$szSide = substr($szKey, 8);			//Inside or Outside
				$szRules .= "#Field found: ".$szService.": ".$szDirection."/".$szSide."(".$szValue.")".$szLineBreak;
				
				$cSourceDestiny = ($szDirection == "incoming"?array(array("IN","d","i"),array("OUT","s","o")):array(array("OUT","d","o"), array("IN","s","i")));	//changed "s" to "d" for both "OUT"...
				$szDevice = (!strcmp($szSide, "Inside")? $szLAN : $szWAN);
				
				//Allow incoming SSH: sudo iptables -A INPUT -p tcp --dport 22 -j DROP
				
				$cThisService = $cServices[$szService];
				foreach($cThisService as $szProtocolAndPort)
				{
				
					$cProtPort = explode("^", $szProtocolAndPort);
					if (strlen(isset($szDevice)?$szDevice:""))
					{
					
						$szLine = $szIpTables." -A ".$cSourceDestiny[0][0]."PUT -".$cSourceDestiny[0][2]." ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[0][1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
						//NOTE! The next line has to include "NEW" for incoming Samba to work.. probably not the others...
						$szLine .= $szIpTables." -A ".$cSourceDestiny[1][0]."PUT -".$cSourceDestiny[1][2]." ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[1][1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
						//Above are wrong... interface is always specified as: -i <device>
						//$szLine = $szIpTables." -A ".$cSourceDestiny[0][0]."PUT -i ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[0][1]."port ".$cProtPort[1]." -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
						//$szLine .= $szIpTables." -A ".$cSourceDestiny[1][0]."PUT -i ".$szDevice." -p ".$cProtPort[0]." --".$cSourceDestiny[1][1]."port ".$cProtPort[1]." -m state --state ESTABLISHED,RELATED -j ACCEPT".$szLineBreak;
						$szRules .= $szLine;
					}
					else
						$szRules .= "#Rules skipped because networks are not properly setup..".$szLineBreak;
				}
			}
		}
		$szRules .= $szLineBreak;
		
	}
	
	if (!$nFound)
	{
		//$szSetupFlds = implode(",", $cSetupFlds);
		//$szValues = str_repeat("b'0'",sizeof($cSetupFlds));

		/*This part is moved to system install
		$cFlds = array();
		$pDb->execute("insert into fw_acceptTemplate(ruleTemplate) values ('SSH'),('Samba'),('HTTP')", $cFlds);
		$cRuleFlds = array(":prot"=>"HTTP");
		$pDb->execute("update fw_acceptTemplate set incomingInside = b'1', incomingOutside = b'1', outwardsInside = b'1', outwardsOutside = b'1' where ruleTemplate = :prot", $cRuleFlds);
		print h1("First time visit so populating the tables. Please click Firewall in left hand menu to enter again.");
		return;*/


		$pDb = new CDb;
		$cFlds = array();
		$pDb->execute("insert into systemMessage (message, sysSnapshotSection) values ('Something went wrong during installation! No rule templates installed!', 'www')", $cFlds);
		
		//listTemplates(); // Rurun.	Dropped because created infinite loop of adding recordc... 
	}

	$szHeadings = tr("<td rowspan=\"2\">Service</td>".td("Inside",2).td("Outside",2));
	$szOneSide = td("Outwards").td("Incoming");
	$szHeadings .= tr($szOneSide.$szOneSide);

	$szRows .= tr(td("<b>Click values in table to toggle</b>",5));
	
	$szRows .= tr(td('<form  action="index.php?f=fw_db&togglelogging=1" method="post"> <input type="checkbox" name="log" value="1" '.($cSetup["logRejected"]?"checked":"").'>Log rejected traffic&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="submit" value="Submit"></form>',5));
	
	print table($szHeadings.$szRows, 'border="1"');
	
	//Add default rules:
	//$szRules .= "#Allow all forward:<br>iptables -A FORWARD -i eth0 -s 192.168.0.0/24 -j ACCEPT",$szLineBreak;
	

	//NOTE!!!!This section being moved to perl....
	if ($cSetup["logRejected"]+0)
	{
		$szRules .= "\n#Logging\n";
		$szRules .= $szIpTables." -N LOGGING\n";
		$szRules .= $szIpTables." -A INPUT -j LOGGING\n";
		$szRules .= $szIpTables." -A OUTPUT -j LOGGING\n";

		//Drop those we don't want in the log
		$szRules .= $szIpTables." -A LOGGING -d 224.0.0.251 -j DROP\n";

		$szRules .= $szIpTables." -A LOGGING -m limit --limit 10/min -j LOG --log-prefix \"IPTables-Dropped: \" --log-level 4\n";
		$szRules .= $szIpTables." -A LOGGING -j DROP\n";
	}

	//$myfile = fopen($szIpTablesFromWebFile, "w") or die("Unable to open file!");
	//fwrite($myfile, $szRules);
	//fclose($myfile);

	//print "<br><br>Interpretation: ".$szRules;

	requireAccessUpdate();
	
}	


?>
