<?php
session_start();
$nRequiredDbVersion=41;	#NOTE! Make sure this line is always number 3 because that's claimed below.
include "dbfunc.php";
//Check if db is updated otherwise the script often fails...
$conn = getConnection();

$sql = "SELECT *, inet_ntoa(adminIP) as adminIPA from setup";
$result = $conn->query($sql);
$bOk = $result->num_rows > 0 && $setupRow = $result->fetch_assoc(); 
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

$szErrorMessage = "";	//Use it to print message...

print "Here now";


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
		<tr><td colspan="2"><input type="submit" name="Submit"><input type="hidden" name="f" value="subLogin"></td></tr> 
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
<td bgcolor="white"><a href="index.php?f=log">Log</a></td>
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
<td bgcolor="white"><a href="index.php?f=workshop">Workshop</a></td>
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


function showLog()
{
	$conn = getConnection();

	$sql = "SELECT received, fromIP, toIP, protocol,action, comment FROM logEntry order by logEntryId desc limit 15";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Last requests:</h2><table>";
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["received"]. "</td><td>".$row["comment"]. "</td>";
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
	    		print "</tr>";
	  	}
	} 
	else 
	{
	  echo "0 results";
	}
	print "</table>";
	$conn->close();
}


function traffic()
{
	$conn = getConnection();
	$sql = "SELECT inet_ntoa(T.ipFrom) as ipFrom, inet_ntoa(T.ipTo) as ipTo, T.whoIsId, CAST(isLan AS UNSIGNED) as isLan, name, portFrom, portTo, created, count from traffic T left outer join whoIs W on W.whoIsId = T.whoIsId order by trafficId desc limit 50";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Traffic:</h2><table>
			<tr><th colspan=\"2\">From</td><th colspan=\"2\">Port from/to</td><td>Time</td><td>Count</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			$szName = ($row["isLan"] ? '<font color="gray">LAN traffic</font>' : $row["name"]);
	    		print "<tr><td>".$row["ipFrom"]. "</td><td>".$szName."</td><td>".$row["portFrom"]."</td><td>".$row["portTo"]."</td><td>".$row["created"]."</td><td>".$row["count"]."</td>";
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
	  echo "No traffic registered. Make sure absecurity and abmonitor are both running<br>";
	}
	$conn->close();
}


function partner()
{
	$szSQL = "select name, adminEmail, adminPhone, techEmail, techPhone from partner where partnerId = ".$_GET["id"];
	$conn = getConnection();
	$result = $conn->query($szSQL);
        $nCount =0;

	if ($result->num_rows > 0) 
	{
		if($row = $result->fetch_assoc()) 
		{
		        $szPartnerName = $row["name"];
        ?>
        <table>
                <tr><td>Name</td><td><?php print $row["name"]; ?></td></tr>
                <tr><td>Adm Email</td><td><?php print $row["adminEmail"]; ?></td></tr>
                <tr><td>Adm Phone</td><td><?php print $row["adminPhone"]; ?></td></tr>
                <tr><td>Tech Email</td><td><?php print $row["techEmail"]; ?></td></tr>
                <tr><td>Tech Phone</td><td><?php print $row["techPhone"]; ?></td></tr>
        </table>  <?php
                print "<table>";
                	//$szSQL = "select inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask from partnerRouter where partnerId = ".$_GET["id"];
                	//Intended to print the ip and nettmask hexadecimalt  $szSQL = 
                	$szSQL = "select routerId, hex(ip) as ip, inet_ntoa(ip) as aip, hex(nettmask) as nettmask from partnerRouter where partnerId = ".$_GET["id"];
	                $result = $conn->query($szSQL); 

	                if ($result->num_rows > 0) 
	                {
	                        print ('<tr><th colspan="2">Registered routers</th></tr>');
                		while ($row = $result->fetch_assoc()) 
		                {
                                        print '<tr><td>'.$row["aip"].'</td><td>'.$row["ip"].'</td><td>'.$row["nettmask"].'</td><td><a href="index.php?f=delRouter&id='.$row["routerId"].'">[Delete]</a></td></tr>';
                                        $nCount++;
                                }
                        }
                        if (!$nCount)
                                print "<tr><td>No routers found</td></tr>";
                        print "</table>";

                        if (!$nCount)
                                print '<a href="index.php?f=delPartner&id='.$_GET["id"].'">[Delete]</a><br><br>';
                                //print '<tr><td>&nbsp;</td><td><a href="index.php?f=delPartner&id='.$_GET["id"].'">Delete partner</a></td></tr>';
                        
                        print '<a href="index.php?f=addrouter&id='.$_GET["id"].'">Add router for '.$szPartnerName.'</a>'; 	
	  	}
                else
	    		print '<tr><td colspan="2">ERROR! Couldn\'t find the partner!</td></tr>';
	} 
	else 
	{
    		print '<tr><td colspan="2">ERROR! Couldn\'t find the partner!</td></tr>';
 	}
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
	print '<br><a href="index.php?f=addpartner">Add partner</a>';
	print '<br><br><a href="index.php?f=listrouters">List all routers</a>';
	
}

function addPartner()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		//$szSQL = "insert into partner(name, adminEmail, adminPhone, techEmail, techPhone) values ('".$_GET['name']."','".$_GET['amail']."','".$_GET['aphone']."','".$_GET['tmail']."','".$_GET['tphone']."')";
		//$conn->query($szSQL) or die(mysql_error());
		
		$szSQL = "insert into partner(name, adminEmail, adminPhone, techEmail, techPhone) values (?,?,?,?,?)";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("sssss", $name, $adminEmail, $adminPhone, $techEmail, $techPhone);
		$name = $_GET['name'];
                $adminEmail = $_GET['amail'];
                $adminPhone = $_GET['aphone'];
                $techEmail = $_GET['tmail'];
                $techPhone = $_GET['tphone'];
                $stmt->execute();
		
		//print "<br>$szSQL<br>";
		print "Think it's saved now.........<br><br>";
		print '<a href="index.php?f=partners">List partners</a>';
		return;
	}

?>
<form action="index.php"><table>
<tr><td>Name</td><td><input name="name"></td></tr>
<tr><td>Adm Email</td><td><input name="amail"></td></tr>
<tr><td>Adm Phone</td><td><input name="aphone"></td></tr>
<tr><td>Tech Email</td><td><input name="tmail"></td></tr>
<tr><td>Tech Phone</td><td><input name="tphone"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addpartner"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

function delPartner()
{
		$conn=getConnection();
		//$szSQL = "delete from partner where partnerId = ".$_GET["id"];
		//print "<br>$szSQL<br>";
//		$conn->query($szSQL) or die(mysql_error());
		
		$szSQL = "delete from partner where partnerId = ?";
		
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $id);
		$id = $_GET['id'];
                $stmt->execute();
		
		print "Think it's deleted now.........<br><br>";
		print '<a href="index.php?f=partners">List partners</a>';
		return;
}

function listrouters()
{
	$conn=getConnection();
	$szSQL = "select inet_ntoa(ip) as ip, partnerStatusReceived from partnerRouter";
	//print "<br>$szSQL<br>";
	$conn->query($szSQL) or die(mysql_error());
	$result = $conn->query($szSQL);

        if ($result->num_rows > 0) 
        {
        	// output data of each row  
        	print "<h2>All registered routers:</h2><table>";
	        $nCount=0;
	        while($row = $result->fetch_assoc()) 
	        {
	        	if (!$nCount)
	        		print "<tr><td>IP</td><td>Status received</td></tr>";
	                print "<tr><td>".$row["ip"]."</td><td>".$row["partnerStatusReceived"]."</td></tr>";
	                $nCount++;
	        }
	        print "</table>";
        }
}


function delRouter()
{//
		$conn=getConnection();
		$nPartnerId = getString("select partnerId from partnerRouter where routerId = ".$_GET["id"]+0);
		$szSQL = "delete from partnerRouter where routerId = ?";
		//print "<br>$szSQL<br>";
		//$conn->query($szSQL) or die(mysql_error());
		
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $nId);
		$nId = $_GET['id'];
                $stmt->execute();
		
		print "Think it's deleted now.........<br><br>";
		print '<a href="index.php?f=partner&id='.$nPartnerId.'">Back to partner</a>';
		return;
}

function addRouter()
{
	if (isset($_GET["submit"]))
	{
		if (filter_var(trim($_GET['ip']), FILTER_VALIDATE_IP) && filter_var(trim($_GET['nett']), FILTER_VALIDATE_IP)) 
		{
			$conn=getConnection();
			/*$szSQL = "insert into partnerRouter(partnerId, ip, nettmask) values (?,INET_ATON(?),INET_ATON(?))";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("iss", $nId, $szIp, $szNett);
			$nId = $_GET['id'];
			$szIp = trim($_GET['ip']);
			$szNett = trim($_GET['nett']);
	                $stmt->execute();*/
	                $nId = $_GET['id']+0;
			$szSQL = "insert into partnerRouter(partnerId, ip, nettmask) values (".$nId.",INET_ATON('".trim($_GET['ip'])."'),INET_ATON('".trim($_GET['nett'])."'))";
			//print "$szSQL<br>";
	                $result = $conn->query($szSQL);
	                if (!$result)
	                {
	                	print "***** SQL FAILED ******<br><br>";
	                }
	                else
	                {
				print "Think it's saved now.........<br><br>";
				print '<a href="index.php?f=partner&id='.$_GET['id'].'">Back to partner</a>';
				return;
			}
	        }
	        else
	        {
	        	print "Error in IP address or netmask:<br>IP: ".$_GET['ip']."<br>Nett: ".$_GET['nett']."<br>";
	        }
		
	}

?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip" value="<?php print (isset($_GET['ip'])?$_GET['ip']:"");  ?>"></td></tr>
<tr><td>Nettmask</td><td><input name="nett" value="<?php print (isset($_GET['nett'])?$_GET['nett']:"");  ?>"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addrouter"><input type="submit" name="submit" value="Submit"><input type="hidden" name="id" value="<?php print $_GET['id']; ?>"></td></tr>
</table></form>
<?php
}

