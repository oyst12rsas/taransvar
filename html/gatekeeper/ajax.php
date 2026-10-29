<?php
session_start();

error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

include "genlib.php";
require_once "Basic.class.php";
include "System.class.php";
include "dbfunc.php";
require_once "../taraLib.php";

/*if (file_exists("../Db.class.php"))
    include "../Db.class.php";
else
    include "Db.class.php";
*/
if (!function_exists("isAjax"))
{
    function isAjax() {return true;}
}

// taraLib.php provides reportHacking().

function experiencingDbConnectionTrouble()
{
	//print "<bR>DB-Error has been detected... don't know what may cause this...<bR><bR>";
	return false;
}

// taraLib.php provides the canonical, non-spoofable getSenderIp().


function myId() 
{
    $nMyId = (isset($_SESSION['sess_adm_userid'])?$_SESSION['sess_adm_userid']:0)+0;

    if (!$nMyId)
    {

        if (isset($_SESSION["user"]))
        {
            $szUsername = $_SESSION["user"];

            if (strlen($szUsername))
            {
                $cFlds = array(":uname" => $szUsername);
                $nMyId = CDb::getString("select UserId from Users where Username = :uname", $cFlds);
            }
        }
    }


    return $nMyId;
}


if (!isset($pSystem))   //200327
    $pSystem = new CSystem;

if (!function_exists("getSystem"))
{
    function getSystem()
    {   
	    global $pGlobalSystem;
	    if (!$pGlobalSystem)
		    $pGlobalSystem = new CSystem;
	    return $pGlobalSystem; 
    }
}


include "XmlCommand.class.php";
include "gate_lib.php";

function partnerScan()
{
    //Turn off error reporting to avoid timeout message as 404
 //   error_reporting(0);
 //   ini_set('display_errors', '0');
 //   ini_set('display_startup_errors', 0); 

    //print "Doing partner scan";
    //CXmlCommand::alert("Doing it now..");
    $szMsg = "";

    //$server_ip = $_SERVER['SERVER_ADDR'];
    //$szMsg =  "The IP address of the server is: " . $server_ip."<br>";
    //$ipv4 = gethostbyname(gethostname());
    //$szMsg .= "IPv4: ".$ipv4;
    $szIpv4 = lan_ipv4();

    $ipv4 = ($szIpv4 ?? 'No LAN IPv4 found');
    $szMsg .= "My IP address: ".$szIpv4."<br>";

    $nLastDot = strrpos($szIpv4, ".", );
    if (!$nLastDot)
    {
        CXmlCommand::setInnerHTML("scanresult", "", "Unable to find IP address: ".$szIpv4);  //, $cMoreParamsArr = array()
        return;
    }

    //Clear the parter register
    $conn = getConnection();
    $szSQL = "delete from partnerRouter";     
    $stmt = $conn->prepare($szSQL);
	$stmt->execute();
    $szSQL = "delete from partner";     
    $stmt = $conn->prepare($szSQL);
	$stmt->execute();

    //Scan all IP addresses in same range and send message..
    $nStart= 2;
    //$nStart = 15;
    $nEnd = 200;
    $cReplies = array();
    for ($n = $nStart; $n<=$nEnd; $n++)
    {

        $szTryIp = substr($szIpv4, 0, $nLastDot).".".$n;

        if ($szIpv4 == $szTryIp)
        {
            $szMsg .= "$current_timestamp - $szIpv4 - Skipping this computer<br>";
        }
        else
        {
            $url = "http://".$szTryIp."/gatekeeper/partnerscan.php";

            //$szReply = file_get_contents($url);
            $szReply = safe_file_get_contents($url);

            $current_timestamp = time();

            $context = stream_context_create([
                    'http' => [
                    'timeout' => 1,   // seconds
                    ]
                ]);

            $szReply = @file_get_contents($url, false, $context);
            //$szMsg .= print $szReply;

            if ($szReply !== false) {
                $szMsg .= "$current_timestamp - $url - $szReply<br>";
                $cReply = json_decode($szReply, 1);

                if (!$cReply)
                    $szMsg .= "Unable to decode reply: $szReply<br>";
                else
                {
                    $cReply["ip"] = $szTryIp;
                    savePartner($cReply["name"], $cReply["ip"]);

                    //Store a list of partners and send the full list to all after completion
                    $cReplies[] = $cReply;
                }

            }
            //else
            //    $szReply = "Request failed or timed out";
        }

        //$szMsg .= "$current_timestamp - trying: $szTryIp - $szReply<br>";
    }

    $szMsg .= "<br>Partner list: ".json_encode($cReplies);

    foreach ($cReplies as $cReply)
    {
        $url = "http://".$cReply["ip"]."/gatekeeper/partnerscan.php?res=".urlencode(json_encode($cReplies));
        $szMsg .= "<br>Sending ".$url."<br>";
        $szReply = safe_file_get_contents($url);
        if ($szReply === false)
            $szMsg .= "<br>** ERROR ** returned false";
        else
            $szMsg .= "<br>Reply: $szReply";
    }
    $szMsg .="<br>All partners notified";

    CXmlCommand::setInnerHTML("scanresult", "", $szMsg);  //, $cMoreParamsArr = array()
}

