<?php

function users_group() {
	showUserGroup();
}

function showUserGroup()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	//print "HERE NOW<br>";
	$pDb = new CDb;
	$szName = request("nm");
	$nId = request("id");
	//$cFlds = array(":name"=>$szName);
	$cFlds = array(":id"=> $nId);
	if (!$cRec = $pDb->fetchNext("select groupname, description from usergroup where usergroupid = :id", $cFlds))
	{
		print "Group not found!";
		return;
		
	}
	$szName = $cRec["groupname"];
	$szRows = tr(td("User group information:",2));
	$szRows .= tr(td("Name:").td($cRec["groupname"])).
			tr(td("Description:").td($cRec["description"]));
			
	$szRows .= tr(td("Campaigns",2));
	
	$pCampDb = new CDb;
	$szCampRows = "";
	while ($cRec = $pCampDb->fetchNext("select campaignid, campaindescription, createtime, campaindescription, purpose, usernameprefix, giveHoursAfterLogin, giveMB, price, count from groupcampaign where groupid = :id", $cFlds))
	{
		$szCampRows .= tr(td(a($cRec["createtime"],"index.php?f=users_showcamp&id=".$cRec["campaignid"])).td($cRec["campaindescription"]).td($cRec["purpose"]).td($cRec["usernameprefix"]).td($cRec["giveHoursAfterLogin"]).td($cRec["giveMB"]).td($cRec["price"]).td($cRec["count"]));
	}
	
	$szRows .= tr(td(table($szCampRows),2));
	
	$szRows .= tr(td(a("Add campaign","index.php?f=users_addcamp&id=".$nId),2));
	print table ($szRows);
	
	//List group members
	$nCamp = -1; 
	$nOffset = request("o");
	require_once("users_list.php");
	userList($nId, $nCamp, $nOffset);	
	
	
	
/*$pUsersDb = new CDb;
	$szRows = "";
	while ($cRec = $pUsersDb->fetchNext("select r.username, mbquota, mbusage, expirytime from radusergroup g join radcheck r on r.username = g.username where groupid = :id", $cFlds))
	{
		$szRows .= tr(td($cRec["username"]).td($cRec["mbquota"]).td($cRec["mbusage"]).td($cRec["expirytime"]));
	}

	if (!strlen($szRows))
		$szRows = tr(td("No users in group",2));
*/		
	$szRows = tr(td("<br>You may also:",2));
	$szRows .= tr(td(a("- Add or remove users from group","index.php?f=users_changegrpusers&id=$nId"),2));
	$szRows .= tr(td(a("- Grant access","index.php?f=users_grantGroupQuota&id=$nId"),2));

	print table ($szRows);
}

?>
