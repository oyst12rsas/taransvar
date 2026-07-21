<?php
session_start();
$nRequiredDbVersion=72;	//NOTE! Make sure this line is always number 3 in the file because that's claimed below.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include "dbfunc.php";
//require_once "../script/getSenderIp.php";
include "../taraLib.php";

$szErrorMessage = "";
//$szErrorMessage = '<h3><font color="red">I\'ll be down for planned maintainance 1pm GMT today (Friday 5th)</font></h3><b>Let me know if it\'s inconvenient. I\'m flexible.<b><br><br>';	//Use it to print message...
//$szErrorMessage = "";//'<h3><font color="red">The Demo network will be taken down in <div id="countdown"></div></font></h3><br>';	//Use it to print message...
//$szErrorMessage = '<h3><font color="red">This gateway will be replaced by <a href="http://100.68.165.190/gatekeeper/index.php">this new one.</font></a>.</h3>You should set it as your homepage while checking the sites out.<br><br>';
$szTargetTime = "";//"2026-06-09T16:00Z";	//Zulu/GMT time... Set to "" if not relevant. Usage: taken down in <div id="countdown"></div>


//Check if db is updated otherwise the script often fails...

$conn = getConnection();
$sql = "SELECT *, coalesce(inet_ntoa(adminIP),'') as adminIPA from setup";
$result = $conn->query($sql);
$bOk = $result->num_rows > 0 && $setupRow = $result->fetch_assoc(); 

function isAdmin()
{
	//This is not necessary on every request... store in $_SESSION[] when logging in instead. 260717
/*	$szSQL = "select CAST(isAdmin AS UNSIGNED) as isAdmin from user where userId = ?";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$nUserId = isset($_SESSION["userid"])?$_SESSION["userid"]+0:0;
	$stmt->bind_param("i", $nUserId);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
	{
		$row = $result->fetch_assoc();
		if ($row)
			return ($row["isAdmin"] == "1");
		$result->free();
	}
	$conn->close();
	return false;
*/
	return isset($_SESSION["isAdmin"]) && ($_SESSION["isAdmin"] == 1);
}


function inspectionsMenu()
{
	print '<table><tr><td><a href="index.php?f=syslogThreat">SyslogThreat</a></td><td><a href="index.php?f=hackReports">hackReports</a></td></tr></table>';
}


function printTitle()
{
	global $setupRow;
	if (isset($setupRow["nickname"]) && strlen($setupRow["nickname"]))
		$szDisplay = $setupRow["nickname"];
	else
		$szDisplay = "Taransvar Gatekeeper";

	$nPos = strrpos($setupRow["adminIPA"], ".");
	$szPostfix =  substr($setupRow["adminIPA"], $nPos+1);
	print "$szDisplay ($szPostfix)";

}

//$bOk = 0;

if ($bOk)
{
	if (intval($setupRow["dbVersion"])+0 != $nRequiredDbVersion)
	{
		if ($setupRow["dbVersion"]+0 < $nRequiredDbVersion)
			print "Your database is not properly upgraded... you should run: sudo perl misc/system_diag.pl to upgrade to version $nRequiredDbVersion.";
		else
			print "Your database is newer than your script.<br>The most likely reason is that you didn't copy the files from the www directory to localhost (normally /var/www/html).<br>To avoid this message, you can also update the \$nRequiredDbVersion variable in line 3 or index.php.";
			
		print "<br><br>This script is made for version ".$nRequiredDbVersion.". Your database is version ".$setupRow["dbVersion"].". <br><br>Aborting...";
		return;
	}
}
else
{
	print "Database not properly set up... Aborting..";
	return;
}

function experiencingDbConnectionTrouble()
{
	print "<bR>DB-Error has been detected... don't know what may cause this...<bR><bR>";
}

function loggedIn()
{
	if (!isset($_SESSION["userid"]))
	{
		print "You need to be logged in to access this function.";
		return 0;		
	}
	return 1;
}

