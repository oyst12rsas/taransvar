<?php
session_start();
$nRequiredDbVersion=66;	//NOTE! Make sure this line is always number 3 because that's claimed below.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include "taraLib.php";
$szIP = getSenderIp();
?>
<html>
<header>
<script type="text/javascript" src="gatekeeper/std.js"></script>
<script type="text/javascript" src="gatekeeper/lib.js"></script>
<script type="text/javascript" src="gatekeeper/lib2.js"></script>
<script type="text/javascript" src="cyberDemo.js"></script>
<script>
    var cAjaxServer = "ajax.php";
    function debugging()
    {
        return 1;
    }
	function pageLoader()
	{
    	const intervalId = setInterval(myUpdaterFunction, 3000);
	}
</script>
</header>
<body onload="pageLoader()">
<h1>Taransvar Cyber Demo</h1>
<table>
    <tr><td>Tom<br><div id="node1" name="10.47.14.12">Status here..</div></td>
    <td>Beth<br><div id="node2" name="10.47.14.13">Status here..</div></td></tr>
    <tr><td colspan="2">Router<br><div id="rouer" name="10.100.0.1">Status here..</div></td></tr>
    <tr><td colspan="2"><h2>You</h2><div id="me">Me info here</div><?php 
    
    print "IP: ".$szIP;
    
    ?></td></tr>
<?php
?>
</table>
</body>
</html>