
<?php
session_start();
error_reporting( E_ALL );
ini_set('display_errors', '1');

include "../script/tagged.php";

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
        //NOTE! THE NEW FUNCTION 
//        print "IP: $szIp";
        //$szSQL = "select inet_ntoa(T.ipFrom) as aIpFrom, inet_ntoa(T.ipTo) as aIpTo, CAST(isLan AS UNSIGNED) as isLan, name, created, lastSeen, count, tag from traffic T join whoIs W on W.whoIsId = T.whoIsId where T.ipFrom = inet_aton(?) order by lastSeen desc limit 10";
        $szSQL = "select inet_ntoa(T.ipFrom) as aIpFrom, inet_ntoa(T.ipTo) as aIpTo, T.whoIsId, name, created, lastSeen, cast(isLan AS UNSIGNED) as isLan, count, tag from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId where T.ipFrom = inet_aton(?) order by lastSeen desc limit 10";

        $conn = getConnection();
    	$stmt = $conn->prepare($szSQL);
        //$stmt->bind_param("si", $szIp, $port); 
        $stmt->bind_param("s", $szIp); 
        $stmt->execute();
    	$result = $stmt->get_result(); // get the mysqli result
        $nCount = 0;

	    while ($result && $row = $result->fetch_assoc())
	    {
            if (!$nCount)
                print "<table><tr><th>Time(changed)</th><th>Via (ISP?)</th><th>Count</th><th>Tag</th></tr>";

            $nCount++;
            $szTime = $row["created"]." - ".($row["lastSeen"]?substr($row["lastSeen"],11):"&nbsp;");
            //$szTime = $row["created"]." - (changed) ".$row["lastSeen"];
            $szWho = $row["isLan"]?($row["whoIsId"]?$row["name"]:"&nbsp;"):"";

            //print '<tr><td>'.$szTime.'</td><td>'.$row["whoIsId"].'</td><td>'.$row["count"].'</td><td>'.$row["tag"].'</td></tr>';
            print '<tr><td>'.$szTime.'</td><td>'.$szWho.'</td><td>'.$row["count"].'</td><td>'.$row["tag"].'</td></tr>';

        }

        if ($nCount)
            print "</table>";
        else
            print "No traffic found.. Wait a few seconds and refresh.. Or maybe your request is being blocked?..";

}


function guestInfo()
{
    $szIp = getVisitorIP();

    $port = $_SERVER['REMOTE_PORT'];
    //$szIp = "10.10.10.10";

    if ($szIp == "::1")
    {
        print "<br><br>You are using this computer. Connect a unit through wifi (if available) or visit the samplebank of other router to get more valuable info.";
        return;
    }

    $szDbFuncFile = "../gatekeeper/dbfunc.php";
    //if (!file_exists($szDbFuncFile))
    include_once $szDbFuncFile;
    $conn = getConnection();

    print "<table>";
    print "<tr><td>Public IP address</td><td>".$szIp."</td></tr>";
    $szTag = getTheTag($szIp, $port);   //See "../script/tagged.php";
    print "<tr><td>Tag</td><td>".$szTag."</td></tr>";

    $szSQL = "select P.name from partner P join partnerRouter PR on PR.partnerId = P.partnerId where PR.ip = inet_aton(?)";


    $stmt = $conn->prepare($szSQL);
    $stmt->bind_param("s", $szIp); 
    $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result

	if ($result && $row = $result->fetch_assoc())
    {
        print "<tr><td>Port number (HTTP)</td><td>".$port."</td></tr>";
        print '<tr><td>You\'re connected to</td><td>'.$row["name"].'</td></tr>';
    }
    else
        print '<tr><td colspan="2">Don\'t know where you\'re connected.</td></tr>';

    print '<tr><td colspan="2">Latest traffic from you:</td></tr>';

    print '<tr><td colspan="2">';
    listTrafficFrom($szIp, $port);
    print "</td></tr>";
    print "</table>";
}

?>