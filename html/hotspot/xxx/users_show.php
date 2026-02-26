<?php

//function showUser()
function users_show()
{
	if (!isSuperUser())
	{
		print "Aborting";
		return;
	}

	$szUser = request("nm");
	
	print "Usage information for $szUser";
	for ($n=0;$n<10;$n++)
		print "&nbsp;";
	print '<a href="index.php?f=users_chpw&nm='.$szUser.'">Change password</a><br><br>';
	$pDb = new CDb;
	$cFlds = array(":name" => $szUser);
	if (!$cFetched = $pDb->fetchNext("select value, confirmedTime, confirmCode, wrongConfCodeCount, wrongPasswordTime, wrongPasswordCount, subscriptionType, expirytime, mbquota, round(mbusage,1) as mbusage, round(coalesce(mbquota,0)-coalesce(mbusage,0),1) as quotaLeft from radcheck where username = :name and op = ':=' and attribute = 'Cleartext-Password'", $cFlds))
	{
		print "Problems fetching user data! Aborting";
		return;
	}
	
	$bQuotaLeft = $cFetched["quotaLeft"] > 0; 
	
	$szMbLeft = ($bQuotaLeft?"":'<font color="red">').$cFetched["quotaLeft"].($bQuotaLeft?"":'</font>');
	
	$szRows = tr(td("Total quota:").td($cFetched["mbquota"],1,'align="right"')).//.td(a("[Add quota]",func("users_addquota&name=".$szUser)),2)).
			tr(td("Total used:").td($cFetched["mbusage"],1,'align="right"')).//td("&nbsp;",2)).
			tr(td("Quota left:").td($szMbLeft,1,'align="right"').td("&nbsp;"));
			
	$szRows .= tr(td(getAddQuotaForm($szUser, $bSubmitSameLine=true, $bIncludeUsername=false),2));
			
	$szChangSubTypeForm = '<form  action="index.php?f=users_changesubtype&nm='.$szUser.'" method="post">'.getSubscriptionTypeDropList($cFetched["subscriptionType"]).'<button type="submit">Submit</button></form>';
			
	$szRows .= tr(td("Subscription type:").td($szChangSubTypeForm));
	$szRows .= tr(td("Expiry time:").td($cFetched["expirytime"]."&nbsp;".a("[Add time]",func("users_addtime&name=".$szUser))));//.td("&nbsp;",2));
	
	
	print table($szRows);

	

	showUsageHistoryFor($szUser);
}

?>
