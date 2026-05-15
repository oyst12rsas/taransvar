<?php

function users_grantGroupQuota() {
	$pDb = new CDb;
	$nId = request("id");
	$cFlds = array(":id"=>$nId);
	if (!$cRec = $pDb->fetchNext("select groupname, description from usergroup where usergroupid = :id", $cFlds))
	{
		print "Group not found!";
		return;
	}
	
	if (isset($_GET["submit"]) || isset($_GET["confirm"])) {
		if (grantGroupQuotaOk())
			return;
	}
	
	$szRows = tr(td("User group information:",2));
	$szRows .= tr(td("Name:").td($cRec["groupname"])).
			tr(td("Description:").td($cRec["description"]));
		
	$szRows .= tr(td("MB data").td('<input name="mbdata">')).
		tr(td("Max data").td('<input name="maxdata">')).
		//tr(td("days").td('<input name="days">')).
		tr(td('<input type="hidden" name="f" value="users_grantGroupQuota"><input type="hidden" name="id" value="'.
		$nId.'">').td('<input type="submit" name="submit">'));
		
	print '<form action="index.php?f=users_grantGroupQuota">'.table($szRows).'</form>';
}

function grantGroupQuotaOk() {
	$nMBdata = intval($_GET["mbdata"]);
	$nMaxData = intval($_GET["maxdata"]);
	$szGroup = request("id");
	$szGroupName = getGroupName($szGroup);
	
	if (!isset($_GET["confirm"])) {
		print "All members of $szGroupName will be given $nMBdata";
		
		if ($nMaxData)
			print ", but none of them will have more than $nMaxData after the operation";

		print ".<br><br>";
	
		print a("Click here to confirm", "index.php?f=".$_GET["f"]."&mbdata=".$nMBdata."&maxdata=".$nMaxData."&id=".$szGroup."&confirm=1");
		return true;
	} else {
		if ($nMaxData == 0)
			$nMaxData = 1000000000;
		
		//$nDays = $_GET["days"];
		$szSQL = "update radcheck set mbquota = least(mbquota + $nMBdata, $nMaxData) where username in (
			select username from radusergroup where groupid = :group )";
		
		$pDb = new CDb;
		$cFlds = array(":group" => $szGroup);
		$pDb->execute($szSQL, $cFlds);
		print "<br>Quota granted.<br><br>".a("Click here to go back to ".$szGroupName,"index.php?f=users_group&id=".$szGroup);
		return true;	
	}		
}


?>