function getDemo()
{
	$conn = getConnection();

	//$sql = "SELECT *, inet_ntoa(ipTargetHost) as targetHost, inet_ntoa(ipBotHost) as botHost, inet_ntoa(ipBot) as bot from demo  ";
	
	//inet_ntoa(ipTargetHost) as ipTargetHost, inet_ntoa(ipBotHost) as ipBotHost, inet_ntoa(ipBot) as ipBot,
	$sql = "SELECT * , inet_ntoa(ipTargetHost) as targetHost, inet_ntoa(ipBotHost) as botHost, inet_ntoa(ipBot) as bot,
				unix_timestamp(now())-unix_timestamp(targetHostChecked) as secSinceTargetHostChecked, 
				unix_timestamp(now())-unix_timestamp(botHostChecked) as secSinceBotHostChecked,
				unix_timestamp(now())-unix_timestamp(botChecked) as secSinceBotChecked from demo limit 1";
	
	$result = $conn->query($sql);
	$bOk = $result->num_rows > 0 && $row = $result->fetch_assoc(); 
	$result->free();
	$conn->close();
	if ($bOk)
	{
		return $row;
	}
	else
		return 0;
}

$demoRow = getDemo();

function last_insert_id($conn)
{
	$result = $conn->query("select last_insert_id()");
	$row = $result->fetch_row();
	return $row[0];
}

function checkCronTaskRunning($privateDemoRow, $szIam)
{
	$szFld = "secSince".$szIam."Checked";
	if ($privateDemoRow[$szFld] > 100)
		print "<h3><font color=\"red\">misc/crontasks.pl should run every minute as cron task. It is ".$privateDemoRow[$szFld]." seconds since it was updated on \"".$szIam."\".</font></h3>";
}


//Decide background image if running demo
if (isset($setupRow["background"]) && strlen($setupRow["background"]))
{
      	$szBackgroundImage = $setupRow["background"].".jpeg";
}
else
        if ($demoRow)
        {
        	switch ($demoRow["iAm"])
	        {
	        	case "botHost":
	        		$szBackgroundImage = "server.jpeg";
	        		break;
	        	case "targetHost":
	        		$szBackgroundImage = "gold.jpeg";
	        		break;
	        	case "bot":
	        		$szBackgroundImage = "computer.jpeg";
	        		break;
	        	default:
	        		{
	        			/*$conn = getConnection();

	        			$sql = "SELECT adminIp from setup";
	        			$result = $conn->query($sql);
	        			$bOk = $result->num_rows > 0 && $row = $result->fetch_assoc(); 
	        			if ($bOk)*/
	        			if ($setupRow)
	        			{
	        				$row = $setupRow;	//Change usage below to $setupRow
	        				if ($row["adminIp"] == $demoRow["ipBotHost"])
	        					$szIam = "botHost";
	        				else
	        					if ($row["adminIp"] == $demoRow["ipTargetHost"])
	        						$szIam = "adminIp";
	        					else
	        						if ($row["adminIp"] == $demoRow["ipBot"])
	        							$szIam = "bot";
	        						else
	        						{
	        							$szErrorMessage = "<h2><font color=\"red\">**** ERROR **** Registered IP is not same as used in demo.. You can schedule misc/crontasks.pl to keep it updated.</font></h2>";
	        							$szIam = false;
	        						}
								
	        				if ($szIam)
	        				{
	        					$sql = "update demo set iAm = '$szIam'";
	        					$result = $conn->query($sql);
	        					//print "<br>iAm updated...<br>";
								$result->free();
	        				}
	        			}
				
	        			$szBackgroundImage = "server.jpeg";
	        		}
			
			
	        }
	}
        else
	        $szBackgroundImage = "server.jpeg";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<style>

.center {
	margin-left:auto;
	margin-right:auto;
	margin-top:auto;
	margin-bottom:auto;
}
body {
	background-image: url('<?php print $szBackgroundImage; ?>');
}