function hackReport()
{
    $nLastId = $_GET["id"];
    //CXmlCommand::alert("ID seen: $nLastId");

    //Add new hackReport records to table.    
	$sql = "SELECT reportId, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, h.lastSeen, h.unitId, hostname, description, severity, why from hackReport h left outer join unit u on u.unitId = h.unitId where reportId > ? order by h.created asc limit 1";
    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $nLastId); 
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {  

		if ($row["unitId"])
        	$szWhom = ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
		else
			$szWhom = "ISP: ".$row["partnerIp"];

        $szDesc = $row["status"];
        $cArr = array($row["lastSeen"],$row["ip"].":".$row["port"], $szWhom, $row["severity"], $szDesc, $row["why"]); //
        $szRowId = "hr".$row["reportId"];
        CXmlCommand::addTableRow("hackReportTbl", "top", "", $cArr, "", $szRowId);//$szHTML)
    }

}


$szIncFile = "include_getActivateInfectionLinks.php";

if (file_exists($szIncFile))
    include $szIncFile;
else
    if (file_exists("func/".$szIncFile))
        include "func/".$szIncFile;


function internalInfections()
{
    //Update internalInfections table...
	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description, severity, botnetId, infoSharePartner, infoSharePartners from internalInfections I left outer join unit u on u.unitId = I.unitId order by coalesce(I.lastSeen, inserted)";

    $conn = getConnection();
    $stmt = $conn->prepare($sql);
    //$stmt->bind_param("i", $nLastId); 
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {    
        $szDesc = "-".$row["description"]."-";
        
		$szActivateLinks = getActivateInfectionLinks($row); //($row["active"])?"Active":"Disabled"
        //$szEditLink = '<img src="../img/edit.png" alt="Edit info" onclick="editInfection('.$row["infectionId"].')" style="cursor:pointer;">';
        $szEditLink = '<a href="index.php?f=editInfection&id='.$row["infectionId"].'"> <img src="../img/edit.png" alt="Edit infection info" style="cursor:pointer;"></a>';
        $cArr = array($row["lastSeen"],$row["ip"],$row["nettmask"],$szActivateLinks, $row["severity"], $row["botnetId"], $row["infoSharePartner"], $row["infoSharePartners"], $szEditLink); //
        $szRowId = "inf".$row["infectionId"];
        CXmlCommand::addTableRow("infectionsTbl", "top", $szRowId, $cArr, "", $szRowId);//$szHTML)
    }
}


function traffic()
{
    $nLastId = $_GET["id"];
	$conn = getConnection();
	$sql = "SELECT trafficId, inet_ntoa(T.ipFrom) as ipFrom, inet_ntoa(T.ipTo) as ipTo, T.whoIsId, CAST(isLan AS UNSIGNED) as isLan, name, portFrom, portTo, created, lastSeen, count, tag from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId where trafficId > ? order by trafficId asc limit 50";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $nLastId); 
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {    
        $szName = ($row["isLan"] ? '<font color="gray">LAN traffic</font>' : ($row["name"]?$row["name"]:""));
        $cArr = array($row["ipFrom"].":".$row["portFrom"],$szName, $row["lastSeen"], $row["count"], $row["tag"]); //

        $szRowId = "tr".$row["trafficId"];
        CXmlCommand::addTableRow("trafficTbl", "top", "", $cArr, "", $szRowId);//$szHTML)
    }


}

