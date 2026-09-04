<?php

function users_pricing()
{
	if (!isSuperUser()) return;
	$pDb = new CDb;
	$notice = "";

	if (isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] === "POST") {
		$id = (int)request("packageId");
		$label = trim((string)request("label"));
		$quota = max(1, (int)request("quotaMB"));
		$price = max(0, (float)request("priceKsh"));
		$enabled = request("enabled") ? 1 : 0;
		if ($id > 0 && $label !== "") {
			$pDb->execute(
				"update hotspotPricePackage set label=:label, quotaMB=:quota, priceKsh=:price, enabled=:enabled where packageId=:id",
				array(":label"=>$label, ":quota"=>$quota, ":price"=>$price, ":enabled"=>$enabled, ":id"=>$id)
			);
			$notice = "Pricing updated.";
		}
	}

	print table(tr(td(h1("Hotspot pricing"))));
	print "<p>These prices are published by this hotspot for TaraSec app/service testing. Current rows are demo prices, not a payment commitment.</p>";
	if ($notice !== "") print "<p><b>".htmlspecialchars($notice)."</b></p>";

	$rows = "";
	$pList = new CDb;
	while ($r = $pList->fetchNext("select packageId,label,quotaMB,priceKsh,currency,cast(enabled as unsigned) enabled,cast(isDemo as unsigned) isDemo from hotspotPricePackage order by sortOrder,quotaMB", array())) {
		$id = (int)$r["packageId"];
		$checked = ((int)$r["enabled"] === 1) ? " checked" : "";
		$demo = ((int)$r["isDemo"] === 1) ? "Demo" : "Live";
		$form = '<form method="post" action="index.php?f=users_pricing" style="margin:0">'.
			'<input type="hidden" name="packageId" value="'.$id.'">'.
			'<input name="label" value="'.htmlspecialchars((string)$r["label"],ENT_QUOTES).'" size="10"> '.
			'<input name="quotaMB" type="number" min="1" value="'.(int)$r["quotaMB"].'" size="8"> MB '.
			'<input name="priceKsh" type="number" min="0" step="0.01" value="'.htmlspecialchars((string)$r["priceKsh"],ENT_QUOTES).'" size="7"> KSh '.
			'<label><input name="enabled" type="checkbox" value="1"'.$checked.'> enabled</label> '.
			'<input type="submit" value="Save">'.
			'</form>';
		$effective = ((float)$r["quotaMB"] > 0) ? ((float)$r["priceKsh"] * 1024.0 / (float)$r["quotaMB"]) : 0;
		$rows .= tr(td($form).td(htmlspecialchars($demo)).td(number_format($effective,2)." KSh/GB"));
	}
	print table(tr(th("Package").th("Mode").th("Effective price")).$rows);
	print '<p><a href="index.php?f=users_list">Back to registered users</a></p>';
}

?>
