<?php

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

?>