function units()
{
    include "ajax/units_partners.php";
    units_partners();
    activeUnits();
}

function activeUnits()
{
    // Only known local units qualify. Recent source traffic and NAT assignments
    // provide activity even when DHCP/unit.lastSeen has not been refreshed.
    $sql = "SELECT U.unitId, COALESCE(U.description, '') AS description,
        A.lastSeen, COALESCE(HEX(U.mac), '') AS mac, COALESCE(U.vci, '') AS vci,
        COALESCE(INET_NTOA(U.ipAddress), '') AS ip, COALESCE(U.hostname, '') AS hostname,
        COALESCE(HEX(U.dhcpClientId), '') AS dhcpClientId
        FROM unit U JOIN (
            SELECT unitId, MAX(seen) AS lastSeen FROM (
                SELECT unitId, lastSeen AS seen FROM unit
                WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                UNION ALL
                SELECT U.unitId, T.seen FROM unit U JOIN (
                    SELECT ipFrom, lastSeen AS seen FROM traffic
                    WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    UNION ALL
                    SELECT ipFrom, created AS seen FROM traffic
                    WHERE lastSeen IS NULL AND created >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ) T ON T.ipFrom = U.ipAddress
                UNION ALL
                SELECT unitId, created AS seen FROM unitPort
                WHERE created >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ) observations GROUP BY unitId
        ) A ON A.unitId = U.unitId
        ORDER BY A.lastSeen DESC, U.unitId DESC LIMIT 100";
    $conn = getConnection();
    $result = $conn->query($sql);
    if (!$result) {
        CXmlCommand::setInnerHTML("updateTime", "", "Unable to read active units. Please check the server logs.");
        return;
    }
    // Replace the snapshot so clients which age out also disappear.
    $html = '<tr><th>Hostname</th><th>DHCP Client ID</th><th>Vendor</th><th>Nickname</th><th>Mac</th><th>Last seen</th><th>Last IP</th><th>Ports</th></tr>';
    $escape = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['unitId'];
        $mac = $row['mac'];
        if (strlen($mac) > 12 && substr($mac, 12) === '00000000000000000000') $mac = substr($mac, 0, 12);
        $cells = [
            $escape($row['hostname']), $escape($row['dhcpClientId']), $escape($row['vci']),
            $escape($row['description']).'<a href="index.php?f=edtDesc&amp;id='.$id.'">[Edit]</a>',
            $escape($mac), $escape($row['lastSeen']), $escape($row['ip']),
            '<a href="index.php?f=showPorts&amp;id='.$id.'">[Show]</a>'
        ];
        $html .= '<tr id="un'.$id.'"><td>'.implode('</td><td>', $cells).'</td></tr>';
        $count++;
    }
    $result->free();
    CXmlCommand::setInnerHTML("unitsTbl", "", $html);
    CXmlCommand::setInnerHTML("updateTime", "", $count
        ? $count." units observed in the last 15 minutes; updated ".date('Y-m-d H:i:s')
        : "No units observed in the last 15 minutes. DHCP details may be unavailable for routed WireGuard clients.");
}


$szFile = "ajax/".$_GET["func"].".php";

if (file_exists($szFile))
{
    require_once($szFile);
    //print "Calling the func..";
    $_GET["func"]();
}
else
    switch ($_GET["func"])
    {
        case "partnerscan":
            partnerScan();
            break;
        case "hackReport":
            //CXmlCommand::alert("Don't know how yet...");
            hackReport();
            internalInfections();
            break;
        case "traffic":
            traffic();
            break;
        case "units":
            units();
            //CXmlCommand::setInnerHTML("updateTime", "", "Updated from Ajax..");//, $cMoreParamsArr = array())
            break;
        default:
            print "unknown function : ".$_GET["func"];

    }

CXmlCommand::flushXml();

?>
