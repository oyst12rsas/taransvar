<?php

function users_showcamp()
{
	showCampaign(request("id"));
}

function showCampaign($nCampaignId=0)
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	if (!$nCampaignId)
		$nCampaignId = request("id");

	$pDb = new CDb;
	$cFlds = array(":id"=>$nCampaignId);
	if (!$cRec = $pDb->fetchNext("select usergroupid, g.groupname, left(description,30) as description, left(campaindescription,30) as campaindescription, purpose, usernameprefix, randomchars, giveHoursAfterLogin, giveMB, createtime, count, price, priceinfo from usergroup g join groupcampaign c on c.groupid = g.usergroupid where campaignid = :id", $cFlds))
	{
		print "Campaign not found!";
		return;
	}
	
	$szRows = tr(td(h2("Campaign info"),2));
	$szRows .= tr(td("Group:").td(a($cRec["groupname"],"index.php?f=users_group&id=".$cRec["usergroupid"])));
	$szRows .= tr(td("Description:").td($cRec["description"]));
	$szRows .= tr(td("Campaign:").td($cRec["campaindescription"]));
	
	$szRows .= tr(td("Purpose:").td($cRec["purpose"]));
	$szRows .= tr(td("Prefix:").td($cRec["usernameprefix"]));
	//$szRows .= tr(td(":").td($cRec["randomchars"]));
	$szRows .= tr(td("Hours:").td($cRec["giveHoursAfterLogin"]));
	$szRows .= tr(td("MB:").td($cRec["giveMB"]));
	$szRows .= tr(td("Created:").td(substr($cRec["createtime"],0,16)));
	$szRows .= tr(td("Count:").td($cRec["count"]));
	$szRows .= tr(td("Price:").td($cRec["price"]));
	$szRows .= tr(td("Price info:").td($cRec["priceinfo"]));
	
	$szRows .= tr(td(a("Print labels","index.php?f=users_printlabels&id=".$nCampaignId),2));

	if ($_GET["f"] == "users_grantCampQuota")
	{
		
		if (!isset($_GET["confirmed"]))
		{
			print a("Please confirm that you want to grant quota or increase expiry date","index.php?f=users_grantCampQuota&confirmed=1&id=".$nCampaignId);
			print table($szRows);
		}
		else
		{
			$szRows .= tr(td(red("Should grant...."),2));

			if ($cRec["giveMB"]+0)
			{
				if ($cRec["giveHoursAfterLogin"]+0)
				{
					$szSubscriptionType = "limited";
				}
				else
					$szSubscriptionType = "quota";
			}
			else
				$szSubscriptionType = "expiry";
			
			
			$pScanDb = new CDb;
			$cFlds = array(":group" => $cRec["groupname"]);
			$szUserRows = tr(th("Current status:",4,'align="center"'),th("&nbsp;")).tr(th("Username").th("Quota").th("Used").th("Expires").th("Granted"));
			while ($cGrpmem = $pScanDb->fetchNext("select g.username, mbquota, mbusage, expirytime, giveHoursAfterLogin from radusergroup g join radcheck r on r.username = g.username where groupname = :group", $cFlds))
			{
				$szStatusTxt = $szSubscriptionType.": ";
				$cFlds = array(":user" => $cGrpmem["username"]);
				$szFlds = "";
				switch ($szSubscriptionType)
				{
					case "limited":
						$cFlds = array_merge($cFlds, array(":quota"=>$cRec["giveMB"]+$cGrpmem["mbquota"]+0, ":hours" => $cRec["giveHoursAfterLogin"] + $cGrpmem["giveHoursAfterLogin"]+0));
						$szFlds = "mbquota = :quota, giveHoursAfterLogin = :hours";
						$szStatusTxt .= "Quota: ".$cFlds[":quota"].", hours: ".$cFlds[":hours"];
						break;
					case "quota":
						$cFlds = array_merge($cFlds, array(":quota"=>$cRec["giveMB"]+$cGrpmem["mbquota"]+0));
						$szFlds = "mbquota = :quota";
						$szStatusTxt .= "Quota: ".$cFlds[":quota"];
						break;
					case "expiry":
						$cFlds = array_merge($cFlds, array(":hours" => $cRec["giveHoursAfterLogin"] + $cGrpmem["giveHoursAfterLogin"]+0));
						$szFlds = "giveHoursAfterLogin = :hours";
						$szStatusTxt .= "Hours: ".$cFlds[":hours"];
						break;
					default : 
						$szStatusTxt .= " INVALID SUBSCRIPTION TYPE";
				}
				
				
				$pDb->execute("update radcheck set $szFlds where username = :user", $cFlds);
				
				$szUserRows .= tr(td($cGrpmem["username"]).td($cGrpmem["mbquota"]).td($cGrpmem["mbusage"]).td($cGrpmem["expirytime"]).td($szStatusTxt));
			}
			$szRows .= tr(td(table($szUserRows),2));
			print table($szRows);
		}
		return;
	}

	print table($szRows);

	$nGroup = -1;
	$nOffset = request("o");
	require_once("users_list.php");
	userList($nGroup, $nCampaignId, $nOffset);	

/*
	$cUserDb = new CDb;
	
	$nCount = 0;
	$szUserRows = tr(th("Username").th("Quota").th("Used").th("Expires"));
	while ($cMember = $cUserDb->fetchNext("select username, mbquota, round(mbusage,1) as mbusage, expirytime from radcheck where campaignid = :id order by username", $cFlds))
	{
		$nCount++;
		$szUserRows .= tr(td($cMember["username"]).td($cMember["mbquota"]).td($cMember["mbusage"]).td(substr((isset($cMember["expirytime"])?$cMember["expirytime"]:""),0,16)));
	}

	if ($nCount)
	{
		$szRows .= tr(td(table($szUserRows),2));
		
		$szRows .= tr(td(a("Print usernames/password","index.php?f=users_printlabels&id=".$nCampaignId),2));
		//$szRows .= tr(td("",2));
	}
	else
	{
		if ($cRec["purpose"] == "generatetempusers")
			$szRows .= tr(td(red("No user names generated yet.. ")."  ".a("Click here to generate now","index.php?f=users_gencampusers&id=".$nCampaignId),2));
		else
		{
			$cFlds = array(":group" => $cRec["groupname"]);
			$nCount = CDb::getString("select count(username) from radusergroup where groupname = :group",$cFlds);

//			$szRows .= tr(td(a("Print tickets for this group", "index.php?f=users_campTickets&id=".$nCampaignId),2));
			$szRows .= tr(td(a("Print tickets for this group", "index.php?f=users_printlabels&id=".$nCampaignId),2));

			if ($nCount)
			{
				$szRows .= tr(td("This group has $nCount members. ".a("Grant quota or increase expiry date","index.php?f=users_grantCampQuota&id=".$nCampaignId),2));
			}
			else
				$szRows .= tr(td(red("No user names selected yet.. And function for doing so is not yet implemented (this campaign is marked to target existing group members)"),2));
		}
	}
		
	print table($szRows);
*/
}
 ?>
