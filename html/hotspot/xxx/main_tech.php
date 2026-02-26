<?php


function main_tech()
{
	if (!isSuperUser())
		return;

	if (request("submit") == "1")
	{
        $szNewIp = request("ip");
		print h2("Submitted... should save, IP=".$szNewIp);
		//$szNewMsg = request("comment");
		$cFlds = array(":ip"=> $szNewIp);
		
		$pDb = new CDb;
		$pDb->execute("update hotspotSetup set internalIP = :ip", $cFlds);
		
		print "Technical setup should be saved.....<br><br>";
        printRunSetupNetworkLink();
		print red("You should do that now, otherwise there will be inconsitency in your server setup...<br><br>");
		return;
	}
	
	$cRec = getSetup();
	$szInternalIP = $cRec["internalIP"];

	$szRows = tr(td(red("NOTE! You should not change this setup unless you know what you're doing!"),2));

	$szRows .= tr(td("Internal IP:").td('<input name="ip" value="'.$szInternalIP.'">'));

	$szRows .= tr(td('<button type="submit">Submit</button>',2));					
	
	print '<form  action="index.php?f=main_tech&submit=1" method="post">'.table($szRows).'</form>';

    print "<br>".a("Back to administrative setup", "index.php?f=main_setup");
	
}

?>
