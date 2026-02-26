<html>
<body>
<?php
//honey.php
//Run: http://81.88.18.98/honey.com

//Takes report from partners... 
//config_update.php?ip=<hexip>:<port>&f=hack


//Put this directly into database and process later... e.g in 10 minutes when dhsp leases and conntrack is loaded.... 
//


function getSenderIp()
{
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
    return $ip;
 }
} 

$ip = getSenderIp();
 
 $nPort = $_SERVER['REMOTE_PORT'];
 
 print "<br>Your ip is: ".$ip.", port: ".$nPort."<br><br>";
 //print "Server is: ".$_SERVER['SERVER_ADDR']."<br>";
 print "<br><font color=\"red\">And by the way... This is a honeypot script, <br>So now you should be tagged as hacker in the network.</font><br><br>";
print "One way to untag yourself may be to visit one of our routers within one hour from the same ip/port and request untagging...<br><br>"; 

//$cPartnerRouters = array("102.212.245.245", "81.88.18.98");

//We currently don't have DB server with public IP address. When we have, it should be put here.
$cPartnerRouters = array();//array("81.88.18.98");
//exit;
//If running locally, also treat this host as a partner (router) for notification of infections 
//(later, such messages should only be sent to central DB + the owner of the IP) 


if (!in_array($_SERVER['SERVER_ADDR'], $cPartnerRouters))    
        array_push($cPartnerRouters, $_SERVER['SERVER_ADDR']);
$nCount = 1;
foreach($cPartnerRouters as $szPartner)
{
	$szSubdir = ($szPartner == "81.88.18.98"?"/dashboard":"");
		
	$szUrl = "http://".$szPartner.$szSubdir."/config_update.php?f=report&ip=".$ip."&port=".$nPort;
        print "Calling: $szUrl<br><br>";
        $szReply = file_get_contents($szUrl);
        if (!$nCount++) {
        	print "update ";
        }	
        
        print $szReply."... ";
        //print "<h1>".$szPartner."</h1>".$szHtml;
}

print "<br>Welcome back!";


?>
</body>
</html>

