<?php
session_start();
error_reporting( E_ALL );
ini_set('display_errors', '1');

function getVisitorIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Can contain multiple IPs: client, proxy1, proxy2
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function listTrafficFrom($szIp, $port)
{
	$szSQL = "select inet_ntoa(ipFrom) as ipFrom, portFrom, inet_ntoa(ipTo) as ipTo, portTo, created, count from traffic where ipFrom = inet_aton(?) and portFrom = ? order by trafficId desc limit 10";
	//print "<br>$szSQL<br>";
    $conn = getConnection();
   	$stmt = $conn->prepare($szSQL);
    $stmt->bind_param("si", $szIp, $port); 
    $stmt->execute();
  	$result = $stmt->get_result(); // get the mysqli result

    if ($result->num_rows > 0) 
    {
    	// output data of each row  
        print "<h2>Last traffic from you:</h2><table>";
	    $nCount=0;
	    while($row = $result->fetch_assoc()) 
	    {
	        if (!$nCount)
            {
	        	print '<tr><th colspan="2">From</td><td colspan="2">To</td><td>Time</td><td>Count</td></tr>';
	        	print '<tr><td>Ip</td><td>port</td>';
	        	print '<td>Ip</td><td>port</td></tr>';
            }

	        print "<tr><td>".$row["ipFrom"]."</td><td>".$row["portFrom"]."</td>";
	        print "<td>".$row["ipTo"]."</td><td>".$row["portTo"]."</td>";
	        print "<td>".$row["created"]."</td><td>".$row["count"]."</td>";
	        $nCount++;
	        print "</table>";
        }

        if (!$nCount)
        {
            print '<tr><td>Something may have gone wrong.. There should be registered traffic<br>(or at least if you refresh your browser).</td></tr>';   //The request now handled may not yet be in the table...
        }
    }
}

function guestInfo()
{
    $szIp = getVisitorIP();

    $port = $_SERVER['REMOTE_PORT'];
    //$szIp = "10.10.10.10";

    if ($szIp == "::1")
    {
        print "You are connected to this router. Visit the samplebank of other router to get more valuable info.";
        return;
    }

    print "<table>";
    print "<tr><td>Public IP address</td><td>".$szIp."</td></tr>";
    $szDbFuncFile = "../gatekeeper/dbfunc.php";

    if (file_exists($szDbFuncFile))
    {
        include_once $szDbFuncFile;
        $conn = getConnection();
        $szSQL = "select P.name from partner P join partnerRouter PR on PR.partnerId = P.partnerId where PR.ip = inet_aton(?)";

    	$stmt = $conn->prepare($szSQL);
        $stmt->bind_param("s", $szIp); 
        $stmt->execute();
    	$result = $stmt->get_result(); // get the mysqli result

	    if ($result && $row = $result->fetch_assoc())
	    {
            print "<tr><td>Port number (HTTP)</td><td>".$port."</td></tr>";
            print '<tr><td>You\'re connected to</td><td>'.$row["name"].'</td></tr>';

            listTrafficFrom($szIp, $port);
        }
        else
            print '<tr><td colspan="2">Don\'t know where you\'re connected.</td></tr>';
    }
    else
        print "File doesn't exist in guestinfo......";

    print "</table>";
}

guestInfo();
?>