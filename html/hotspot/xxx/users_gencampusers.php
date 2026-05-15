<?php

function users_gencampusers()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$nCampId = request("id");
	$cFlds = array(":id"=>$nCampId);
	if (!$cRec = $pDb->fetchNext("select usergroupid, groupname, createtime, campaindescription, purpose, usernameprefix, randomchars, CAST(successive as UNSIGNED) as successive, numbersonly, giveHoursAfterLogin, giveMB, count from groupcampaign C join usergroup G on G.usergroupid = groupid where campaignid = :id", $cFlds))
	{
		print "Campaign not found!";
		return;
	}
	
	$nCount = CDb::getString("select count(username) as usercount from radcheck where campaignid = :id", $cFlds);
	
	if ($nCount)
	{
		print red("$nCount users are already registered for this campaign. Aborting!");
		return;
	}

	$nRandomChars = $cRec["randomchars"]+0;

	if (!$nRandomChars+0)
	{
		print red("Number of random chars must be larger than zero.. Aborting.");
		return;
	}
	
	$nCount = $cRec["count"];
	$szPrefix = $cRec["usernameprefix"];
	$nGiveHours = $cRec["giveHoursAfterLogin"]+0;
	$nGiveQuota = $cRec["giveMB"]+0;
	$nUserGroupId = $cRec["usergroupid"];
	
	$szRows = "";
	
	
	for ($n=0; $n< $nCount;$n++)
	{
		$szUser = $szPrefix.random_str($nRandomChars);
		$szPass = random_str(4);
		
		$cFlds = array(":user" => $szUser);
		$szFound = $pDb->getString("select username from radcheck where username = :user", $cFlds);
		
		if (strlen($szFound))
			$szFound = "Already exists";
		else
			$szFound = "Doesn't exist";
		
		$cParam = array(":name" => $szUser, ":pass" => $szPass, ":camp" => $nCampId);
		
		 
		//NOTE! N hours after login or set expirytime....
		$szFlds = $szValues = "";
		
		if ($nGiveHours >0 && $nGiveQuota > 0)
		{
			$szFlds .= ", mbquota, giveHoursAfterLogin, subscriptionType";
			$szValues .= ", :quota, :hours, :type";
			$cParam = array_merge($cParam, array(":quota"=>$nGiveQuota,":hours"=>$nGiveHours, ":type" => "limited"));
		}
		else
		{
			if ($nGiveHours > 0)
			{
				$szFlds .= ", giveHoursAfterLogin, subscriptionType";
				$szValues .= ", :hours, :type";
				$cParam = array_merge($cParam, array(":hours"=>$nGiveHours, ":type" => "expiry"));
			}
			else
				if ($nGiveQuota > 0)
				{
					$szFlds .= ", mbquota, subscriptionType";
					$szValues .= ", :quota, :type";
					$cParam = array_merge($cParam, array(":quota"=>$nGiveQuota, ":type" => "quota"));
				}
				else
				{
					print red("Neither quota or hours is specified.. Aborting.");
					return;
				}
				
		}
		
		
		$szSQL = "insert into radcheck (username, attribute, op, value, campaignid, confirmedTime $szFlds) values (:name, 'Cleartext-Password', ':=', :pass, :camp, now() $szValues)";
		$pDb->execute($szSQL, $cParam);

		$szSQL = "insert into radusergroup (username, groupid) values (:name, :id)";
		$pDb->execute($szSQL, array(":name"=>$szUser, ":id"=>$nUserGroupId));


		$szRows .= tr(td($szUser).td($szPass).td($szFound).td($szSQL)); 
	}

	require_once("xxx/users_showcamp.php");
	showCampaign($nCampId);
	//print table($szRows);
}

?>