function listServers()
{
	$conn = getConnection();

	if ($_GET["f"] == "delserver")
	{
		$sql = "delete from internalServers where ip = ? and port = ?";
		//print "SQL: $sql<br>";
		//$result = $conn->query($sql);
		$stmt = $conn->prepare($sql);
		$stmt->bind_param("ii", $szIp, $nPort);
		$szIp = $_GET['ip'];
		$nPort = $_GET['port'];
                $stmt->execute();
	}
	

	$sql = "SELECT inet_ntoa(ip) as ip, ip as ipn, port, publicPort, protection from internalServers order by ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered servers and required protection:</h2><table>
			<tr><td>IP</td><td>Port</td><td>Public port</td><td>Protection</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["ip"]. "</td><td>".$row["publicPort"]."</td><td>".$row["port"]."</td>";
	    		print '<td>'.$row["protection"]. "</td>";
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
	    		print '<td><a href="index.php?f=delserver&ip='.$row["ipn"].'&port='.$row["port"].'">[Delete]</a></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
		print "</table>";
	} 
	else 
	{
	  echo "No internal servers registered<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	print '<br><a href="index.php?f=addserver">Add server</a>';
}

function listInfections()
{
	$conn = getConnection();

	$sql = "SELECT infectionId, inet_ntoa(ip) as ip, inet_ntoa(nettmask) as nettmask, status, CAST(active AS UNSIGNED) as active, I.lastSeen, hostname, description from internalInfections I left outer join unit u on u.unitId = I.unitId order by I.lastSeen desc";
	$result = $conn->query($sql);

        print "<h2>Registered infections in our net:</h2>";

	if ($result) 
	{
		// output data of each row  
		print "<table>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			switch ($row["active"])
			{
				case "1":
					$szAction = "deactivate";
					$szExtraAction = '';
					$szFont = $szFontEnd = "";
					break;
				case "0":
					$szAction = "activate";
					$szExtraAction = '<a href="index.php?f=delInfection&action=delete&id='.$row["infectionId"].'">[delete]</a>';
					$szFont = '<font color="red">';
					$szFontEnd = "</font>";
					break;
			}
			$szWho = $row["hostname"].$row["description"];
			print '<tr><td>'.$row["lastSeen"].'<td>'.$szFont.$row["ip"].$szFontEnd.'</td><td>'.$szFont.$row["nettmask"].$szFontEnd.'</td><td>'.$szWho.'</td><td>'.$szFont.$row["status"].$szFontEnd.'</td><td><a href="index.php?f=delInfection&action='.$szAction.'&id='.$row["infectionId"].'">['.$szAction.']</a>'.$szExtraAction.'</td>';
			//print '<tr><td>'.$row["ip"].'</td><td>'.$row["nettmask"].'</td><td>'.$row["status"].'</td><td></td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registered infections found!<br>";
	} 
	else 
	{
		print "Error fetching data!<br>";
	}
	print "</table>";


        print "<h2>Hacking attempts reported by partners and fans:</h2>";



	$sql = "SELECT inet_ntoa(ip) as ip, port, inet_ntoa(partnerIp) as partnerIp, partnerPort, status, h.created, hostname, description from hackReport h left outer join unit u on u.unitId = h.unitId order by h.created desc";
	$result = $conn->query($sql);

	if ($result) 
	{
		// output data of each row  
		print "<h2><table>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{       
		        if ($nCount == 0)
		        {
		                print '<tr><td>Reported</td><td colspan="3">Attacker</td><td colspan="2">Reporter</td></tr>';
		        }
		        $szWhom =       ($row["description"] && strlen($row["description"])?$row["description"]:$row["hostname"]);
			print '<tr><td>'.$row["created"].'</td><td>'.$row["ip"].'</td><td>'.$row["port"].'</td><td>'.$szWhom.'</td><td>'.$row["partnerIp"].'</td><td>'.$row["partnerPort"].'</td><td>'.$row["status"].'</td>';
			//print "<td>" . $row["toIP"]. "</td><td>" . $row["protocol"]."</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No hacking attempts reported.<br>";
	} 
	else 
	{
			print "No hacking attempts reported.<br>";
	}
	print "</table>";

        print "<a href=\"index.php?f=reportHack\">Register hacking attempt</a>";


        
        $conn->close();

	print '<br><a href="index.php?f=addinfection">Add infection</a>';
}

function delInfection()
{
	if (isset($_GET["id"]))
	{
		$conn = getConnection();
		switch ($_GET["action"])
		{
			case "activate":
				$szSQL = "update internalInfections set active = b'1', handled = b'0' where infectionId = ?";
				break;
			case "deactivate":
				$szSQL = "update internalInfections set active = b'0', handled = b'0' where infectionId = ?";
				break;
			case "delete":
				//Don't let user delete Infection that is not yet handled (which would mean the infection would remain active with no way to deactivate than restart kernel)
				$szSQL = "select if (handled,1,0) as handled from internalInfections where infectionId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				if ($result = $stmt->get_result()) // get the mysqli result
					if ($row = $result->fetch_assoc())
	 					if ($row["handled"]+0 == 0)
	 					{
	 						print "You're not allowed to delete infection that is not yet sent to kernel. Please wait 10sek.";
	 						return;
	 					}
				
				$szSQL = "delete from internalInfections where infectionId = ?";
				break;
		}
	}	
	else
		print "******** ERROR in parameters...";

		$stmt = $conn->prepare($szSQL);
		if (!$stmt)
		{
			print "Error binding... aborting.";
			return;
		}
		$stmt->bind_param("i", $_GET["id"]); 
	        $stmt->execute();

       	listInfections();

}

function addInfection()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		$szSQL = "insert into internalInfections(ip,nettmask,status) values (inet_aton('".$_GET['ip']."'),inet_aton('".$_GET['mask']."'),'".$_GET['cat']."')";
		//print "<br>$szSQL<br>";
		$conn->query($szSQL) or die(mysql_error());
		print "Think it's saved now.........<br><br>";
		print '<a href="index.php?f=infections">List infections</a>';
		return;
	}

?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Nettmask</td><td><input name="mask" value="255.255.255.255"></td></tr>
<tr><td>Category</td><td><select name="cat">
<option value="firsttime">First time<option>
<option value="sporadic">Sporadic<option>
<option value="hack">Hack<option>
<option value="dos">DOS-attack<option>
<option value="hotspot">Hotspot<option>
<option value="bot">Bot<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addinfection"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

function addServer()
{
	if (isset($_GET["submit"]))
	{
		$conn=getConnection();
		$szSQL = "insert into internalServers(ip,port,publicPort, protection) values (inet_aton('".$_GET['ip']."'),'".$_GET['port']."','".$_GET['eport']."','".$_GET['protection']."') on duplicate key update protection = protection";
		//print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}

		print '<br><a href="index.php?f=servers">List servers</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Internal port</td><td><input name="port"></td></tr>
<tr><td>External port</td><td><input name="eport"></td></tr>
<tr><td>Protection</td><td><select name="protection">
<option value="clean">Clean<option>
<option value="presumed_clean">Presumed clean<option>
<option value="no_bots">No BOTs<option>
<option value="all">All<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addserver"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}



function listDomains()
{
	$conn = getConnection();

	$sql = "SELECT domainId, domainName, color, if(active,'Active','Inactive') as active from domain order by domainName";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered domains and white/blacklisting:</h2><table>
			<tr><td>DomainName</td><td>Color</td><td>Active</td><td>&nbsp;</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print '<tr><td><a href="index.php?f=domaininfo&id='.$row["domainId"].'">'.$row["domainName"]. "</a></td><td>".$row["color"]."</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print '<td><a href="index.php?f=domaindel&id='.$row["domainId"].'">[Delete]</td>';
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
        	print "</table>";
	} 
	else 
	{
	  echo "No domain found<br>";
	}
	$conn->close();
	print '<br><a href="index.php?f=adddomain">Add domain</a>';
}


function addDomain()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into domain(domainName, color, active) values ('".$_GET['dname']."','".$_GET['color']."',b'1')";
		print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}
	
		$nNewId = last_insert_id($conn);

		updateIPsForDomain($nNewId, $_GET['dname']);

		print '<br><a href="index.php?f=domains">List domains</a>';
		return;
	}
?>
<h2>Register domain for white/black listing</h2>
<form action="index.php"><table>
<tr><td>Domain</td><td><input name="dname"></td></tr>
<tr><td>Color</td><td><select name="color">
<option value="white">White<option>
<option value="black">Black<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="adddomain"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
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
	print '<tr><td colspan="2"><a href="index.php?f=dnslookup&id='.$nDomainId.'">DNS Lookup</a></td></tr>';
	print "</table>";
	$conn->close();	

	print "</table>";
}

function updateDnsInfo()
{
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

function listColorListings()
{
	$conn = getConnection();

	$sql = "SELECT ip, color, active from colorListings order by ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Registered IP white/blacklisting:</h2><table>
			<tr><td>IP</td><td>Color</td><td>Active</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["ip"]. "</td><td>".$row["color"]."</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
		print "</table>";
	} 
	else 
	{
	  echo "0 results";
	}
	$conn->close();
	print '<br><a href="index.php?f=addcolorlisting">Add IP white/blacklist</a>';
}

