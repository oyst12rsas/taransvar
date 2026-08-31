<?php

function users_changesubtype()
{
	if (!isSuperUser())
		return;

	$szUser = request("nm");
	$szType = (string)request("subscriptionType");
	$cAllowed = array("quota", "expiry", "limited");
	if (!in_array($szType, $cAllowed, true))
	{
		print red("Invalid subscription type.");
		return;
	}

	$pDb = new CDb;
	$cUser = $pDb->fetch("select username from hotspotSubscriber where username=:name limit 1", array(":name"=>$szUser));
	if (!$cUser)
	{
		print "User not found! Aborting.";
		return;
	}

	$cFlds = array(":name"=>$szUser, ":type"=>$szType);
	$pDb->execute("update hotspotSubscriber set subscriptionType=:type where username=:name", $cFlds);
	// Keep the explicit legacy row synchronized while old reports still exist.
	$pDb->execute("update radcheck set subscriptionType=:type where username=:name and op=':=' and attribute='Cleartext-Password'", $cFlds);
	requireAccessUpdate();

	print 'Subscription type is changed.<br><br><a href="index.php?f=users_show&nm='.rawurlencode($szUser).'">Back to user</a>';
}

?>