h1 {
	color: white;
  	text-align: center;
}

table {
  border:1px solid black;
	border-collapse: collapse;
  margin-top: 20px;
  margin-bottom: 20px;
  margin-right: 20px;
  margin-left: 20px;
}

td {
	color: black;
}


.menu-table {
	border: 0px solid black;
	border-collapse: collapse;
	background:#7fb5da;
  margin-top: 20px;
  margin-bottom: 20px;
  margin-right: 20px;
  margin-left: 20px;
}

.menu-table-td td {
	background:#7fb5da;
}

        td {
            border: 1px solid #7a3f3f;
            padding: 20px;
            text-align: center;
		border-collapse: collapse;
        }

.orange-text { 
    color: white; 
    font-weight: bold; 
    } 

</style>
<script>
	function pageLoader()
	{
		//if (typeof szUpdateRoutine !== 'undefined') {
		//now always init..
			initUpdater();
		//}

		<?php
		if (strlen($szErrorMessage))
		{ 
		?>
		updateCountdown();
		const timer = setInterval(updateCountdown, 1000);
		<?php			
		}
		?>
	}
</script>
<script type="text/javascript" src="std.js"></script>
<script type="text/javascript" src="lib.js"></script>
<script type="text/javascript" src="lib2.js"></script>
<script type="text/javascript" src="gatekeeper.js"></script>
<script>
var cJsonParam = new Object;
</script>
<title><?php 
	printTitle();
?></title>
</head>
<body onload="pageLoader()">
<div id="targetTime" style="display:none;"><?php print $szTargetTime; ?></div>
<table class="center"><tr><td bgcolor="#AAB396">
<?php

function requiresLogin($f)
{
	if (!isset($f))
		return true;

	return !in_array($f, array("submitLogin", 'demo','listLog','infections','traffic','about','units', 'unitsMore','tagStatus'));
}


function printMenuChoice($szFunc, $szPrint)
{
	if (!isset($_SESSION["userid"]) && requiresLogin($szFunc))	//; pointer-events: none
		print '<span style="color: #777; opacity: 0.6;"><a href="index.php?f=login">'.$szPrint.'</a></span>';
	else
		print '<a href="index.php?f='.$szFunc.'">'.$szPrint.'</a>';
}




function showMenu()
{ 
global $setupRow;
?>
<h1 style="position:relative;"><?php printTitle(); ?>
	 <div id="tagStatus" style="position:absolute; right:0; top:0; font-size:12px;" onclick="tagStatusClicked()">&nbsp;</div>
	 <div id="tagStatusExtra" style="display:none;">&nbsp</div>
</h1>
<table>
<tr>
<td bgcolor="white"><?php printMenuChoice("main", "Home"); ?></td>
<td bgcolor="white"><?php printMenuChoice("infections", "Infections"); ?></td>
<td bgcolor="white"><?php printMenuChoice("listLog", "Log"); ?></td>
<td bgcolor="white"><?php printMenuChoice("traffic", "Traffic"); ?></td>
<td bgcolor="white"><?php printMenuChoice("units", "Units"); ?></td>
<td bgcolor="white"><?php printMenuChoice("attack", "Attack"); ?></td>
<td bgcolor="white"><?php printMenuChoice("demo", "Demo"); ?></td>
<td bgcolor="white"><?php printMenuChoice("about", "About"); ?></td>
<td bgcolor="white"><?php printMenuChoice("setupMenu", "Setup menu"); ?></td>
<td bgcolor="white"><?php printMenuChoice("help", "Help"); ?></td>
</tr>
</table>
<?php
}

