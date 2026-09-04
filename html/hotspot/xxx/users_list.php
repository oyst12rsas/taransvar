<?php

function userList($nGroup, $nCamp, $nOffset) {
	$nInList = 15;
	$pDb = new CDb;
	$cFlds = array();
	$szRows = "";
	$szJoinAndWhere = "";

	if ($nGroup >= 0) {
		$szJoinAndWhere = " join radusergroup G on G.username = H.username where G.groupid = :id ";
		$cFlds = array(":id" => $nGroup);
	} else if ($nCamp > 0) {
		$szJoinAndWhere = " join radusergroup G on G.username = H.username join groupcampaign C on C.groupid = G.groupid where C.campaignid = :id ";
		$cFlds = array(":id" => $nCamp);
	}

	$szSqlCount = "select count(*) as counted from hotspotSubscriber H $szJoinAndWhere";
	$cCount = $pDb->fetch($szSqlCount, $cFlds);
	if (!$cCount) {
		print "Unable to count..";
		return;
	}
	$nCount = (int)$cCount["counted"];
	$szLimit = " limit $nInList ".(intval($nOffset)>0?"offset ".intval($nOffset)*$nInList:"");

	$szSQL = "select H.username, H.quotaMB, round(coalesce(H.usageMB,0),1) as theusage, H.subscriptionType, H.expiryTime, cast(H.enabled as unsigned) as enabled, H.confirmedTime, UNIX_TIMESTAMP(H.expiryTime)-UNIX_TIMESTAMP(now()) as moreTime from hotspotSubscriber H $szJoinAndWhere order by H.username $szLimit";
	$pListDb = new CDb;

	while ($cFetched = $pListDb->fetchNext($szSQL, $cFlds))
	{
		$szSubType = (string)$cFetched["subscriptionType"];
		$bEnabled = ((int)$cFetched["enabled"] === 1) && !empty($cFetched["confirmedTime"]);
		$bQuotaOk = ((float)$cFetched["quotaMB"] > (float)$cFetched["theusage"]);
		$bExpiryOk = (!empty($cFetched["expiryTime"]) && (int)$cFetched["moreTime"] > 0);

		switch ($szSubType)
		{
			case "quota":
				$bHasAccess = $bEnabled && $bQuotaOk;
				$szSubField = td(((float)$cFetched["quotaMB"]>0?$cFetched["quotaMB"]:red("No Quota")),1,'align="right"');
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				$szAddLink = td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
				break;
			case "expiry":
				$bHasAccess = $bEnabled && $bExpiryOk;
				$szSubField = td((empty($cFetched["expiryTime"])?red("Expiry not set"):substr($cFetched["expiryTime"],0,16)));
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				$szAddLink = td(a("[Add time]",func("users_addtime&name=".$cFetched["username"])));
				break;
			case "limited":
				$bHasAccess = $bEnabled && $bQuotaOk && $bExpiryOk;
				$szTxt = ((float)$cFetched["quotaMB"]>0?$cFetched["quotaMB"]."MB":red("No quota"));
				$szTxt .= "/".(empty($cFetched["expiryTime"])?red("Expiry not set"):($bExpiryOk?substr($cFetched["expiryTime"],0,16):red("Expired")));
				$szSubField = td($szTxt,1,'align="right"');
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				$szAddLink = td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
				break;
			default:
				$bHasAccess = false;
				$szSubField = td(red("Invalid subscription type"));
				$szUsage = td($cFetched["theusage"],1,'align="right"');
				$szAddLink = td("&nbsp;");
		}

		$szUsername = htmlspecialchars((string)$cFetched["username"]);
		$szStatus = !$bEnabled?red("DISABLED/UNCONFIRMED"):($bHasAccess?"YES":red("NO"));
		$szRows .= tr(
			td(a($szUsername,"index.php?f=users_show&nm=".rawurlencode($cFetched["username"]))).
			td(htmlspecialchars($szSubType)).$szSubField.$szUsage.
			td($szStatus,1,'align="Center"').$szAddLink.
			td(a("[Delete]","index.php?f=users_deluser&unm=".rawurlencode($cFetched["username"])))
		);
	}

	$szSeparator = "&nbsp;";
	$szParams = ($nGroup > 0?"f=users_group&id=$nGroup":($nCamp > 0 ? "f=users_showcamp&id=$nCamp":"f=users_list"));
	$szFastBack = (intval($nOffset) > 0 ? a(b("&lt;&lt;"),"index.php?$szParams&o=0"):'<font color="gray">&lt;&lt;</font>');
	$szBack = (intval($nOffset) > 0 ? a(b("&lt;"),"index.php?$szParams&o=".(intval($nOffset)-1)):'<font color="gray">&lt;</font>');
	$nPages = max(0, (int)ceil($nCount/$nInList)-1);
	$szNext = (intval($nOffset) < $nPages ? a(b("&gt;"),"index.php?$szParams&o=".(intval($nOffset)+1)):'<font color="gray">&gt;</font>');
	$szLast = (intval($nOffset) < $nPages ? a(b("&gt;&gt;"),"index.php?$szParams&o=".$nPages):'<font color="gray">&gt;&gt;</font>');
	$szRows .= tr(td("$szFastBack $szSeparator $szBack $szSeparator $szNext $szSeparator $szLast",7,'align="center"'));

	print table(tr(th("User name").th("Type").th("Quota/Expiry").th("Used").th("Access?").th("&nbsp;").th("&nbsp;")).$szRows);
}

function users_list()
{
	if (!isSuperUser())
		return;
	print table(tr(td(h1("Registered users:"))));
	print '<p><a href="index.php?f=users_pricing"><b>Hotspot pricing / demo packages</b></a> &nbsp; · &nbsp; <a href="index.php?f=users_earnings"><b>Roaming earnings</b></a></p>';
	userList(-1, -1, request("o"));
}

?>
