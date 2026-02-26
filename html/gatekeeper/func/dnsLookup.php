<?php


function updateIPsForDomain($nDomainId, $szDomain)
{
	if (!strlen($szDomain))
		$szDomain = getDomainName($nDomainId);

	print "<h1>Updating IP info for $szDomain</h1>";

	$aRecord = dns_get_record($szDomain, DNS_A);

	for ($m=0;$m<sizeof($aRecord);$m++)
	{
		$cFld = $aRecord[$m];
		$szIp = $cFld["ip"];
		if (strlen($szIp))
		{
			print "IP: ".$szIp."<br>";
			$szSQL = "insert into domainIp (domainId, ip) values ($nDomainId, inet_aton('$szIp')) on duplicate key update ip=ip";
			print $szSQL;		
			$res = getConnection()->query($szSQL);
		}	
	}
	
}



function dnsLookup()
{
	$cInfo = array(DNS_MX, DNS_ALL, DNS_SRV, DNS_AAAA,DNS_A,DNS_CNAME,DNS_HINFO,DNS_CAA,DNS_NS,DNS_PTR,DNS_SOA,DNS_TXT,DNS_NAPTR,DNS_A6);

	$nDomainId = $_GET["id"];
	$domain = getDomainName($nDomainId);

	$aRecord = dns_get_record($domain, DNS_A);
	print "<h1>A-record for : $domain</h1><br>";
	print_r($aRecord);
	print "<br>";

	updateIPsForDomain($nDomainId, $szDomain);

	for ($m=0;$m<sizeof($aRecord);$m++)
	{
		$cFld = $aRecord[$m];
		print "IP: ".$cFld["ip"]."<br>";
	}

	print "<h1>Various DNS info for: $domain</h1><br>";

	for ($n=0;$n<sizeof($cInfo);$n++)
	{
		print '<h2>'.$n.': '.$cInfo[$n].'</h2><br>';
		print_r(dns_get_record($domain, $cInfo[$n]));
		print "<br><br>";
	}
}

?>