function setupMenu()
{ ?>
<table>
<tr>
<td bgcolor="white"><a href="index.php?f=partners">Partner</a></td>
<td bgcolor="white"><a href="index.php?f=servers">Server</a></td>
<td bgcolor="white"><a href="index.php?f=domains">Domains</a></td>
<td bgcolor="white"><a href="index.php?f=colorListings">W/B List</a></td>
<td bgcolor="white"><a href="index.php?f=inspections">Inspections</a></td>
<td bgcolor="white"><a href="index.php?f=assistance">Assistance</a></td>
<td bgcolor="white"><a href="index.php?f=honey">Honey</a></td>
<!----------------- <td bgcolor="white"><a href="index.php?f=workshops">Workshop</a></td> ------------->
<td bgcolor="white"><a href="index.php?f=setup">Setup</a></td>
</tr>
</table>
<?php
}

function getString($szSQL)
{
	$conn = getConnection();
	$result = $conn->query($szSQL);
	$string = false;

	if ($result)
	{
		if ($result->num_rows > 0) 
		{
			// output data of each row  
			print "<h2>Last requests:</h2><table>";
			if ($row = $result->fetch_row()) 
				$string = $row[0];
		} 
		$result->free();
	}

	$conn->close();
	return $string;
}

function listPartners()
{
	$conn = getConnection();

	if ($_GET["f"] == "delpartner")
	{
	        $sql = "select count(routerId) from partnerRouter where partnerId = ".$_GET["id"];
	        if (getString($sql)+0 > 0)
	        {
	                print "You need to delete routers belonging to this partner before you can delete the partner.";
	                return;
	        }
		$sql = "delete from partner where partnerId = '".$_GET["id"];
		print "SQL: $sql";
		$result = $conn->query($sql);
	}

	print '<a href="index.php?f=setDbSrv">Set as global DB server</a><br><br>';

	print '<div id="scanresult"><a href="javascript:partnerscan()">Scan for partners</a></div>';
	print '<div id="debug"></div>';	//NOTE! Script with fail without this field..
	$sql = "SELECT partnerId, name from partner order by name";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered partners:</h2><table>
			<tr><td>Id</td><td>Name</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["partnerId"]. "</td><td><a href=\"index.php?f=partner&id=".$row["partnerId"]."\">".$row["name"]."</a></td>";
	    		//print '<td><a href="index.php?f=delpartner&ip='.$row["partnerId"].'">[Delete]</a></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "<tr><td colspan=\"2\">No registrations found!<br></td></tr>";
		print "</table>";
	} 
	else 
	{
	  echo "No partners registered<br>";
	}
	$result->free();	//260717
	$conn->close();
	//print 'Supposed to list servers';

	if (isAdmin())
		print '<br><a href="index.php?f=addPartner">Add partner</a>';

	print '<br><br><a href="index.php?f=listRouters">List all routers</a>';
	
}


function getDomainName($nDomainId)
{
	$szSQL = "select domainName from domain where domainId = ".$_GET["id"];
	//print "SQL: $szSQL<br>";
	$conn = getConnection(); 
	$res = $conn->query($szSQL);
	$row = $res->fetch_assoc();
	$domain = $row["domainName"];
	$res->free();	//260717
	$conn->close();	//260717
	return $domain;
}

function updateDnsInfo()
{
}

function editDescription()
{
    if (isset($_GET["submit"]))
    {
        print "Supposed to save....<br><br>";
        $szSQL = "update unit set description = ? where unitId = ?";     
        $conn = getConnection();
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("si", $_GET["desc"], $_GET["id"]); 
	    $stmt->execute();
		$conn->close();	//260717
               	
       	//print "$szSQL<br>";
        //$result = $conn->query($szSQL);
    	print "<a href=\"index.php?f=units\">Go back to units</a>";
    	return;
	}
    ?>
    <h2>Register new description</h2>
    <form action="index.php">
    <table>
        <tr><td>New description</td><td><input name="desc"></td></tr>
        <tr><td>&nbsp;</td><td><input type="submit" name="submit"><input type="hidden" name="f" value="edtDesc"><input type="hidden" name="id" value="<?php print $_GET["id"]; ?>"></td></tr>
    </table>
	</form>
        
    <?php
}


