<?php

function logs_status()
{
	if (!isSuperUser())
		return;
		
	print a("Click here to see server status (opens in new tab in the browser)", "/cgi-bin/debugserver",'target="new"')."<br><br>NOTE! This will open in a new tab in your browser. Close it to get back here.<br><br>";
	
    printRunSetupNetworkLink();

	print a("Update system info", "index.php?f=logs_debugserver")." - NOTE! Use link above to see the result after appx 30 seconds.<br><br>";
	
	$cArr = array();
    $pDb = new CDb;
    $cSetup = $pDb->fetch("select coalesce(maintenanceRequest,'') as maintenanceRequest, 
            round(unix_timestamp(now()) - unix_timestamp(coalesce(lastAccessUpdate,'2000-01-01 01:00'))) as lastAccessUpdate, 
            round(unix_timestamp(now()) - unix_timestamp(coalesce(lastAccessUpdatePoll,'2000-01-01 01:00'))) as lastAccessUpdatePoll from setup", $cArr);
	$szCurrent = $cSetup["maintenanceRequest"];
	if (strlen($szCurrent))
	{
		print b("Current maintenence request: ".$szCurrent." NOTE! They should be handled within 30 seconds.<br><br>"); 
	}
	else
		print b("No current maintenence request!<br><br>");
		
	
	//if (file_exists ("/temp
	$szFilename = "/var/www/html/temp/maintainanceRequest.txt";

	if (!file_exists ($szFilename))
		print "No result from maintenence yet.. Not yet run or some error occurred?";
	else
	{
		$szFile = file_get_contents($szFilename);
		print h2("Last maintenance result:")."<br><br>".$szFile;
	}

    print "<br><br>".h2("Seconds since access update:").table(tr(td("Last access update:").td($cSetup["lastAccessUpdate"],1,'align="right"')).
                tr(td("Last poll:").td($cSetup["lastAccessUpdatePoll"],1,'align="right"')));

    if ($cSetup["lastAccessUpdatePoll"] > 15 || $cSetup["lastAccessUpdate"] >130)
        print h2(red("There's something wrong here. Need to update should be polled every 10 seconds and access update at least every 2 minutes!"));
}

?>
