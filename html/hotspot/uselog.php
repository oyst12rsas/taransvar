<?php

//107	185.26.182.93	vip01.ams.lb.opera.technology.	2018-05-01 22:05:00	3144	9368

require_once "radiuslib.php";


function uselogMenu()
{
	if (!isSupervisor())
		return;

	$fName = "f_".request("f");

	if (function_exists($fName))
	{
		$fName();
		return;
	}

	print h1("Usage Logs");

	print "Select from left side menu";

}

function f_uselog_last()
{
	if (!isSupervisor())
		return;

	$pDb = new CDb;
	
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select internal_ip, ip, domain, mb_in, mb_out, reg_time, status, Organization, from_ip, to_ip, NetName, from_ip_bin from log_contact c join log_resolv r on r.ip_id = external_ip 
				left outer join log_arin a on from_ip_bin = arin_from_ip_bin
				order by reg_time desc limit 100", $cFlds))
	{
		if (!strlen($szDomain = $cFetched["domain"]))
			$szDomain = "Status: ".$cFetched["status"];

		if ($cFetched["from_ip_bin"]+0)
			$szLink = a((strlen($szOrg=$cFetched["Organization"])?$szOrg:"[Show]"), "index.php?f=uselog_showarin&from=".$cFetched["from_ip_bin"]);
		else
			$szLink = "N/A";
		$szRows .= tr(td($cFetched["internal_ip"]).td($cFetched["ip"]).td($szDomain).td($cFetched["reg_time"]).td($cFetched["mb_in"]).td($cFetched["mb_out"]).td($szLink));
	}
	
	print table($szRows);
	
}


function f_uselog_arin()
{
	$pDb = new CDb;
	
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select from_ip, from_ip_bin, to_ip, to_ip_bin, Organization, CIDR, NetName, NetHandle from log_arin order by from_ip_bin limit 200", $cFlds))
	{
		$szURL = "index.php?f=uselog_showarin&from=".$cFetched["from_ip_bin"]."&to=".$cFetched["to_ip_bin"];
		$szRows .= tr(td('<a href="'.$szURL.'">'.$cFetched["from_ip"].'</a>').td($cFetched["to_ip"]).td($cFetched["Organization"]).td($cFetched["CIDR"]).td($cFetched["NetName"]).td($cFetched["NetHandle"]));
	}
	
	print table($szRows);
}

function f_uselog_showarin()
{
	if (!isSupervisor())
		return;

	print "About to show the arin....";

	$pDb = new CDb;
	
	//asdfasdf... this may be blank...
	$szToIpBin = request("to");
	
	if ($szToIpBin == "")
	{
		$cFlds = array(":from" => request("from"));
		$szToIpBin = CDb::getString("select min(to_ip_bin) from log_arin where from_ip_bin = :from", $cFlds);
		print "Found to IP: $szToIpBin<br>";
	}
	
	$cFlds = array(":from" => request("from"), ":to" => $szToIpBin);
	$szRows = "";
	if (!$cFetched = $pDb->fetchNext("select from_ip, from_ip_bin, to_ip, to_ip_bin, Organization, CIDR, NetName, NetHandle from log_arin where from_ip_bin = :from and to_ip_bin = :to", $cFlds))
	{
		print red("Arin record not found! Aborting!");
		return;
	}

	$szRows .= tr(td("From IP:").td($cFetched["from_ip"])).
			tr(td("To IP:").td($cFetched["to_ip"])).
			tr(td("Org:").td($cFetched["Organization"])).
			tr(td("CDIR:").td($cFetched["CIDR"])).
			tr(td("NetName:").td($cFetched["NetName"])).
			tr(td("NetHandle:").td($cFetched["NetHandle"]));

	//Check if this is sub range to other range.. 

	$pDb = new CDb;
	
	$szSuperRows = "";
	$szSuperRangeFromIP = "";
	$szSuperRangeToIP = "";
	
	while ($cFetchedSuper = $pDb->fetchNext("select from_ip, from_ip_bin, to_ip, to_ip_bin, Organization, CIDR, NetName, NetHandle from log_arin where from_ip_bin <= :from and to_ip_bin >= :to order by to_ip_bin - from_ip_bin", $cFlds))
	{
		//$szRows .= tr(td("Found: ".$cFetchedSuper["from_ip"]." -> ".$cFetchedSuper["to_ip"],2));
		$szRows .= tr(td("This is sub IP range of this: ",2));
	
		if ($cFetchedSuper["from_ip_bin"] < $cFetched["from_ip_bin"] || $cFetchedSuper["to_ip_bin"] > $cFetched["to_ip_bin"])
		{
			$szSuperRows .= tr(td($cFetchedSuper["from_ip"]).td($cFetchedSuper["to_ip"]).td($cFetchedSuper["Organization"]).td($cFetchedSuper["CIDR"]).td($cFetchedSuper["NetName"]).td($cFetchedSuper["NetHandle"]));
		}
	
		//NOTE! ordered by to_ip_bin - from_ip_bin to make sure the super range comes last so these will keep the biggest range found...
		$szSuperRangeFromIP = $cFetchedSuper["from_ip_bin"];
		$szSuperRangeToIP = $cFetchedSuper["to_ip_bin"];
	}
	
	$szRows .= tr(td(table($szSuperRows),2));	

	//Find other sub ranges of the same super range...
	

	$pDb = new CDb;
	
	$szSubRows = "";

	$cFlds = array(":from" => $szSuperRangeFromIP, ":to" => $szSuperRangeToIP);

	$szRows .= tr(td("Other sub IP ranges of the same super range: ",2));
	
	$cFromIp = array();

	while ($cFetchedOtherSub = $pDb->fetchNext("select from_ip, from_ip_bin, to_ip, to_ip_bin, Organization, CIDR, NetName, NetHandle from log_arin where from_ip_bin >= :from and to_ip_bin <= :to", $cFlds))
	{
		//$szRows .= tr(td("Found: ".$cFetchedSuper["from_ip"]." -> ".$cFetchedSuper["to_ip"],2));
	
		if ($cFetchedOtherSub["from_ip_bin"] != $cFetched["from_ip_bin"] || $cFetchedOtherSub["to_ip_bin"] != $cFetched["to_ip_bin"])
		{
			$szSubRows .= tr(td($cFetchedOtherSub["from_ip"]).td($cFetchedOtherSub["to_ip"]).td($cFetchedOtherSub["Organization"]).td($cFetchedOtherSub["CIDR"]).td($cFetchedOtherSub["NetName"]).td($cFetchedOtherSub["NetHandle"]));
		}
		
		array_push($cFromIp, $cFetchedOtherSub["from_ip_bin"]);
	}



	print table($szRows.tr(td(table($szSubRows),2)));
	$szIdList = implode(",", $cFromIp);
	
	print "To delete:<br>update log_resolv set arin_from_ip = null, arin_from_ip_bin = null where arin_from_ip_bin in (".$szIdList.");<br>";
	print "delete from log_arin where from_ip_bin in (".$szIdList.");<br>";
	
	$szIdList = implode("_", $cFromIp);
	print "<be>".a("Delete arin records", "index.php?f=uselog_delarin&ip=$szIdList")." (to reread)";

}

function f_uselog_delarin()
{
	if (!isSupervisor())
		return;

	print "Not yet possible to delete here....";
}

?>
