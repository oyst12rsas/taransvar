<?php



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

?>
