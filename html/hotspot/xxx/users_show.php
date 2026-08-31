<?php

function users_show()
{
	if (!isSuperUser())
	{
		print "Aborting";
		return;
	}

	$szUser = request("nm");
	$szUserEsc = htmlspecialchars($szUser, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	print "Usage information for $szUserEsc";
	for ($n=0;$n<10;$n++)
		print "&nbsp;";
	print '<a href="index.php?f=users_chpw&nm='.rawurlencode($szUser).'">Change password</a><br><br>';

	$pDb = new CDb;
	$cFlds = array(":name" => $szUser);
	$cFetched = $pDb->fetch(
		"select username, confirmedTime, subscriptionType, expiryTime, quotaMB, coalesce(usageMB,0) as usageMB, round(coalesce(quotaMB,0)-coalesce(usageMB,0),1) as quotaLeft, cast(enabled as unsigned) as enabled from hotspotSubscriber where username=:name limit 1",
		$cFlds
	);
	if (!$cFetched)
	{
		print "Problems fetching hotspot subscriber data! Aborting";
		return;
	}

	$bQuotaLeft = ((float)$cFetched["quotaLeft"] > 0);
	$szMbLeft = ($bQuotaLeft?"":'<font color="red">').htmlspecialchars((string)$cFetched["quotaLeft"]).($bQuotaLeft?"":'</font>');

	$szRows = tr(td("Enabled:").td(((int)$cFetched["enabled"] === 1)?"Yes":red("No"))) .
		tr(td("Confirmed:").td(!empty($cFetched["confirmedTime"])?htmlspecialchars((string)$cFetched["confirmedTime"]):red("No"))) .
		tr(td("Total quota:").td(htmlspecialchars((string)$cFetched["quotaMB"]),1,'align="right"')) .
		tr(td("Total used:").td(htmlspecialchars((string)$cFetched["usageMB"]),1,'align="right"')) .
		tr(td("Quota left:").td($szMbLeft,1,'align="right"').td("&nbsp;"));

	$szRows .= tr(td('<form action="index.php?f=users_subQuota&name='.rawurlencode($szUser).'" method="post">Add quota (in MB) &nbsp;<input name="quota" size="12"><button type="submit">Submit</button></form>',2));

	$cTypes = array(
		"quota" => "User has access until quota (megabytes of data) is used",
		"expiry" => "User has access until a specified date and time",
		"limited" => "Access until either quota or specified expiry reached"
	);
	$szOptions = "";
	foreach ($cTypes as $szValue => $szLabel)
	{
		$szSelected = ((string)$cFetched["subscriptionType"] === $szValue)?' selected="selected"':'';
		$szOptions .= '<option value="'.$szValue.'"'.$szSelected.'>'.htmlspecialchars($szLabel).'</option>';
	}
	$szChangSubTypeForm = '<form action="index.php?f=users_changesubtype&nm='.rawurlencode($szUser).'" method="post"><select name="subscriptionType">'.$szOptions.'</select><button type="submit">Submit</button></form>';

	$szRows .= tr(td("Subscription type:").td($szChangSubTypeForm));
	$szExpiry = empty($cFetched["expiryTime"])?red("Not set"):htmlspecialchars((string)$cFetched["expiryTime"]);
	$szRows .= tr(td("Expiry time:").td($szExpiry."&nbsp;".a("[Add time]",func("users_addtime&name=".$szUser))));

	print table($szRows);
	showUsageHistoryFor($szUser);
}

?>