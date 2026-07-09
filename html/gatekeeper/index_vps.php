<?php
session_start();
$nRequiredDbVersion=72;	//NOTE! Make sure this line is always number 3 in the file because that's claimed below.

$szErrorMessage = "";
//$szErrorMessage = '<h3><font color="red">I\'ll be down for planned maintainance 1pm GMT today (Friday 5th)</font></h3><b>Let me know if it\'s inconvenient. I\'m flexible.<b><br><br>';	//Use it to print message...
//$szErrorMessage = "";//'<h3><font color="red">The Demo network will be taken down in <div id="countdown"></div></font></h3><br>';	//Use it to print message...
//$szErrorMessage = '<h3><font color="red">This gateway will be replaced by <a href="http://100.68.165.190/gatekeeper/index.php">this new one.</font></a>.</h3>You should set it as your homepage while checking the sites out.<br><br>';
$szTargetTime = "";//"2026-06-09T16:00Z";	//Zulu/GMT time... Set to "" if not relevant. Usage: taken down in <div id="countdown"></div>


//Check if db is updated otherwise the script often fails...

function printTitle()
{
	print "Obsolete gateway";

}



//Decide background image if running demo
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
	return true;
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

showMenu();

?> 

This page is no longer in use. Please visit <a href="http://10.100.1.1/gatekeeper">our new server instead.

</td></tr></table>

</body>
</html>