function inspections()
{
	$conn = getConnection();

	$sql = "SELECT hex(ip) as ip, inet_ntoa(ip) as aip, hex(nettmask) as nettmask, inet_ntoa(nettmask) as anett, handling, active from inspection order by handling, ip";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Special packet inspection handling:</h2><table>
			<tr><td colspan=\"2\">IP</td><td colspan=\"2\">Nettmask</td><td>Handling</td><td>Active</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["aip"]. "</td><td>".$row["ip"]. "</td><td>".$row["anett"]."</td><td>".$row["nettmask"]. "</td>";
	    		print "<td>".$row["handling"]. "</td>";
	    		print "<td>".$row["active"]. "</td>";
	    		print "</tr>";
			$nCount++;
	  	}
		if (!$nCount)
			print "No registrations found!<br>";
	} 
	else 
	{
	        print "<table>";
	        print "<tr><td>No inspections registered</td></tr>";
	}
	print "</table>";
	$conn->close();
	print '<br><a href="index.php?f=addinspection">Add packet inspection</a>';
}

function addInspection()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into inspection(ip, nettmask, handling, active) values (inet_aton('".$_GET['ip']."'),inet_aton('".$_GET['nett']."') ,'".$_GET['handling']."', b'1')";
		print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}

		print '<br><a href="index.php?f=inspections">List inspections</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Nettmask</td><td><input name="nett"></td></tr>
<tr><td>Handling</td><td><select name="handling">
<option value="Drop">Drop<option>
<option value="Inspect">Inspect<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addinspection"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

function addColorListing()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into colorListings(ip, color) values (inet_aton('".$_GET['ip']."'),'".$_GET['color']."')";
		print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}

		print '<br><a href="index.php?f=domains">List IP color listings</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>IP</td><td><input name="ip"></td></tr>
<tr><td>Color</td><td><select name="color">
<option value="white">White<option>
<option value="black">Black<option>
</select></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addcolorlisting"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

function domainDelete()
{
	if (isset($_GET["id"]))
	{
		$cConn = getConnection();
		$szSQL = "delete from domainIp where domainId = ".$_GET["id"];
		$cConn->query($szSQL) or die (mysql_error());
		$szSQL = "delete from domain where domainId = ".$_GET["id"];
		$cConn->query($szSQL) or die (mysql_error());
		print 'Domain should have been deleted now.<br><br><a href="index.php?f=domains">List domains</a>';
	}
	else
		print "Error deleting....";
}

function honey()
{
	$conn = getConnection();

	$sql = "select port, description, handling from honeyport";//handling: enum('block','normal','ssh','mysql','SQL-server','samba'),

	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		print "<h2>Registered honeyports:</h2><table><tr><td>port</td><td>Handling</td><td>Description</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["port"]."</td><td>".$row["handling"]."</td><td>".$row["description"]."</td>".$row["description"]."</td><td><a href=\"index.php?f=delhoney&port=".$row["port"]."\">[Delete]</h></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No honeyports registered<br>";
	}
	$conn->close();
	print '<br><a href="index.php?f=addhoney">Add honeyport</a>';
}


function addHoney()
{
	if (isset($_GET["submit"]))
	{

		$conn=getConnection();
		$szSQL = "insert into honeyport(port, handling, description) values (".$_GET['port'].",'".$_GET['handling']."','".$_GET['desc']."')";
		print "<br>SQL to run:<br>$szSQL<br><br>";

		try {
			$nRes = $conn->query($szSQL) or die (mysql_error());

		  
		} catch (Exception $e) {
			echo $e->getMessage();
			echo "---";
			echo mysql_error();
		}

		if ($nRes === TRUE)
			print "Think it's saved now......<br><br>";
		else
		{
			print "About to fetch error msg...<br>";
			$cError = "";// mysql_error();
			
			print "$cError... maybe you should run it manually for testing the rest of the system?";
		}

		print '<br><a href="index.php?f=honey">Back to honeypots</a>';
		return;
	}
?>
<form action="index.php"><table>
<tr><td>Port</td><td><input name="port"></td></tr>
<tr><td>Handling</td><td><select name="handling">
<option value="block">Block<option>
<option value="normal">Normal<option>
<option value="ssh">SSH<option>
<option value="SQL-server">SQL-server<option>
<option value="samba">Samba<option>
</select></td></tr>
<tr><td>Description</td><td><input name="desc"></td></tr>
<tr><td>&nbsp;</td><td><input name="f" type="hidden" value="addhoney"><input type="submit" name="submit" value="Submit"></td></tr>
</table><form>
<?php
}

function delHoney()
{
        print "Should save......";
        $szSQL = "delete from honeyport where port = ".$_GET["port"];
	$cConn = getConnection();
	print "<br>SQL: $szSQL<br>";
	$cConn->query($szSQL) or die (mysql_error());
	print 'Honeyport has been deleted...<br><br><a href="index.php?f=honey">Back to list...</a>';
}

function saveSetupOld()
{
	if (filter_var($_GET["ip"], FILTER_VALIDATE_IP) && filter_var($_GET["intIp"], FILTER_VALIDATE_IP) && filter_var($_GET["nett"], FILTER_VALIDATE_IP))
	{
               	print "Should save......";	
               	$szSQL = "update setup set adminIp = inet_aton('".$_GET["ip"]."'), internalIP = inet_aton('".$_GET["intIp"]."'), nettmask = inet_aton('".$_GET["nett"]."'), statusIntervalSec = ".$_GET["statusinterval"].", showStatus = b'".(isset($_GET["showstatus"])=="on"?'1':'0')."', showPreRoutePartner = b'".(isset($_GET["preroutepartner"])=="on"?'1':'0')."', showPreRouteNonPartner = b'".(isset($_GET["preroutenonpartner"])=="on"?'1':'0')."', showForwardPartner = b'".(isset($_GET["forwardpartner"])=="on"?'1':'0')."', showForwardNonPartner = b'".(isset($_GET["forwardnonpartner"])=="on"?'1':'0').
	        "', showUrgentPtrUsage = b'".(isset($_GET["urgentptr"])=="on"?'1':'0').
                "', showOwnerless = b'".(isset($_GET["ownerless"])=="on"?'1':'0').
                "', showOther = b'".(isset($_GET["showother"])=="on"?'1':'0').
                "', showNew1 = b'".(isset($_GET["shownew1"])=="on"?'1':'0').
                "', showNew2 = b'".(isset($_GET["shownew2"])=="on"?'1':'0').
                "', handled = b'0', doTagging = b'".(isset($_GET["dotag"])=="on"?'1':'0').
                "', doInspection = b'".(isset($_GET["doinspect"])=="on"?'1':'0').
                "', doBlocking = b'".(isset($_GET["doblock"])=="on"?'1':'0').
                "', doOther = b'".(isset($_GET["doother"])=="on"?'1':'0').
                "', globalDb1ip = ".(strlen($_GET["db1"])?"inet_aton('".$_GET["db1"]."')":'NULL').
                ", globalDb2ip = ".(strlen($_GET["db2"])?"inet_aton('".$_GET["db2"]."')":'NULL').
                ", globalDb3ip = ".(strlen($_GET["db3"])?"inet_aton('".$_GET["db3"]."')":'NULL').
                ";"; 
	}
                
	$cConn = getConnection();
	//print "<br>SQL: $szSQL<br>";
	$cConn->query($szSQL) or die ("Error in sql: $szSQL");//mysql_error());
}

function getUpdateSetupIp($lpField, $lpIp, $cConn)
{
	$szNewIp = (isset($_GET[$lpIp]) && filter_var($_GET[$lpIp], FILTER_VALIDATE_IP)?"inet_aton('".$_GET[$lpIp]."')":"NULL");
	$szSQL = "update setup set $lpField = $szNewIp;\n";
	$cConn->query($szSQL) or die ("Error in sql: $szSQL");//mysql_error());
}











function getUpdateIntField($lpField, $szVal, $cConn)
{
	$szNewVal = (!isset($_GET[$szVal])?"NULL":intval($_GET[$szVal])+0);
	$szSQL = "update setup set $lpField = $szNewVal;\n";
	$cConn->query($szSQL) or die ("Error in sql: $szSQL");//mysql_error());
}

function getUpdateBitField($lpField, $szVal, $cConn)
{
	$szNewVal = (isset($_GET[$szVal])?"1":"0");
	$szSQL = "update setup set $lpField = b'$szNewVal';\n";
	//print "$szSQL<br>";
	$cConn->query($szSQL) or die ("Error in sql: $szSQL");//mysql_error());
}

function updateSetupString($lpField, $szVal, $conn)
{
	$szSQL = "update setup set $lpField = ?";
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $_GET[$szVal]); 
        $stmt->execute();
}


