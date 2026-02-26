<?php


function domainInfo()
{
	$nDomainId = $_GET["id"];
	$domain = getDomainName($nDomainId);
	?>
	<table class="center">
	<tr><td>Domain</td><td><?php print $domain; ?></td></tr>
<?php
	$szSQL = "select inet_ntoa(ip) as ip from domainIp where domainId = ".$nDomainId;
	$conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		print '<tr><td colspan="2">Registered IP addresses</td></tr>';
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print '<tr><td colspan="2">'.$row["ip"]. '</td></tr>';
			$nCount++;
	  	}
		if (!$nCount)
	    		print '<tr><td colspan="2">No registered IP-addresses found! You should update</td></tr>';
	} 
	else 
	{
    		print '<tr><td colspan="2">No registered IP-addresses found! You should update</td></tr>';
 	}
	print '<tr><td colspan="2"><a href="index.php?f=dnsLookup&id='.$nDomainId.'">DNS Lookup</a></td></tr>';
	print "</table>";
	$conn->close();	

	print "</table>";
}

?>
