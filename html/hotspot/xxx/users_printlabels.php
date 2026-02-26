<?php

function users_printlabels()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$cSetup = getSetup("print");
	
	$szFile = "labelTemplate.html";
	$myfile = fopen($szFile, "r");
	$szTemplate = fread($myfile,filesize($szFile));
	fclose($myfile);

	$pDb = new CDb;
	$cFlds = array(":id" => request("id"));
	if (!$cCampaign = $pDb->fetchNext("select price, priceinfo, giveMB, giveHoursAfterLogin from groupcampaign where campaignid = :id", $cFlds))
	{
		print "ERROR! Unable to read campaign info!";
		return;
	}

	if (!isset($_POST["submit"]))
	{
		$szRows = tr(td("Labels across").td('<input name="across" value="'.$cSetup["printNumbersAcross"].'">'));
		$szRows .= tr(td("Print padding").td('<input name="pad" value="'.$cSetup["printPadding"].'">'));
		$szRows .= tr(td("Font size").td('<input name="size" value="'.$cSetup["printFontSize"].'">'));
		$szRows .= tr(td("Price").td('<input name="price" value="'.$cCampaign["price"].'">'));
		$szRows .= tr(td("Price info").td('<input name="info" value="'.$cCampaign["priceinfo"].'">'));
		$szRows .= tr(td('<button name="submit" type="submit">Submit</button>',2));					
		
		print '<form  action="index.php?f=users_printlabels&id='.request("id").'" method="post">'.table($szRows).'</form>';

        $szLabelFile = "labelTemplate.html";
	    $szLayout = file_get_contents($szLabelFile);

        print h2("Current label layout:");
        print $szLayout;

        print "<br><br>".a("Click here to change the ticket setup", "index.php?f=main_ticketlayout");


		return;
	}
	else
	{
		$cSetupFlds = array(":across" => request("across")+0, ":pad" => request("pad"), ":size" => request("size"));
		$pDb->execute("update adminSetup set printNumbersAcross = :across, printPadding = :pad, printFontSize = :size", $cSetupFlds);
		$cSetup = getSetup("print");
		
		$cSetupFlds = array(":id" => request("id"), ":price" => request("price")+0, ":info" => request("info"));
		$pDb->execute("update groupcampaign set price = :price, priceinfo = :info where campaignid = :id", $cSetupFlds);
		$cCampaign["price"] = request("price")+0;
		$cCampaign["priceinfo"] = request("info");
	}

	print '<style>
body {background-color: white;}
h1   {color: blue;}
p    {color: red;}
.main {  
	padding: 0px; 
}
.label {
	padding: '.$cSetup["printPadding"].'px; 
	font-size: '.$cSetup["printFontSize"].'px;
	font-family: Arial, Helvetica, sans-serif;
}
</style>';
	print "</head><body>";	//<head> is printed before reaches here..

	
	if ($cCampaign["giveMB"]+0 > 0)
		$szSubscriptionInfo = $cCampaign["giveMB"]. "MB";
	else
		$szSubscriptionInfo = "";

	$nHours = $cCampaign["giveHoursAfterLogin"] +0;

	if ($nHours)
	{
		if ($nHours % 24 == 0)
		{
			$nDays = $nHours/24;
			$szPeriod = "$nDays day".($nDays != 1?"s":"");
		}
		else
			$szPeriod = "$nHours hour".($nHours != 1?"s":"");
			
		$szSubscriptionInfo .= (strlen($szSubscriptionInfo)?"/":"").$szPeriod;
	}

	if (strlen($cCampaign["priceinfo"]))
		$szPriceInfo = $cCampaign["priceinfo"];
	else
		$szPriceInfo = $cCampaign["price"];

	$cUserDb = new CDb;
		
	$nCount = $nAcrossCount = 0;
	$szUserRows = "";
	$szRow = "";
	while ($cMember = $cUserDb->fetchNext("select username, value from radcheck where campaignid = :id order by username", $cFlds))
	{
		$nCount++;
		$szLabel = str_replace("[username]", $cMember["username"], $szTemplate);
		$szLabel = str_replace("[password]", $cMember["value"], $szLabel);
		$szLabel = str_replace("[priceinfo]", $szPriceInfo, $szLabel);
		$szLabel = str_replace("[URL]", $cSetup["internalIP"], $szLabel);
		$szLabel = str_replace("[subscriptioninfo]", $szSubscriptionInfo, $szLabel);
		
		$szUserRows .= td($szLabel);
		$nAcrossCount ++;
		if ($nAcrossCount == $cSetup["printNumbersAcross"])
		{
			$szRow .= tr($szUserRows);
			$szUserRows = "";
			$nAcrossCount = 0;
		}
	}

	if (strlen($szUserRows))
	{
		if ($nAcrossCount)
			$szRow .= td("&nbsp;", $cSetup["printNumbersAcross"]-$nAcrossCount);
		
		$szRow .= tr($szUserRows);
	}


	print table($szRow,'class="main"');
}

?>
