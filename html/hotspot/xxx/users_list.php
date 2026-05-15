<?php

function userList($nGroup, $nCamp, $nOffset) {
	$nInList = 15;
	$pDb = new CDb;
	
	if (!$pDb)
	{
		print "Unable to login!";
		return;
	}
	
	$cFlds = array();
	$szRows = "";

	$szJoinAndWhere = "";
	
	if ($nGroup >= 0) {
		$szJoinAndWhere = " join radusergroup G on G.username = R.username where groupid = :id ";  
		$cFlds = array(":id" => $nGroup);
	} else {
		if ($nCamp > 0) {
			$szJoinAndWhere = " join radusergroup G on G.username = R.username join groupcampaign C on C.groupid = G.groupid where C.campaignid = :id ";  
			$cFlds = array(":id" => $nCamp);
		}
	
	}
	
	$nCount = 0;
	$szSqlCount = "select count(*) as counted from radcheck R $szJoinAndWhere"; 
	if ($cCount = $pDb->fetch($szSqlCount, $cFlds))
		$nCount = $cCount["counted"];
	else
	{
		print "Unable to count..";
		return;
	}
	
	
	
	$szLimit = " limit $nInList ".(intval($nOffset)>0?"offset ".intval($nOffset)*$nInList:"");
	

	$szSQL = "select R.username, email, value as password, mbquota, round(mbusage,1) as theusage, if(isnull(confirmedTime),confirmCode,'') as theCode, subscriptionType, expirytime,  UNIX_TIMESTAMP(expirytime) - UNIX_TIMESTAMP(now()) as moreTime from radcheck R $szJoinAndWhere order by username $szLimit";

	//print "$szSQL<br>";

	$pDb = new CDb;
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
	{
		$szSubType = $cFetched["subscriptionType"];
		
		switch ($szSubType)
		{
			case "quota":
				$bHasAccess = $cFetched["mbquota"]>$cFetched["theusage"];
				$szSubField = td(($cFetched["mbquota"]>0?$cFetched["mbquota"]:red("No Quota")),1,'align="right"');
				$szAddLink = td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				break;
			case "expiry":
				$bHasAccess = (strlen($cFetched["expirytime"]) && $cFetched["moreTime"] > 0);
				$szSubField = td((!strlen($cFetched["expirytime"])?red("Expiry not set"):substr($cFetched["expirytime"],0,16)));
				$szAddLink = td($cFetched["theusage"],1,'align="right"').td(a("[Add time]",func("users_addtime&name=".$cFetched["username"])));
				$szUsage = td("&nbsp;");
				break;
			case "limited":
				$bHasAccess = ($cFetched["mbquota"]+0 > $cFetched["theusage"]+0 && $cFetched["moreTime"] > 0);
				$szTxt = ($bHasAccess?$cFetched["mbquota"]."MB":red("Quota used"));
				
				if ($bHasAccess)
					$szTxt .= "/".(!strlen($cFetched["expirytime"])?red("Expiry not set"):(!expired($cFetched["expirytime"])?substr($cFetched["expirytime"],0,16):red("Expired")));
				
				$szSubField = td($szTxt,1,'align="right"');
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				$szAddLink = td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
			
				break;
			default: $szSubField = td(red("Invalid subscription type"),3);
				$bHasAccess = 0;
				$szAddLink = td("&nbps;");
				$szUsage = "";
		}
	
		$szUsername = (isset($cFetched["username"]) && strlen($cFetched["username"]) > 0 ? $cFetched["username"] : $cFetched["email"]); 
		  
		$szRows .= tr(td(a($szUsername,"index.php?f=users_show&nm=".$cFetched["username"])).td($cFetched["subscriptionType"]).$szSubField.$szUsage.td(($bHasAccess?"YES":red("NO")),1,'align="Center"').$szAddLink.td(a("[Delete]","index.php?f=users_deluser&unm=".$cFetched["username"])).
				td($cFetched["theCode"]) 
				);
	}
	$szSeparator = "&nbsp;";
	$szParams = ($nGroup > 0?"f=users_group&id=$nGroup":($nCamp > 0 ? "f=users_showcamp&id=$nCamp":"f=users_list"));
	$szFastBack = (intval($nOffset) > 0 ? a(b("&lt;&lt;"),"index.php?$szParams&o=0"):'<font color="gray">&lt;&lt;</font>');
	$szBack = (intval($nOffset) > 0 ? a(b("&lt;"),"index.php?$szParams&o=".$nOffset-1):'<font color="gray">&lt;</font>');
	$nPages = floor($nCount/$nInList);
	$szNext = (intval($nOffset) < $nPages ? a(b("&gt;"),"index.php?$szParams&o=".(intval($nOffset)+1)):'<font color="gray">&gt;</font>');
	$szLast = (intval($nOffset) < $nPages ? a(b("&gt;&gt;"),"index.php?$szParams&o=".$nPages):'<font color="gray">&gt;&gt;</font>');
	
	$szRows .= tr(td("$szFastBack $szSeparator $szBack $szSeparator $szNext $szSeparator $szLast",8,'align="center"')); 
	
	print table(tr(th("User name").th("Type").th("Quota/Expiry").th("Used").th("Access?").th("&nbsp;").th("&nbsp;").th("Code")).$szRows);
}

function users_list()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	print table(tr(td(h1("Registered users:"))));
	//print "In the function";
	//return;
	
	$nGroup = -1;
	$nCamp = -1; 
	$nOffset = request("o");
	userList($nGroup, $nCamp, $nOffset);	
	
	//NOTE RESULTS IN ABORT..: delete $pDb;
	
}

?>
