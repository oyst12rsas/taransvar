<?php
session_start();
$nRequiredDbVersion=49;	#NOTE! Make sure this line is always number 3 because that's claimed below.
include "dbfunc.php";

$szErrorMessage = "";	//Use it to print message...

//Check if db is updated otherwise the script often fails...

$conn = getConnection();
$sql = "SELECT *, inet_ntoa(adminIP) as adminIPA from setup";
$result = $conn->query($sql);
$bOk = $result->num_rows > 0 && $setupRow = $result->fetch_assoc(); 

//$bOk = 0;

if ($bOk)
{
	print "ok";

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

function loggedIn()
{
	if (!isset($_SESSION["userid"]))
	{
		print "You need to be logged in to access this function.";
		return 0;		
	}
	return 1;
}

function login()
{
	?>
	<form action="index.php">
	<table>
		<tr><td>Email:</td><td><input name="email"></td></tr>
		<tr><td>Password:</td><td><input type="password" name="pass"></td></tr>
		<tr><td colspan="2"><input type="submit" name="Submit"><input type="hidden" name="f" value="submitLogin"></td></tr> 
	<!---------------	<tr><td colspan="2"><a href="index.php?f=sendPass">Send password email</td></tr> 
		<tr><td colspan="2"><a href="index.php?f=sendPass">Send password email</td></tr> ------->
	</table>
	</form>
	<?php
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
	$conn->close();
	if ($bOk)
	{
		return $row;
	}
	else
		return 0;
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
	        							$szErrorMessage = "<font color=\"red\">**** ERROR **** Registered IP is not same as used in demo.. You can schedule misc/crontasks.pl to keep it updated.</font>";
	        							$szIam = false;
	        						}
								
	        				if ($szIam)
	        				{
	        					$sql = "update demo set iAm = '$szIam'";
	        					$result = $conn->query($sql);
	        					//print "<br>iAm updated...<br>";
	        				}
	        			}
				
	        			$szBackgroundImage = "server.jpeg";
	        		}
			
			
	        }
	}
        else
	        $szBackgroundImage = "server.jpeg";
?>
<html>
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
<head>
<title>Taransvar Gatekeeper</title>
</head>
<body>
<table class="center"><tr><td bgcolor="#AAB396">
<?php

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);




function showMenu()
{ 
global $setupRow;
?>
<h1>Taransvar Gatekeeper<?php 
$nPos = strrpos($setupRow["adminIPA"], ".");
$szDisplay =  substr($setupRow["adminIPA"], $nPos+1);
print " ($szDisplay)";
//asdfasdf
?></h1>
<table>
<tr>
<td bgcolor="white"><a href="index.php?f=infections">Infection</a></td>
<td bgcolor="white"><a href="index.php?f=listLog">Log</a></td>
<td bgcolor="white"><a href="index.php?f=traffic">Traffic</a></td>
<td bgcolor="white"><a href="index.php?f=units">Units</a></td>
<td bgcolor="white"><a href="index.php?f=attack">Attack</a></td>
<td bgcolor="white"><a href="index.php?f=demo">Demo</a></td>
<td bgcolor="white"><a href="index.php?f=about">About</a></td>
<td bgcolor="white"><a href="index.php?f=setupMenu">Setup menu</a></td>
<td bgcolor="white"><a href="index.php?f=help">Help</a></td>
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
<td bgcolor="white"><a href="index.php?f=honey">Honey</a></td>
<td bgcolor="white"><a href="index.php?f=workshops">Workshop</a></td>
<td bgcolor="white"><a href="index.php?f=setup">Setup</a></td>
</tr>
</table>
<?php
}



function getString($szSQL)
{
	$conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Last requests:</h2><table>";
		if ($row = $result->fetch_row()) 
		{
			$conn->close();
			//print "<br>getString() returned ".$row[0]."<br>";
			return $row[0];
	  	}
	} 
	return false;
	$conn->close();
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
	$conn->close();
	//print 'Supposed to list servers';
	print '<br><a href="index.php?f=addPartner">Add partner</a>';
	print '<br><br><a href="index.php?f=listRouters">List all routers</a>';
	
}


