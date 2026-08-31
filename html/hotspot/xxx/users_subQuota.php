<?php

function users_subQuota()
{
	if (!isSuperUser())
		return;

	$szUser = request("name");
	$szQuota = trim((string)request("quota"));
	if ($szQuota === '' || !is_numeric($szQuota) || (float)$szQuota <= 0)
	{
		print red("Quota must be a positive number of MB.");
		return;
	}

	$nQuota = (float)$szQuota;
	$pDb = new CDb;
	$cUser = $pDb->fetch("select quotaMB from hotspotSubscriber where username=:name limit 1", array(":name"=>$szUser));
	if (!$cUser)
	{
		print "User not found! Aborting.";
		return;
	}

	$cFlds = array(":name"=>$szUser, ":quota"=>$nQuota);
	$pDb->execute("update hotspotSubscriber set quotaMB=coalesce(quotaMB,0)+:quota where username=:name", $cFlds);
	// Keep legacy reporting data synchronized while it is still present.
	$pDb->execute("update radcheck set mbquota=coalesce(mbquota,0)+:quota where username=:name and op=':=' and attribute='Cleartext-Password'", $cFlds);
	requireAccessUpdate();

	print 'Quota is added.<br><br><a href="index.php?f=users_show&nm='.rawurlencode($szUser).'">Back to user</a>';
}

?>