function saveSetupNew()
{
	if (!filter_var($_GET["ip"], FILTER_VALIDATE_IP) || !filter_var($_GET["intIp"], FILTER_VALIDATE_IP) || !filter_var($_GET["nett"], FILTER_VALIDATE_IP))
	{
		print "Error in one of the IP addresses! Saving anyway<br>";
	}
		
        print "Should save......";
	$cConn = getConnection();
	getUpdateSetupIp("adminIp", 			"ip", $cConn);
	getUpdateSetupIp("internalIP", 			"intIp", $cConn);
	getUpdateSetupIp("nettmask", 			"nett", $cConn);

	getUpdateIntField("statusIntervalSec", 			"statusinterval", $cConn); 
	getUpdateIntField("blockIncomingTaggedTrafficThreshold", "tagThreshold", $cConn); 
	getUpdateIntField("workshopId", 			"ws", $cConn); 

	getUpdateBitField("showStatus", 		"showstatus", $cConn);
	getUpdateBitField("showPreRoutePartner", 	"preroutepartner", $cConn);
	getUpdateBitField("showPreRouteNonPartner", 	"preroutenonpartner", $cConn);
	getUpdateBitField("showForwardPartner", 	"forwardpartner", $cConn);
	getUpdateBitField("showForwardNonPartner", 	"forwardnonpartner", $cConn);
	getUpdateBitField("showUrgentPtrUsage", 	"urgentptr", $cConn);
	getUpdateBitField("showOwnerless", 		"ownerless", $cConn);
	getUpdateBitField("showOther", 		"showother", $cConn);
	getUpdateBitField("showNew1", 		"shownew1", $cConn);
	getUpdateBitField("showNew2", 		"shownew2", $cConn);
	getUpdateBitField("doTagging", 		"dotag", $cConn);
	getUpdateBitField("doReportTraffic", 		"doReportTraffic", $cConn);
	getUpdateBitField("doInspection", 		"doinspect", $cConn);
	getUpdateBitField("doBlocking", 		"doblock", $cConn);
	getUpdateBitField("doOther", 			"doother", $cConn);
	//$szSQL .= getUpdateBitField("", $_GET[""]);

	getUpdateSetupIp("globalDb1ip", "db1", $cConn);
	getUpdateSetupIp("globalDb2ip", "db2", $cConn);
	getUpdateSetupIp("globalDb3ip", "db3", $cConn);

	updateSetupString("background", "bkg", $cConn);

	$szSQL = "update setup set handled = b'0';"; 
	//print "<br>SQL: $szSQL<br>";
	$cConn->query($szSQL) or die ("Error in sql: $szSQL");//mysql_error());
}

function setup()
{
        if (isset($_GET["submit"]))
        {
        	//NOTE! Should verify that especilly all IP addresses are legal:  if(!filter_var($_GET["ip"], FILTER_VALIDATE_IP)){ do some error handling  }
        	
        	//saveSetupOld();
        	saveSetupNew();
		print 'Setup should have been saved..<br><br><a href="index.php?f=setup">See it..</a>';
		return;
        }
        print "<h2>Setup</h2>";
	$szSQL = "select adminIp, inet_ntoa(adminIp) as adminIpA, inet_ntoa(internalIP) as internalIP, inet_ntoa(nettmask) as nettmask, 
		statusIntervalSec, if(showStatus,1,0) as showStatus, ifnull(blockIncomingTaggedTrafficThreshold,0) as threshold, showPreRoutePartner, showPreRouteNonPartner, showForwardPartner, showForwardNonPartner, showUrgentPtrUsage, showOwnerless, showOther, showNew1, showNew2, doTagging, doReportTraffic, doInspection, doBlocking, doOther, inet_ntoa(globalDb1ip) as globalDb1ip, inet_ntoa(globalDb2ip) as globalDb2ip, inet_ntoa(globalDb3ip) as globalDb3ip, background, dbVersion, uptime, workshopId from setup";
	$conn = getConnection();
	$result = $conn->query($szSQL);
        $nCount =0;

        if($result->num_rows > 0 && $row = $result->fetch_assoc()) 
	{
	        $szPartnerName = $row["adminIpA"];
        ?>
        
                <form action="index.php">
	        <table>
	        <tr><td>
                <?php if (isset($_SESSION["userid"])) print '<a href="index.php?f=logout">Logout</a>'; else print "Not logged in"; ?>
	        <table>
                <tr><td>External IP</td><td><input name="ip" value="<?php print $row["adminIpA"]; ?>"></td></tr>
                <tr><td>Internal IP</td><td><input name="intIp" value="<?php print $row["internalIP"]; ?>"></td></tr>
                <tr><td>Nettmask</td><td><input name="nett" value="<?php print $row["nettmask"]; ?>"></td></tr>
                <tr><td>Status interval</td><td><input name="statusinterval" value="<?php print $row["statusIntervalSec"]; ?>"></td></tr>
                <tr><td>Taggin threshold *)</td><td><input name="tagThreshold" value="<?php print $row["threshold"]; ?>"></td></tr>
                <tr><td>Global DB 1</td><td><input name="db1" value="<?php print $row["globalDb1ip"]; ?>"></td></tr>
                <tr><td>Global DB 2</td><td><input name="db2" value="<?php print $row["globalDb2ip"]; ?>"></td></tr>
                <tr><td>Global DB 3</td><td><input name="db3" value="<?php print $row["globalDb3ip"]; ?>"></td></tr>
                <tr><td>Workshop ID</td><td><input name="ws" value="<?php print $row["workshopId"]; ?>"></td></tr>
                <tr><td>Background</td><td><select name="bkg">
                <?php
                 //value="<?php print $row["background"]; 
                $cOptions = array("server","computer","background","gold","raspberry","micro tower", "database");
                foreach ($cOptions as $szOption)
                {
                        $szDefault = ($szOption == $row["background"]?" selected":"");
                        print "<option $szDefault>$szOption</option>";
                }
/*                                <option>server</option>
                <option>computer</option>
                <option>background</option>
                <option>gold</option>
                <option>raspberry</option>
                <option>micro tower</option>
*/
                ?>
                </select></td></tr>
                <tr><td colspan="2">*) Traffic with severity level exceeding this will be blocked</td></tr>
                <tr><td colspan="2">&nbsp</td></tr>

                <tr><td>DB version</td><td><?php print $row["dbVersion"]; ?></td></tr>
                <tr><td>Uptime</td><td><?php print $row["uptime"]; ?></td></tr>

		</table>
		
		</td><td>

		<table>
                <tr><td>Show status</td><td><input type="checkbox" name="showstatus" <?php if ($row["showStatus"]) print "checked"; ?>></td></tr>
                <tr><td>Show pre route partner</td><td><input type="checkbox" name="preroutepartner" <?php if ($row["showPreRoutePartner"]) print "checked"; ?>></td></tr>
                <tr><td>Show pre route non partner</td><td><input type="checkbox" name="preroutenonpartner" <?php if ($row["showPreRouteNonPartner"]) print "checked"; ?>></td></tr>
                <tr><td>Show forward partner</td><td><input type="checkbox" name="forwardpartner" <?php if ($row["showForwardPartner"]) print "checked"; ?>></td></tr>
                <tr><td>Show forward non partner</td><td><input type="checkbox" name="forwardnonpartner" <?php if ($row["showForwardNonPartner"]) print "checked"; ?>></td></tr>

        <tr><td>Show urgent prt usage</td><td><input type="checkbox" name="urgentptr" <?php if ($row["showUrgentPtrUsage"]) print "checked"; ?>></td></tr>
        <tr><td>Show orphans</td><td><input type="checkbox" name="ownerless" <?php if ($row["showOwnerless"]) print "checked"; ?>></td></tr>
        <tr><td>Show other</td><td><input type="checkbox" name="showother" <?php if ($row["showOther"]) print "checked"; ?>></td></tr>
        <tr><td>Show new1</td><td><input type="checkbox" name="shownew1" <?php if ($row["showNew1"]) print "checked"; ?>></td></tr>
        <tr><td>Show new2</td><td><input type="checkbox" name="shownew2" <?php if ($row["showNew2"]) print "checked"; ?>></td></tr>

        <tr><td>Do tagging</td><td><input type="checkbox" name="dotag" <?php if ($row["doTagging"]) print "checked"; ?>></td></tr>
        <tr><td>Report traffic</td><td><input type="checkbox" name="doReportTraffic" <?php if ($row["doReportTraffic"]) print "checked"; ?>></td></tr>

	<tr><td>Do inspection</td><td><input type="checkbox" name="doinspect" <?php if ($row["doInspection"]) print "checked"; ?>></td></tr>
        <tr><td>Do blocking</td><td><input type="checkbox" name="doblock" <?php if ($row["doBlocking"]) print "checked"; ?>></td></tr>
        <tr><td>Do other</td><td><input type="checkbox" name="doother" <?php if ($row["doOther"]) print "checked"; ?>></td></tr>

		</table>
        	</td></tr>
        	<tr><td colspan="2">
        <input type="submit" name="submit"><input type="hidden" name="f" value="setup">	
	        </td></tr>
        	</table></td><td>
        	
        	</td></tr>
        	</table>
                </form>
         NOTE! Use ifconfig to find exteral and internal IP4<?php
        }
        else
                print "Error reading setup!";
}


function listLog()
{//asdf
	$conn = getConnection();
	$szSQL = "select inet_ntoa(adminIp) as ip, dmesg, unix_timestamp(now())-unix_timestamp(dmesgUpdated) as secsAgo from setup";
	$result = $conn->query($szSQL);
	$row = 0;

	if ($result && $result->num_rows > 0) 
	{
		if ($row = $result->fetch_assoc()) 
		{
			if ($row["secsAgo"]+0 > 20)
				print "<b><font color=\"red\">This content is supposed to be updated every 10 seconds but misc/crontasks.pl seems not to be set up properly</font></b>";
			else
				print "<b>NOTE! This contents was updated ".$row["secsAgo"]." seconds ago</b>. For updated content, ssh ".$row["ip"]." and run sudo dmesg -w</b>";
			$replaced = str_replace("\n","<br>",$row["dmesg"]);
			$ip = getSenderIp();
			$replaced = str_replace($ip,"<b><font color=\"red\">".$ip."</font></b>",$replaced);
			
			print '<table><tr><td><p align="left">'.$replaced."</p></td></tr></table>";
		}
	}
	if (!$row)
		print "Setup not found!";

	/*$sql = "select lineId, batchId, timeSearch, theTime, theText from dmesgLine order by lineId desc limit 100";
	
	$result = $conn->query($sql);

	if ($result && $result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Log:</h2><table><tr><td>Id</td><td>Time, Text</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["lineId"]."</td><td>".$row["theTime"]."</td><td>".$row["theText"]."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No log entries<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	print '<br><a href="index.php?f=addpartner">Add partner</a>';*/
}

