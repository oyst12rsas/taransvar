<?php

function users_addquota()
{
	if (!isSuperUser())
		return;

	$szUser = request("name");
	$pDb = new CDb;
	$cUser = $pDb->fetch("select quotaMB,coalesce(usageMB,0) usageMB from hotspotSubscriber where username=:name limit 1", array(":name"=>$szUser));
	if (!$cUser)
	{
		print "User not found! Aborting.";
		return;
	}

	print table(
		tr(td("Name").td(htmlspecialchars($szUser))) .
		tr(td("Total quota until now").td(htmlspecialchars((string)$cUser["quotaMB"]))) .
		tr(td("Total used").td(htmlspecialchars((string)$cUser["usageMB"])))
	);
	print '<form action="index.php?f=users_subQuota&name='.rawurlencode($szUser).'" method="post">'.
		'Add quota (in MB): <input name="quota" size="12"> <button type="submit">Submit</button></form>';
}

?>