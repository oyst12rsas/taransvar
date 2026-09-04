<?php

function users_earnings()
{
	if (!isSuperUser()) return;
	$pDb = new CDb;

	print table(tr(td(h1("Hotspot earnings from roaming TaraSec users"))));
	print '<p>This page shows value earned when this hotspot serves TaraSec subscribers whose account was authenticated through the global TaraSec service. Demo amounts are accounting test values until settlement/payment is enabled.</p>';

	$cfg = $pDb->fetch("select roamingPriceKshPerMiB,networkFeePercent,currency,cast(isDemo as unsigned) isDemo from hotspotEarningConfig where configId=1", array());
	if ($cfg) {
		$mode = ((int)$cfg["isDemo"] === 1) ? "Demo" : "Live";
		print '<p><b>'.$mode.' earning rate:</b> '.number_format((float)$cfg["roamingPriceKshPerMiB"]*1024.0,2).' '.htmlspecialchars((string)$cfg["currency"]).'/GB &nbsp; · &nbsp; Network fee '.number_format((float)$cfg["networkFeePercent"],1).'%</p>';
	}

	$summary = $pDb->fetch(
		"select count(distinct coalesce(customerId,deviceKey)) externalUsers, coalesce(sum(usageMiB),0) usageMiB, coalesce(sum(grossAmount),0) grossAmount, coalesce(sum(networkFee),0) networkFee, coalesce(sum(netAmount),0) netAmount from hotspotEarning",
		array()
	);
	$today = $pDb->fetch(
		"select coalesce(sum(netAmount),0) amount from hotspotEarning where createdTime >= curdate() and settlementStatus <> 'reversed'",
		array()
	);
	$month = $pDb->fetch(
		"select coalesce(sum(netAmount),0) amount from hotspotEarning where createdTime >= date_format(now(),'%Y-%m-01') and settlementStatus <> 'reversed'",
		array()
	);
	$pending = $pDb->fetch(
		"select coalesce(sum(netAmount),0) amount from hotspotEarning where settlementStatus='pending'",
		array()
	);
	$available = $pDb->fetch(
		"select coalesce(sum(netAmount),0) amount from hotspotEarning where settlementStatus='available'",
		array()
	);
	$currency = $cfg ? htmlspecialchars((string)$cfg["currency"]) : "KES";

	if ($summary) {
		print table(
			tr(th("External users").th("Data served").th("Gross earned").th("Network fee").th("Net earned")).
			tr(
				td((int)$summary["externalUsers"],1,'align="right"').
				td(number_format((float)$summary["usageMiB"]/1024.0,3)." GB",1,'align="right"').
				td(number_format((float)$summary["grossAmount"],2)." $currency",1,'align="right"').
				td(number_format((float)$summary["networkFee"],2)." $currency",1,'align="right"').
				td(number_format((float)$summary["netAmount"],2)." $currency",1,'align="right"')
			)
		);
	}

	print '<p><b>Today:</b> '.number_format((float)($today["amount"] ?? 0),2).' '.$currency.
		' &nbsp; · &nbsp; <b>This month:</b> '.number_format((float)($month["amount"] ?? 0),2).' '.$currency.
		' &nbsp; · &nbsp; <b>Pending settlement:</b> '.number_format((float)($pending["amount"] ?? 0),2).' '.$currency.
		' &nbsp; · &nbsp; <b>Available:</b> '.number_format((float)($available["amount"] ?? 0),2).' '.$currency.'</p>';

	$rows = "";
	$pList = new CDb;
	while ($r = $pList->fetchNext(
		"select earningId,sessionId,customerId,deviceKey,usageMiB,grossAmount,networkFee,netAmount,currency,settlementStatus,cast(isDemo as unsigned) isDemo,createdTime from hotspotEarning order by earningId desc limit 100",
		array()
	)) {
		$identity = !empty($r["customerId"]) ? (string)$r["customerId"] : (string)$r["deviceKey"];
		$rows .= tr(
			td(htmlspecialchars(substr($identity,0,40))).
			td(number_format((float)$r["usageMiB"],2)." MiB",1,'align="right"').
			td(number_format((float)$r["grossAmount"],2),1,'align="right"').
			td(number_format((float)$r["networkFee"],2),1,'align="right"').
			td(number_format((float)$r["netAmount"],2)." ".htmlspecialchars((string)$r["currency"]),1,'align="right"').
			td(htmlspecialchars((string)$r["settlementStatus"])).
			td(((int)$r["isDemo"] === 1 ? "Demo" : "Live")).
			td(htmlspecialchars((string)$r["createdTime"]))
		);
	}
	if ($rows === "") {
		$rows = tr(td("No roaming earnings recorded yet.",8));
	}
	print table(tr(th("External account/device").th("Usage").th("Gross").th("Fee").th("Net").th("Settlement").th("Mode").th("Time")).$rows);
	print '<p><a href="index.php?f=users_list">Back to registered users</a></p>';
}

?>