function units()
{
	$conn = getConnection();
	$cLookup = getConnection();

	//$sql = "select U.unitId, UP.created, mac, vci, inet_ntoa(UP.ipAddress), inet_ntoa(U.ipAddress), hostname from unitPort UP join unit U on U.unitId = UP.unitId where created > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by U.unitId, UP.created desc;";

#	$sql = "select U.unitId, description, greatest(discovered, lastSeen) as discovered, hex(mac) as mac, vci, inet_ntoa(S.ip) as ip, inet_ntoa(U.ipAddress), hostname, hex(dhcpClientId) as dhcpClientId from dhcpSession S join unit U on clientId = unitId where discovered > DATE_SUB( NOW() , INTERVAL 1 DAY ) or lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by greatest(discovered, lastSeen) desc;";
	$sql = "select unitId, description, lastSeen, hex(mac) as mac, vci, inet_ntoa(ipAddress) as ip, hostname, hex(dhcpClientId) as dhcpClientId from unit where  lastSeen > DATE_SUB( NOW() , INTERVAL 1 DAY ) order by lastSeen desc;";

	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Active units (connected clients in sub network):</h2><table><tr><td>Hostname</td><td>DHCP Client ID</td><td>Vendor</td><td>Nickname</td><td>Mac</td><td>Last seen</td><td>Last IP</td><td>Ports</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			$szM = (isset($row["mac"])?$row["mac"]:"");
		        $szMac = (strlen($szM) > 12 && substr($szM, 12) == "00000000000000000000" ? substr($szM, 0,12) : $szM);
		        $szDescription = $row["description"].'<a href="index.php?f=edtDesc&id='.$row["unitId"].'">[Edit]</a>';
		       
		        //Assemble list of last ports used..
                        $szPorts = '<a href="index.php?f=showPorts&id='.$row["unitId"].'">[Show]</a>';
		       
	    		print "<tr><td>".$row["hostname"]."</td><td>".$row["dhcpClientId"]."</td><td>".$row["vci"]."</td><td>".$szDescription."</td><td>".$szMac."</td><td>".$row["lastSeen"]."</td><td>".$row["ip"]."</td><td>".$szPorts."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No DHCP IP assignments registered. You should make sure misc/crontasks.pl<br>is registered with cron. See the script file for instructions.<br>";
	}

        //******************************* Show prot assignments *******************************
	$sql = "select portAssignmentId, UP.created, ifnull(U.unitId,-1) as unitId, inet_ntoa(UP.ipAddress) as ip, UP.port, description, hostname, hex(dhcpClientId) as dhcpClientId, hR.created as attacked from unitPort UP left outer join unit U on U.unitId = UP.unitId left outer join hackReport hR on hR.port = UP.port and hR.created >  DATE_SUB(NOW(), INTERVAL '1' HOUR)
	order by portAssignmentId desc limit 100";
	//print "$sql<br>";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>NAT - external port assignments:</h2><table><tr><td>Unit</td><td>Time</td><td>IP</td><td>Port</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
		        if (isset($row["description"]) && strlen($row["description"])) {
        		         $szDescription = $row["description"];
		        } else {
		                if (isset($row["hostname"]) && strlen($row["hostname"])) {
        		                $szDescription = $row["hostname"];
        		        } else {
                		        if (isset($row["vci"]) && strlen($row["vci"])) {
                        		        $szDescription = $row["vci"];
                		        } else {
                		        	if (isset($row["dhcpClientId"])) {
                        		        	$szDescription = $row["dhcpClientId"];
                        		        } else {
        		         			$szDescription = ($row["unitId"]+0 == -1?'<font color="red">*** UNKNOWN ***</font>':"'*** ERROR (shouldn't happen) ***'");
        		         		}
                		        }
        		        }
		        }
		        $szAttacked = '<font color="red"><b>'.$row["attacked"].'</b></font>';
	    		print "<tr><td>".$szDescription."</td><td>".$row["created"]."</td><td>".$row["ip"]."</td><td>".$row["port"]."</td><td>".$szAttacked."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
	} 
	else 
	{
	  echo "No port assignments registered. Run misc/diagnose.pl to debug or <a href=\"index.php?f=warnings\">check error messages</a>.<br>";
	}
	$conn->close();
	//print 'Supposed to list servers';
	//print '<br><a href="index.php?f=addpartner">Add partner</a>';
	print '<a href="index.php?f=dhcpLease">See dhcp leases</a>';
}


function about()
{ ?>
<h2>About the Gatekeeper</h2>
<p>Akili Bomba cyber security solution introduces security at the core of Internet</p>
<p>This should have been done long time ago but it's not too late. With our system in place, 
hacking will no longer be sustainable because once a unit is tagged, it will no longer be ble to hack units in the secured network.</p>
<h2>About Taransvar</h2>
<p>Taransvar is a Norwegian NGO dealing with mental health and now also cyber security. We introduce ourselves as owners of global Internet security. It's our job to prove ourselves worthy.</p> 
<p><a target="new" href="http://taransvar.no/cyber.html">You can read more here</a></p>
<?php
}

function help()
{ ?>
<h2>Getting started with Taransvar Gatekeeper</h2>
<p>There is a PDF document explaining how to use the AB Gatekeeper and how to contribute to our project. We suggest you request that document if you haven't yet.</p>
<p>This database contains configuration info that will be read by the "userserver" program and then sent as a socket message to the "absecurity" kernel module once it starts.</p>
<ul>
<p>To get started, you should register:</p>
<ul>
<li>One or more "<a href="index.php?f=adddomain">Domains</a> that will be blocked by the system.</li>
<li>"<a href="index.php?f=addserver">Servers</a> in your network and required quality of clients.</li>
<li>You can also specifically <a href="index.php?f=addcolorlisting">white- or blacklists individual IP addresses</a>.</li>
</ul>
<p>
Network setup:<br>
<ul>
<li>DHCP status: sudo service isc-dhcp-server status</li>
<li>Network setup: ifconfig</li>
<li>Cron setup: sudo crontab -u root -e</li>
<li>Setup script: sudo misc/setup_network.pl</li>
<li>Diagnose: sudo perl misc/diagnose.pl</li>
</ul>

</p>

<?php
}


















function reportHack()
{
        if (isset($_GET["submit"]))
        {
                print "Should save......";
                $szSQL = "insert into hackReport (ip, port, partnerIp, partnerPort, status) values (inet_aton('".$_GET["ip"]."'), ".$_GET["port"].",inet_aton('".$_GET["partnerIp"]."'), ".$_GET["partnerPort"].",'".$_GET["status"]."')";
		$cConn = getConnection();
		print "<br>SQL: $szSQL<br>";
		$cConn->query($szSQL) or die (mysql_error());
		print 'Setup should have been saved..<br><br><a href="index.php?f=infections">See it..</a>';
		return;
        }
        print "<h2>Setup</h2>";
	$szSQL = "select adminIp, inet_ntoa(adminIp) as adminIpA from setup";
	$conn = getConnection();
	$result = $conn->query($szSQL);
        $nCount =0;

	if($result->num_rows > 0 && $row = $result->fetch_assoc()) 
	{
	        $szPartnerName = $row["adminIpA"];
        ?>
        <table>
                <form action="index.php">
                <tr><td>Hacker IP</td><td><input name="ip"></td></tr>
                <tr><td>Hacker port</td><td><input name="port"></td></tr>
                <tr><td>Partner IP</td><td><input name="partnerIp"></td></tr>
                <tr><td>Partner port</td><td><input name="partnerPort"></td></tr>
                <tr><td>Status</td><td><input name="status"></td></tr>
                <tr><td>&nbsp;</td><td><input type="submit" name="submit"><input type="hidden" name="f" value="reportHack"></td></tr>
                </form>
        </table>  <?php
        }
        else
                print "Error reading setup!";
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




function showPorts()
{
        //
        $szSQL = "select unitId, port, created from unitPort where unitId = ".$_GET["id"] ;
        $conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Port assignments:</h2><table><tr><td>Time</td><td>Port</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
	    		print "<tr><td>".$row["created"]."</td><td>".$row["port"]."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
        } 
        else
                print "No port assignments found...<br><br>";
        
        print "<a href=\"index.php?f=units\">Go back to units</a>";
}