function addAssistanceRequest()
{
	if (isset($_GET["submit"]))
	{
		if(filter_var($_GET["ip"], FILTER_VALIDATE_IP))
		{
			$nThreshold = isset($_GET["thrshld"])?intval($_GET["thrshld"]):5;
			$szSQL = "insert into assistanceRequest(ip, port, category, comment, purpose, requestQuality) values (inet_aton(?), ?, 'bruteForce', 'Added through dashboard', 'internalRequest', ?)";
			$conn = getConnection();
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("sii", $_GET["ip"],$_GET["port"], $nThreshold); //$_GET["ip"], 
	        $stmt->execute();
        	print "I think it's registered...".$_GET["ip"].":".$_GET["port"]."<br><br><a href=\"index.php?f=attack\">See list</a>";
			$conn->close();//260717
        	return;
        }
        else
        	print '<font color="red">Error in IP adderss: '.$_GET["ip"].'</font>';
	}
	?>
        <h2>New assistance request</h2>
        <form action="index.php">
        <table>
        <tr><td>IP</td><td><input name="ip"></td><td></td></tr>
        <tr><td>Port</td><td><input name="port"></td><td>Blank means all ports</td></tr>
        <tr><td>Threat threshold</td><td><input name="thrshld" value="5"></td><td>Block threats above this</td></tr>
        <tr><td>&nbsp;</td><td><input type="submit" name="submit"><input type="hidden" name="f" value="addassreq"></td></tr>
        </table>
        </form>
        NOTE! This adds assistance request for other IP to test blocking of outbound presumed malicious traffic for that IP<br>
        To send assistance request on your behalf, better modify the misc/checkload.pl script and<br>alter
        thresholds to generate a request for assistance (given that you have a global DB set up). 
        <?php
}

function getStatus($szStatus)
{
	if ($szStatus == "ok")
		return '<font color="green">So far all seems to be well</font>';
	else
		return '<font color="red">'.$szStatus.'</font>';
}

function checkPerlScriptStatus($szPrefixBigChar, $szPrefixSmallChar)
{
	//ØT241213
	global $demoRow;	
	$szSecSinceField = "secSince".ucfirst($szPrefixSmallChar)."Checked";	//Gives e.g: secSinceTargetHostChecked (column in $demoRow)
	
	if (!isset($demoRow[$szSecSinceField]) || !$demoRow[$szSecSinceField] || $demoRow[$szSecSinceField] > 100)
		print "<h3><font color=\"red\">".$szPrefixBigChar." status NOT UPDATED! ($demoRow[$szSecSinceField] seconds since). Make sure misc/crontasks.pl runs as cron task on \"".$szPrefixBigChar."\". See Gatekeeper manual or crontask.pl script heading.</font></h3>";
	else		
	{
		print "<b>".$szPrefixBigChar." status: (".$demoRow[$szPrefixSmallChar."Checked"].")</b>";
		print "<p>".getStatus($demoRow[$szPrefixSmallChar."Status"])."</p>";
	}
}