function getDomainName($nDomainId)
{
	$szSQL = "select domainName from domain where domainId = ".$_GET["id"];
	//print "SQL: $szSQL<br>";
	$res = getConnection()->query($szSQL);
	$row = $res->fetch_assoc();
	$domain = $row["domainName"];
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
        		//    echo '(invalid ip: '.$ip.' ('.$_GET["ip"].')';
	
			$szSQL = "insert into assistanceRequest(ip, port, category, comment) values (inet_aton('".$_GET["ip"]."'), ?, 'bruteForce', 'Added through dashboard')";
			$conn = getConnection();
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("i", $_GET["port"]); //$_GET["ip"], 
	                $stmt->execute();
        	        print "I think it's registered...".$_GET["ip"].":".$_GET["port"]."<br><br><a href=\"index.php?f=attack\">See list</a>";
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

function logout()
{
	unset($_SESSION["userid"]);
	print "You are logged out. <a href=\"index.php\">Log back in again</a>..";
}

function getCloseWarningLink($nId)
{
	return "<a href=\"index.php?f=warnings&id=".$nId."\">[ok]</a>";
}

showMenu();

if (isset($_SESSION["userid"]) && isset($_GET["f"]) &&  in_array($_GET["f"], array("setup", 'servers', "partners", "domains", "colorListings", "inspections", "workshop", "honey", "listrouters","addpartner","addserver","adddomain","addColorListing","addInspection","addHoney","partner","delRouter")))
{
	setupMenu();
}

print "<h2>$szErrorMessage</h2>";

//Check if there's unhandled warnings and error messages
$conn = getConnection();

$sql = "select warningId, inserted, warning from warning where handled is null order by inserted desc limit 2";
$result = $conn->query($sql);
if ($result->num_rows == 1 && $row = $result->fetch_assoc())
	print "<font color=\"red\">WARNING: ".$row["warning"]."</font>&nbsp;&nbsp;".getCloseWarningLink($row["warningId"])."<br><br>";	
else
	if ($result->num_rows > 1)
		print "<a href=\"index.php?f=warnings\"><font color=\"red\">There are warnings. Click here to see.</font></a><br><br>";

if (!isset($_SESSION["userid"]) && (!isset($_GET["f"]) || $_GET["f"] <> "submitLogin"))
{
	login();
	return;
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
	//case 'addpartner':
	//	addPartner();
	//	break;
	//case 'partner':
	 //       partner();
	 //       break;
	//case 'delPartner':
	 //       delPartner();
	 //       break;
	//case 'addrouter':
	//        addRouter();
	//        break;
	//case 'delRouter':
	 //       delRouter();
	 //       break;
	//case 'listrouters':
	//        listrouters();
	//        break;
	case 'delserver':
	case 'servers':
		include_once "func/listServers.php";
		listServers();
		break;
	case 'infections':
		include_once "func/listInfections.php";
		listInfections();
		break;
	//case 'addinfection':
	//	addInfection();
	//	break;
	//case 'delInfection':
	//	delInfection();
	//	break;
	//case 'addserver':
	//	addServer();
	//	break;
	case 'domains':
		listDomains();
		break;
	//case 'adddomain':
	//	addDomain();
	//	break;
	//case 'domainDel':
	//	domainDelete();
	//	break;
	//case 'domainInfo':
	//	domainInfo();
	//	break;
	//case 'addColorListing':
	//	addColorListing();
	//	break;
	//case 'colorListings':
	//	colorListings();
	//	break;
	//case 'inspections':
	//        inspections();
	//        break;
	//case 'addInspection':
	//        addInspection();
	//        break;
	//case 'dnsLookup':
	//	dnsLookup();
	//	break;
	//case 'honey':
	//        honey();
	//        break;
	//case 'addHoney':
	//        addHoney();
	//        break;
	//case 'delHoney':
	//        delHoney();
	//        break;
	//case 'setup':
	//	setup();
	//	break;
	//case 'listLog':
	//        listLog();
	//        break;
	//case 'units':
	//        units();
	//        break;
	case 'edtDesc':
	        editDescription();
	        break;
        //case 'showPorts':
        //        showPorts();
        //        break;
	//case 'reportHack':
	//        reportHack();
	//        break;
	//case 'attack':
	//	attack();
	//	break;
	//case 'delAttack':
	//	delAttack();
	//	break;
	case 'addassreq':	//Probably in one of the files as well
		addAssistanceRequest();
		break;
	//case 'demo':
	//	demo();
	//	break;
	//case 'addDemo':
	//	addDemo();
	//	break;
	//case 'editDemo':
	//	editDemo();
	//	break;
	//case 'activateDemo':
	//	activateDemo();
	//	break;
	//case 'demoSetup':
	//	demoSetup();
	//	break;
	//case 'about':
	//	about();
	//	break;
	//case 'help':
	//	help();
	//	break;
	case 'rmWarn':		//Don't know what file this is called from...
		removeWarning();
		break;
	//case "submitLogin":
	//	submitLogin();
	//	break;
	case "logout":	//2 lines, no use moving
		logout();
		break;
		case "setupMenu":	//Being called all the time... Leave it here.
			setupMenu();
			break;
		//case "warnings":
		//	warnings();
		//	break;
		//case "dhcpLease":
		 //       dhcpLease();
	  	//      break;
		default:
			print 'Unknown menu choice';
	}
}

?> 
</td></tr></table>

</body>
<html>