function attack()
{
//

	$sql = "select requestId, created, inet_ntoa(ip) as ip, port, category, comment, requestQuality, wantSpoofed, active, purpose, handled from assistanceRequest order by requestId desc limit 20";
	//print "$sql<br>";
	$conn = getConnection();
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Assistance requests:</h2><table><tr><td>Created</td><td>IP:port</td><td>Category</td><td>Comment</td></tr>";
		$nCount=0;
		while($row = $result->fetch_assoc()) 
		{
			if ($row["active"]) 
			{
				$szFont = $szFontEnd = "";
				$szAction = "deactivate";
				$szExtraAction = "";
			}
			else
			{
				$szFont = "<font color=\"red\">";
				$szFontEnd = "</font>";
				$szAction = "activate";
				$szExtraAction = "<a href=\"index.php?f=delAttack&id=".$row["requestId"]."&action=delete\">[delete]</a>";
			}
			$szStatus = $row["active"];//"??";
	    		print "<tr><td>".$szFont.$row["purpose"].$szFontEnd."</td><td>".$szFont.$row["created"].$szFontEnd."</td><td>".$szFont.$row["ip"].":".$row["port"].$szFontEnd."</td><td>".$szFont.$row["category"].$szFontEnd."</td><td>".$szFont.$row["comment"].$szFontEnd."</td><td>".$szFont.$szStatus.$szFontEnd."</td><td><a href=\"index.php?f=delAttack&id=".$row["requestId"]."&action=".$szAction."\">[".$szAction."]</a>".$szExtraAction."</td></tr>";
			$nCount++;
	  	}
		print "</table>";
		print "<a href=\"index.php?f=addassreq\">Add assistance request manually</a>";
	} 


}

