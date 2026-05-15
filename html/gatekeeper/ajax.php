<?php
session_start();

error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

include "genlib.php";
require_once "Basic.class.php";
include "System.class.php";
include "dbfunc.php";

/*if (file_exists("../Db.class.php"))
    include "../Db.class.php";
else
    include "Db.class.php";
*/
if (!function_exists("isAjax"))
{
    function isAjax() {return true;}
}

function reportHacking() {}

function experiencingDbConnectionTrouble()
{
	//print "<bR>DB-Error has been detected... don't know what may cause this...<bR><bR>";
	return false;
}

function getSenderIp() 
{
	if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) 
	{
    		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	} else {
    		$ip = $_SERVER['REMOTE_ADDR'];
 	}
 	return $ip;
} 


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
	$sql = "SELECT reportId, inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, h.lastSeen, h.unitId, hostname, description from hackReport h left outer join unit u on u.unitId = h.unitId where reportId > ? order by h.created asc limit 1";
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
        $cArr = array($row["lastSeen"],$row["ip"].":".$row["port"], $szWhom, $szDesc); //
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
	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description, severity, botnetId, infoSharePartner, infoSharePartners from internalInfections I left outer join unit u on u.unitId = I.unitId order by I.lastSeen desc";

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
        $cArr = array($row["lastSeen"],$row["ip"],$row["nettmask"],"&nbsp;","&nbsp;",$szActivateLinks, $row["severity"], $row["botnetId"], $row["infoSharePartner"], $row["infoSharePartners"], $szEditLink); //
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
	//$sql = "select U.unitId, UP.created, mac, vci, inet_ntoa(UP.ipAddress), inet_ntoa(U.ipAddress), hostname from unitPort UP join unit U on U.unitId = UP.unitId where created > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by U.unitId, UP.created desc;";

#	$sql = "select U.unitId, description, greatest(discovered, lastSeen) as discovered, hex(mac) as mac, vci, inet_ntoa(S.ip) as ip, inet_ntoa(U.ipAddress), hostname, hex(dhcpClientId) as dhcpClientId from dhcpSession S join unit U on clientId = unitId where discovered > DATE_SUB( NOW() , INTERVAL 1 DAY ) or lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by greatest(discovered, lastSeen) desc;";
	$sql = "select coalesce(unitId,'') as unitId, coalesce(description, '') as description, coalesce(lastSeen,'') as lastSeen, coalesce(hex(mac),'') as mac, coalesce(vci,'')as vci, coalesce(inet_ntoa(ipAddress),'') as ip, coalesce(hostname,'') as hostname, hex(dhcpClientId) as dhcpClientId from unit where  lastSeen is null or lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by lastSeen desc;";

	$conn = getConnection();
	$result = $conn->query($sql);
    $nCount = 0;
    $szRowId = "uninitialized";

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		while($row = $result->fetch_assoc()) 
		{
			$szM = (isset($row["mac"])?$row["mac"]:"");
		    $szMac = (strlen($szM) > 12 && substr($szM, 12) == "00000000000000000000" ? substr($szM, 0,12) : $szM);
		    $szDescription = $row["description"].'<a href="index.php?f=edtDesc&id='.$row["unitId"].'">[Edit]</a>';
		       
		    //Assemble list of last ports used..
            $szPorts = '<a href="index.php?f=showPorts&id='.$row["unitId"].'">[Show]</a>';
		       
	    	//print "<tr><td>".$row["hostname"]."</td><td>".$row["dhcpClientId"]."</td><td>".$row["vci"]."</td><td>".$szDescription."</td><td>".$szMac."</td><td>".$row["lastSeen"]."</td><td>".$row["ip"]."</td><td>".$szPorts."</td></tr>";
			$nCount++;

            $szRowId = "un".$row["unitId"];
            $cArr = array($row["hostname"], $row["dhcpClientId"], $row["vci"], $szDescription, $szMac, $row["lastSeen"],$row["ip"],$szPorts);
            CXmlCommand::addTableRow("unitsTbl", "top", $szRowId, $cArr, "", $szRowId);//$szHTML)
	  	}
	} 

    if (!$nCount)
        CXmlCommand::setInnerHTML("updateTime", "", "No DHCP IP assignments registered. You should make sure misc/crontasks.pl<br>is registered with cron. See the script file for instructions.<br>");//, $cMoreParamsArr = array())
    else
        CXmlCommand::setInnerHTML("updateTime", "", "$nCount records read");//, $cMoreParamsArr = array())
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