function printDemoForm($row, $szAction)
{
	$szSelectedTargetHost = $szSelectedBotHost = $szSelectedBot = "";
	
	switch($row["iAm"])	//Field type: enum('targetHost','botHost','bot')
	{
 		case "botHost":
 			$szSelectedBotHost = 'selected="selected"';
 			break;
 		case "botHost":
 			$szSelectedTargetHost = 'selected="selected"';
 			break;
 		default:
 			$szSelectedBot = 'selected="selected"';
	}

	print "<h2>Information about demo:</h2><table>
	        <form action=\"index.php\">
	        <table>
	        <tr><td>Main IP (with wifi router)</td><td><input name=\"bothostip\" value=\"".($row?$row["ipTargetHost"]:"")."\"></td></tr>
	        <tr><td>Sidekick IP (\"target-host\")</td><td><input name=\"targethostip\" value=\"".($row?$row["ipBotHost"]:"")."\"></td></tr>
	        <tr><td>Bot IP (\"interntal\")</td><td><input name=\"botip\" value=\"".($row?$row["ipBot"]:"")."\"></td></tr>
	        <tr><td>I am</td><td><select name=\"iam\"><option value=\"targetHost\" ".$szSelectedTargetHost.">Target host</option>
	        		<option value=\"botHost\"".$szSelectedBotHost.">Bot host</option><option value=\"bot\"".$szSelectedBot.">Bot</option></select></td></tr>
        <tr><td>&nbsp;</td><td><input type=\"submit\" name=\"submit\"><input type=\"hidden\" name=\"f\" value=\"".$szAction."\"></td></tr>
        </table>
        </form>";
}

		
function removeWarning()
{
	$szSQL = "update warning set handled = now() where warningId = ?";
	$conn=getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("i", $_GET["id"]);
	$stmt->execute();
	$conn->close();
	print "<h3>The message is tagged as handled.. <h3><br><br><a href=\"index.php?f=demo\">Click here to go back to demo</a>";
}


function getCloseWarningLink($nId)
{
	return "<a href=\"index.php?f=warnings&id=".$nId."\">[ok]</a>";
}

showMenu();

if (isset($_SESSION["userid"]) && isset($_GET["f"]) &&  in_array($_GET["f"], array("setup", 'servers', "partners", "domains", "colorListings", "inspections", "workshop", "honey", "listrouters","addpartner","addServer","adddomain","addColorListing","addInspection","addHoney","partner","delRouter")))
{
	setupMenu();
}

if (strlen($szErrorMessage))
	print "$szErrorMessage";

if (isAdmin())
{
	//Check if there's unhandled warnings and error messages
	$conn = getConnection();

	$sql = "select warningId, inserted, warning from warning where handled is null order by inserted desc limit 2";
	$result = $conn->query($sql);
	if ($result->num_rows == 1 && $row = $result->fetch_assoc())
		print "<font color=\"red\">WARNING: ".$row["warning"]."</font>&nbsp;&nbsp;".getCloseWarningLink($row["warningId"])."<br><br>";	
	else
		if ($result->num_rows > 1)
			print "<a href=\"index.php?f=warnings\"><font color=\"red\">There are warnings. Click here to see.</font></a><br><br>";
	$result->close();	//260717
	$conn->close();
}

if (!isset($_GET["f"]) || !strcmp($_GET["f"], ""))
	$_GET["f"] = "main";

if (!isset($_SESSION["userid"]) && requiresLogin($_GET["f"]))
{
	include "func/login.php";
	login();
	unset($_GET["f"]);
	//return;
} 

if (isset($_GET['f']))
{
	$funcFile = "func/".$_GET['f'].".php";
	if (file_exists($funcFile))
	{
		include_once $funcFile;
		$_GET['f']();
	}
	else
		switch($_GET['f'])
		{
			//case 'traffic':
			//	traffic();
			//	break;
   		case 'partners':
   		case 'delpartner':
       		listPartners();
       		break;
		case 'delserver':
		case 'servers':
			include_once "func/listServers.php";
			listServers();
			break;
		case 'infections':
			include_once "func/listInfections.php";
			listInfections();
			break;
		case 'domains':
			listDomains();
			break;
		case 'edtDesc':
	    	editDescription();
	    	break;
		case 'addassreq':	//Probably in one of the files as well
			addAssistanceRequest();
			break;
		case 'rmWarn':		//Don't know what file this is called from...
			removeWarning();
			break;
		case "setupMenu":	//Being called all the time... Leave it here.
			setupMenu();
			break;
		default:
			print 'Unknown menu choice';
		}
}

?> 
</td></tr></table>

</body>
</html>