function delAttack()
{
	if (isset($_GET["id"]))
	{
		$conn = getConnection();
		switch ($_GET["action"])
		{
			case "deactivate":
				$szSQL = "update assistanceRequest set active = b'0', handled = NULL where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
			case "activate":
				$szSQL = "update assistanceRequest set active = b'1', handled = NULL where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
			case "delete":
				$szSQL = "delete from assistanceRequest where requestId = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("i", $_GET["id"]); 
			        $stmt->execute();
				break;
		}
        	//print "I think it's ".$_GET["action"]."d...<br><br><a href=\"index.php?f=attack\">See list</a>";
        	attack();
	}	
	else
		print "******** ERROR in parameters...";
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

function printTechnicalStatus()
{
	//First display new warnings if any...
	$conn = getConnection();
	$sql = "select warningId, warning, lastWarned from warning where handled is null";
	$result = $conn->query($sql);

	if ($result->num_rows > 0) 
	{
		// output data of each row  
		print "<h2>Unhandled warnings:</h2><table><tr><td>Discovered</td><td>Warning</td><td>&nbsp;</td></tr>";
		while($row = $result->fetch_assoc()) 
		{
			print '<tr><td>'.$row["lastWarned"].'</td><td>'.$row["warning"].'</td><td><a href="index.php?f=rmWarn&id='.$row["warningId"].'">[remove]</a></td></tr>';
		}
		print "</table>";
	}

	global $demoRow;
	//misc/crontasks.pl should run as a cron task every minute and update these fields if there's an ongoing demo...
	$szStatusFld = $demoRow["iAm"]."Status";
	print "Status field: $szStatusFld<br>"; 
	if (isset($demoRow[$szStatusFld]))
	{
		checkPerlScriptStatus("Target host", "targetHost");
		checkPerlScriptStatus("Bot host", "botHost");
		checkPerlScriptStatus("Bot", "bot");
		
		print "<p>NOTE! This information should display equally on all computers involved in the demo<br>(updating may not work yet, though)</p>";
	}
	else
		print '<font color="red">Demo status fields are not properly updated. This is most likely because misc/crontasks.pl is not set up as a cron task. See the Gatekeeper manual or contact support to fix this.</font>';
}

function getTargetHostStatus()
{
	print "<h1>Not yet learned to assemble target host status</h1>";
	print "This is the computer that is target of simulated attack...";
	//Check attack status... 
	printTechnicalStatus();
}

function getBotHostStatus()
{
	global $demoRow;
	//print "<h1>Not yet learned to assemble bot host status</h1>";
	print "This is the computer where the wifi router is connected...<br><br>";
	
	//****************************************** START OF MAIN TABLE *************************************
	print "<table><tr><td>";	//Main table with list of active units to the left and status of each computer to the right....
	//Check connected units...
	$szSQL = "select unitId, description, hostname, inet_ntoa(ipAddress) as ipAddress, lastSeen, unix_timestamp(now()) - unix_timestamp(lastSeen) as SecondsAgo from unit where lastSeen > DATE_SUB(NOW(), INTERVAL 24 HOUR) order by lastSeen desc limit 5";
	
	$conn = getConnection();
	$result = $conn->query($szSQL);

	if ($result->num_rows > 0) 
	{
		$nHours = 0;
		$bRecentDataFound = 0;
		// output data of each row  
		print "<h2>Last seen connected units:</h2><table>";
		while($row = $result->fetch_assoc()) 
		{
			$szDescription = "";
			if ($row["description"] && strlen($row["description"]) > 0)
				$szDescription = $row["description"];
			else
				if ($row["hostname"] && strlen($row["hostname"]) > 0)
					$szDescription = $row["hostname"];
					
			if (!strlen($szDescription))
				$szDescription = $row["ipAddress"];
			else
				$szDescription .= " (".$row["ipAddress"].")";
	
			if ($row["SecondsAgo"] < 120)
			{
				$szTimeAgo = $row["SecondsAgo"]." seconds ago.";
				$bRecentDataFound = 1;	//If not recent data found: Explain what may be wrong...
			}
			else
				if ($row["SecondsAgo"] < 60*60)	//Less than an hour...
				{
					$nMinutes = floor($row["SecondsAgo"]/60);
					$szTimeAgo = $nMinutes." minutes ago.";
				}
				else
				{
					$nHours = floor($row["SecondsAgo"]/(60*60));
					$szTimeAgo = $nHours." hours ago.";
				}
	    		print "<tr><td>".$szDescription. "</td><td>".$row["SecondsAgo"]. " seconds ago</td>";
	    		print "</tr>";
	  	}
	  	print "</table>";

		if (!$bRecentDataFound)
		{
			print "No recent traffic from connected units. You should first check if the unit is connected. Check your IP address there. It should normally be something like 192.168.50.100. Then try to open 192.168.50.1 in your browser. If that works, then the most likely reason is import of data on the \"bot-host\". To check, you should first open a terminal (Ctrl-Alt-T) and type:<br>&nbsp;&nbsp;&nbsp; \"sudo crontab -u root -e\"<br>";   
			print "First you need to choose editor. \"nano\" is probably the best.. Then there should be at least two lines there:<br><br>  
* * * * * sudo perl /home/<your user name>/programming/misc/crontasks.pl<br>
Those lines make sure new connected units are being important and also their usage of various ports (which is how we can track what computers are \"attacking\" other computers on Internet.<br>"; 
			
		}
			$nHours = 0;

	}
	else
		print "<h1>**** WARNING! - No active connected units found..</h1><font color=\"red\" You should open the bot computer and visit localhost there. If you have a connected phone, you should check if your IP address starts with 192.168.50 and if so visit http://192.168.50.1, which should lead to this page.</font>";

	//Check tagging of traffic
	
	//Check recent infected units in network
	$szSQL = "select infectionId, inet_ntoa(ip) as ip, inserted, I.unitId, unix_timestamp(now()) - unix_timestamp(inserted) as secondsSince, active, description, hostname from internalInfections I left outer join unit U on U.unitId = I.unitId having active = b'1' or secondsSince < 24*60*60";
	
	$conn = getConnection();
	$result = $conn->query($szSQL);
	$nFound = 0;

	if ($result->num_rows > 0) 
	{

		while($row = $result->fetch_assoc()) 
		{
			if (!$nFound)
				print "<h2>Recent or still active infections in our net:</h2><table><tr><td>IP</td><td>Inserted</td></tr>";
				
			$nFound = $nFound +1;	
			$szDesc = "";
			if ($row["description"] && strlen($row["description"]))
				$szDesc = $row["description"];
			else
				if ($row["hostname"] && strlen($row["hostname"]))
					$szDesc = $row["hostname"];
			if (!strlen($szDesc))
				$szDesc = $row["ip"];
			else
				$szDesc .= " (".$row["ip"].")";
					
			print "<tr><td>".$row["ip"]."</td><td>".$row["inserted"]."</td><td>".$szDesc."</td>";
			
			if ($row["secondsSince"] > 5*60*60)	//More than 5 hours since infection reported...
				$szRemove = '<a href="index.php?f=delInfection&action=deactivate&id='.$row["infectionId"].'">It\'s old. Remove it</a>';
			else
				$szRemove = '';
			print '<td>'.$szRemove.'</td>';
			
			print "</tr>";
		}
		if ($nFound)
			print "</table>";
	}

	if (!$nFound)
		print "<b>No recent or still active infections found in our net..<br>To register infection, use the browser on the \"bot\" (computer/phone connected to the wifi router) and click link from the demo page.</b>";

	//****************************************** MIDDLE OF MAIN TABLE *************************************
	print "</td><td>";	//End of left column (connected units and infections) and start of right one

	//Check Blocking of traffic based on partners asking for assistance
	print "Status of the other computers goes here...";
	
	print "target-host: <a href=\"http://".$demoRow["targetHost"]."\">".$demoRow["targetHost"]."</a><br><br>";
	print "Best way to find out what's going on between this computer and the target host is by checking the dmesg log. Open a terminal window with Ctrl-Alt-T and type:<br><br>sudo dmesg -w | grep -v \"^[[:space:]]*$\"<br><br>";
 	print "The last part is to avoid annoying blank lines.<br> Then go to the connected computer and open localhost there, click \"Demo\" and do as described. If it says there's no ongoing demo, then register one with the same IP addresses as here:<br>Target host: ".$demoRow["targetHost"]."<br>Bot host: ".$demoRow["botHost"]."<br>Bot: ".$demoRow["bot"]; 
	print "<br><br>You should also be able to find the bot IP address (".$demoRow["bot"].") in the tables to the left.<br>"; 
	
/*
alter table setup add networkStatusChecked timestamp null;
alter table setup add networkStatus varchar[255] null;
alter table demo add targetHostChecked timestamp null;
alter table demo add targetHostStatus varchar[255] null;
alter table demo add botHostChecked timestamp null;
alter table demo add botHostStatus varchar[255] null;
alter table demo add botChecked timestamp null;
alter table demo add botStatus varchar[255] null;
*/

	printTechnicalStatus();
	//****************************************** END OF MAIN TABLE *************************************
	print "</td></tr></table>"; //End of main table (with list of units and infections on left side)
}

function getBotStatus()
{
	global $demoRow;
	?><h1>This is supposed to run on a computer connected to wifi router, switch or other connected to a \"bot-host\"</h1>
	To check the system, you should do as follows (after it's properly set up):
	<ul>
	<li>Open the dashboard on the "bot-host" (router where you should be connected). Normally <a href="http://<?php print $demoRow["bot"]; ?>">this will be the link</a>. The background should be from server room.</li>
	<li>Open the dashboard on the "target-host" That should be <a href="http://<?php print $demoRow["targetHost"]; ?>">like this</a>. The background should be gold coins, indicating that this is where we're planning to break in.</li>
	<li>Now, as you click in the menu there, check on the "target-host" and "bot-host" that you should be able to see the packages being sent in the terminal window. If you don't or don't know how, then we suggest you read the document with the title ""  If there's too much traffic, you can set them up to show only forwarded traffic in the network. You can do that with these links:<br>
		<a href="http://<?php print $demoRow["targetHost"]; ?>/f=demoSetup">Set up the "target-host" here</a><br> 
		<a href="http://<?php print $demoRow["botHost"]; ?>/f=demoSetup">Set up the "bot-host" here</a><br> 
	</li>
	</ul>

<p>
Once it's properly set up and you see that your traffic gets routed via the "bot-host" (with the server background) to the "target-host" (with the gold coin background), you can simulate an attack from your computer/phone on the "target-host". 
</p>
<p>
To simulate an attack on "target-host": <a href="http://<?php print $demoRow["targetHost"]; ?>/honey.php">Click this link to simulate an attack</a> (or as here visiting a very simple "honeypot"-script) 
</p>
<p>
Once you've clicked the link, you should see a line if you go to "Infections" in the meny, both on the "target-host"(with gold) and "bot-host" (server background). If you now study the terminal windows, the messages should now reflect that traffic is tagged. 
</p>
<p>
Now let's say that a server in the "target-host"-network comes under a D-DOS or bruteforce attack. They can then request help from all partners in our network to stop traffic from their units that are tagged (as you just got by clicking the honey.php script). Do do this, go to the "target-host", open a terminal window, change directory (cd) to programming/misc and run sudo perl checkload.pl. The logic here is that you can have a script running that automatically requests assistance from all partners if e.g a web server gets overloaded. Then we don't want traffic from botnets, either because we're under attack or because we need to prioritize presumed clean traffic. 
</p>

<?php
	//asdf
	printTechnicalStatus();
	
}

function demo()
{
	global $demoRow;
	//First check status

/*ipTargetHost int unsigned not null,
ipBotHost int unsigned not null,
ipBot int unsigned null, 
ipAdditionalBot1 int unsigned null, 
ipAdditionalBot2 int unsigned null, 
ipAdditionalBot3 int unsigned null, 
iAm enum('targetHost','botHost','bot') null,
status text, 
error bit(1) not null default b'0',
warning bit(1) not null default b'0',
activeDemo bit(1) not null default b'0',
statusInstalled bit(1) not null default b'0',
statusConnected bit(1) not null default b'0',
statusTaggingOk bit(1) not null default b'0',
statusTaggingReceivedOk bit(1) not null default b'0',
statusInfectedOk bit(1) not null default b'0',
statusRequestAssistanceOk bit(1) not null default b'0'
*/
	if ($demoRow && $demoRow["activeDemo"]+0)
	{
		//echo 'Not yet ready.. <a href="index.php?f=editDemo">Edit demo info</a>';
		//echo "I am: ".$demoRow["iAm"]."<br>";

		$conn = getConnection();

		if ($demoRow["iAm"] <> "targetHost" && $demoRow["ipTargetHost"] +0 > 0)
			print '<a href="http://'.$demoRow["targetHost"].'/index.php?f=demo">Open target host page</a>&nbsp;&nbsp;&nbsp;&nbsp;';
		if ($demoRow["iAm"] <> "botHost" && $demoRow["ipBotHost"] +0 > 0)
			print '<a href="http://'.$demoRow["botHost"].'/index.php?f=demo">Open bot host page</a>&nbsp;&nbsp;&nbsp;&nbsp;';
		if ($demoRow["iAm"] == "botHost" && $demoRow["ipBot"] +0 > 0)
			print '<a href="http://'.$demoRow["bot"].'/index.php?f=demo">Open bot page (if available from here)</a>&nbsp;&nbsp;&nbsp;&nbsp;';

		$szEditDemoUrl = '<a href="http://localhost?f=editDemo">Edit demo setup</a>'; 
		print $szEditDemoUrl;

		//Check that targetHost and botHost are on same net
		$nTargetNet = ($demoRow["ipTargetHost"] & hexdec("ffffff00"));
		$nBotNet = ($demoRow["ipBotHost"] & hexdec("ffffff00"));
		if ($nTargetNet <> $nBotNet)
			print '<br><font color="red">Target host and bot host are not on same net! (and probably can\'t communicate: '.$demoRow["targetHost"].' & '.$demoRow["botHost"].')</font>. '.$szEditDemoUrl.'<br>';


		checkCronTaskRunning($demoRow, "TargetHost");
			
		//Assemble the current demo status depending on what unit...:
		switch ($demoRow["iAm"])
		{
			case "targetHost":
				getTargetHostStatus();
				break;
			case "botHost":
				getBotHostStatus();
				//Uncomment to test this on the development computer...
				//print "--------------------------- BOT -----------------------<br>";
				//getBotStatus();
				break;
			case "bot":
				break;
		}
		
	} 
	else 
	{
	  	echo "There's currenly no registered ongoing demo setup..<br><br><a href=\"index.php?f=addDemo\">Set up one</a>";
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


function saveDemoInfo()
{
	global $demoRow;
	//Check first if there's a record already..
	$conn = getConnection();
	if (!$demoRow)
	{
		$szSQL = "insert into demo (userId, ipTargetHost, ipBotHost, ipBot,status, activeDemo) values (?, inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), inet_aton('0.0.0.0'), 'to be replaced', b'1')";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("i", $_SESSION["userid"]); 
                $stmt->execute();
	}

	//$szSQL = "update demo set ipTargetHost = inet_aton(?), ipBotHost = inet_aton(?), ipBot = inet_aton(?), iAm = ?";
	$szSQL = "update demo set ipTargetHost = inet_aton(?), ipBotHost = inet_aton(?), ipBot = inet_aton(?), iAm = ? where userId = ?";
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("ssssi", $_GET["targethostip"], $_GET["bothostip"], $_GET["botip"], $_GET["iam"], $_SESSION["userid"]);  
	//$stmt->bind_param("s", $_GET["iam"] );  
        $stmt->execute();
	//ipAdditionalBot1, ipAdditionalBot2, ipAdditionalBot3, 
	$cDemoPartners = array();
	if ($_GET["iam"] <> "botHost")  	$cDemoPartners[] = $_GET["bothostip"];
	if ($_GET["iam"] <> "targetHost") 	$cDemoPartners[] = $_GET["targethostip"];
	if ($_GET["iam"] <> "bot") 		$cDemoPartners[] = $_GET["botip"];
		
	//Send message to the others involved...
	foreach ($cDemoPartners as $szIp)
	{
		$szUrl = "http://".$szIp."/demo.php?bothostip=".$_GET["bothostip"]."&targethostip=".$_GET["targethostip"]."&botip=".$_GET["botip"];
		print "$szUrl<br>";
		//wget($szUrl);
		file_get_contents($szUrl);
	}
}

function addDemo()
{
	//$demoRow = getDemo();	Is being read when page opens....
	global $demoRow;
	
	if (!loggedIn())
		return;
	
	if (isset($_GET["submit"]))
	{
		saveDemoInfo();
		return;		
	}

	if ($demoRow && $demoRow["activeDemo"]+0 == 0)
	{
		print "There's registered demo setup but it's inactive... 
		<ul>
			<li><a href=\"index.php?f=activateDemo\">Activate it...</a></li>
			<li><a href=\"index.php?f=addDemo\">Change information...</a></li>
		</ul>";
	}

	printDemoForm(0, "addDemo");
}

function editDemo()
{
	if (isset($_GET["submit"]))
	{
		saveDemoInfo();
		return;		
	}

	$conn = getConnection();

	$sql = "SELECT inet_ntoa(ipTargetHost) as ipTargetHost, inet_ntoa(ipBotHost) as ipBotHost, inet_ntoa(ipBot) as ipBot, iAm from demo limit 1";
	$result = $conn->query($sql);
	$bOk = $result->num_rows > 0 && $row = $result->fetch_assoc(); 
	$conn->close();

	if ($bOk)
		printDemoForm($row, "editDemo");
}


function activateDemo()
{
	$conn = getConnection();
	$szSQL = "update demo set activeDemo = b'1'";
	$stmt = $conn->prepare($szSQL);
        $stmt->execute();
        print "Demo activated... <a href=\"index.php?f=demo\">Press here to reload..</a>";
	
}

function demoSetup()
{
	if (isset($_GET["conf"]) && $_GET["conf"]+0 == 1)
	{
		switch($demoRow["iAm"])
		{
		
		case "targetHost":
			$conn = getConnection();
			$sql = "update setup set  
		        	statusIntervalSec = 60, showStatus = b'1', showPreRoutePartner = b'1',showPreRouteNonPartner = b'0',
        			showForwardPartner = b'1', showForwardNonPartner = b'0', showUrgentPtrUsage = b'0',
        			showOwnerless = b'0', showOther = b'0', showNew1 = b'0', showNew2 = b'0',
        			doTagging = b'1', doInspection = b'0', doBlocking = b'0', handled = null";
			$result = $conn->query($sql);
			$conn->close();
			break;
		case "botHost":
			//Only difference from "targetHost" is that we don't want the preRoute messages on the "bothost".
			$conn = getConnection();
			$sql = "update setup set  
		        	statusIntervalSec = 60, showStatus = b'1', showPreRoutePartner = b'0',showPreRouteNonPartner = b'0',
        			showForwardPartner = b'1', showForwardNonPartner = b'0', showUrgentPtrUsage = b'0',
        			showOwnerless = b'0', showOther = b'0', showNew1 = b'0', showNew2 = b'0',
        			doTagging = b'1', doInspection = b'0', doBlocking = b'0', handled = null";
			$result = $conn->query($sql);
			$conn->close();
			break;
		case "bot":
			print "<h1>No setup to be done on this computer with the current demo setup..</h1>";
			break;
		}
		
		print "<h2>Setup is changed. Now you can go back to the bot (computer/phone) and continue simulating the attack.</h2>";
	}

?>
	Not yet finalized...<br><br>
	You want to change the setup...<br><br>
			But while doing so, you might like to see how the abmonitor (a user space program) sends updated setup information to absecurity (a linux kernel module).<br>On the "<?php print $demoRow["iAm"]; ?>" computer, there should be two terminal windows running (Ctrl-Alt-T to open new window):
			<li>Check first om you have one terminal window where the last line is "Waiting for message from kernel". If so, that's the abmonitor. If you can't find it then check the "Akili Bomba Gatekeeper" document on how to start is.</li>
			<li>The other window should show lines like this:<br>
[124187.261689] Absecurity: FW to partner: 192.168.50.100->192.168.39.195: Tag: (00000000)<br>
[124187.289499] Absecurity: FW from partner: 192.168.39.195->192.168.50.100: Tag: (00000000)<br></li>
		</ul>
		Now, you can put up the two windows beside each others and when you click the links to change the setting, the traffic will show in the absecurity window and the new setup will appear in both.<br><br>
		<a href="http://<?php print $demoRow["targetHost"]; ?>/index.php?f=demoSetup&conf=1">Click here to do the setup changes</a><br>
			
<?php
			
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

function submitLogin()
{
	print "Trying to login... User: ".$_GET["email"].", pass: ".$_GET["pass"]."<br>";
	$szSQL = "select userId, password from user where username = ?";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $_GET["email"]);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
		$row = $result->fetch_assoc();
	else 
		$row = 0;
	if ($row)
	{
		if ($row["password"] == $_GET["pass"])
		{
			print "WELCOME! You are logged in.";
			$_SESSION["userid"] = $row["userId"];
		}
		else
		{
			print "Error in user name or password. ";
			login();
		}
	}
	else {
		$szSQL = "insert into user(username, password) values (?, ?)";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("ss", $_GET["email"], $_GET["pass"]);
		$stmt->execute();
		$_SESSION["userid"] = last_insert_id($conn);
		print "New user registered.";
	}
	$conn->close();
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

function displayWarnings()
{
	$conn = getConnection();
	
	if (isset($_GET["id"]))
	{
		$szSQL = "update warning set handled = now() where warningId = ?";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("d", $_GET["id"]);
		$stmt->execute();
	}
	
	$sql = "select warningId, lastWarned, warning from warning where handled is null order by lastWarned desc limit 20";
	$result = $conn->query($sql);
	print "<table>";
	while ($row = $result->fetch_assoc())
	{
		print "<tr><td>".$row["lastWarned"]."</td><td>".$row["warning"]."</td><td>".getCloseWarningLink($row["warningId"])."</td></tr>"; 
	}
	print "</table>";
}

function dhcpLease()
{
//asdfasdf
	$sql = "select dhcpDumpLogId, dhcpDumpFileId, logTime, unitId, macAddress, inet_ntoa(ipAddress) as ipAddress, dhcpClientId, mac, vci, hostname, comment from dhcpDumpLog order by dhcpDumpLogId desc limit 50";  
	
	$conn = getConnection();
	$result = $conn->query($sql);
	print "<table>";
	print "<tr><td>Time</td><td>UnitId</td><td>mac</td><td>IP</td><td></td><td></td><td>Vendor class id</td></tr>";
	while ($row = $result->fetch_assoc())
	{
		print "<tr><td>".$row["logTime"]."</td><td>".$row["unitId"]."</td><td>".$row["macAddress"]."</td><td>".$row["ipAddress"]."</td><td>".$row["dhcpClientId"]."</td><td>".$row["mac"]."</td><td>".$row["vci"]."</td><td>".$row["hostname"]."</td><td>".$row["comment"]."</td></tr>"; 
	}
	print "</table>";

}


function workshops()
{
//asdfasdf
	$sql = "select workshopId, inet_ntoa(ip) as ip, role, inet_ntoa(publicIp) as publicIp, created, lastseen from workshop order by created desc limit 50";  
	$conn = getConnection();
	$result = $conn->query($sql);
	print "<table>";
	//print "<tr><td>Time</td><td>UnitId</td><td>mac</td><td>IP</td><td></td><td></td><td>Vendor class id</td></tr>";
	$nFound = 0;
	while ($row = $result->fetch_assoc())
	{
		if (!$nFound)
			print "<tr><td>Id</td><td>IP</td><td>Role</td><td>Public IP</td><td>Created</td><td>Seen</td></tr>";
			
		print "<tr><td>".$row["workshopId"]."</td><td>".$row["ip"]."</td><td>".$row["role"]."</td><td>".$row["publicIp"]."</td><td>".$row["created"]."</td></tr>";
		$nFound++;
	}
	print "</table>";
	if (!$nFound)
		print "No workshop records found.";
}


showMenu();

if (isset($_SESSION["userid"]) && isset($_GET["f"]) &&  in_array($_GET["f"], array("setup", 'servers', "partners", "domains", "colorListings", "inspections", "workshop", "honey", "listrouters","addpartner","addserver","adddomain","addcolorlisting","addinspection","addhoney","partner","delRouter")))
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


if (!isset($_SESSION["userid"]) && (!isset($_GET["f"]) || $_GET["f"] <> "subLogin"))
{
	login();
	return;
} 

//showLog();
if (isset($_GET['f']))
switch($_GET['f'])
{
	case 'traffic':
		traffic();
		break;
        case 'partners':
        case 'delpartner':
                listPartners();
                break;
	case 'addpartner':
		addPartner();
		break;
	case 'partner':
	        partner();
	        break;
	case 'delPartner':
	        delPartner();
	        break;
	case 'addrouter':
	        addRouter();
	        break;
	case 'delRouter':
	        delRouter();
	        break;
	case 'listrouters':
	        listrouters();
	        break;
	case 'delserver':
	case 'servers':
		listServers();
		break;
	case 'infections':
		listInfections();
		break;
	case 'addinfection':
		addInfection();
		break;
	case 'delInfection':
		delInfection();
		break;
	case 'addserver':
		addServer();
		break;
	case 'domains':
		listDomains();
		break;
	case 'adddomain':
		addDomain();
		break;
	case 'domaindel':
		domainDelete();
		break;
	case 'domaininfo':
		domainInfo();
		break;
	case 'addcolorlisting':
		addColorListing();
		break;
	case 'colorListings':
		listColorListings();
		break;
		print "About to call addColorListing()<br>";
		addColorListing();
		break;
	case 'inspections':
	        inspections();
	        break;
	case 'addinspection':
	        addInspection();
	        break;
	case 'dnslookup':
		dnsLookup();
		break;
	case 'honey':
	        honey();
	        break;
	case 'addhoney':
	        addHoney();
	        break;
	case 'delhoney':
	        delHoney();
	        break;
	case 'setup':
		setup();
		break;
	case 'log':
	        listLog();
	        break;
	case 'units':
	        units();
	        break;
	case 'edtDesc':
	        editDescription();
	        break;
        case 'showPorts':
                showPorts();
                break;
	case 'reportHack':
	        reportHack();
	        break;
	case 'attack':
		attack();
		break;
	case 'delAttack':
		delAttack();
		break;
	case 'addassreq':
		addAssistanceRequest();
		break;
	case 'demo':
		demo();
		break;
	case 'addDemo':
		addDemo();
		break;
	case 'editDemo':
		editDemo();
		break;
	case 'activateDemo':
		activateDemo();
		break;
	case 'demoSetup':
		demoSetup();
		break;
	case 'about':
		about();
		break;
	case 'help':
		help();
		break;
	case 'rmWarn':
		removeWarning();
		break;
	case "subLogin":
		submitLogin();
		break;
	case "logout":
		logout();
		break;
	case "setupMenu":
		setupMenu();
		break;
	case "warnings":
		displayWarnings();
		break;
	case "dhcpLease":
	        dhcpLease();
	        break;
	case "workshop":
		workshops();
		break;
	default:
		print 'Unknown menu choice';
}



?> 
</td></tr></table>

</body>
<html>
