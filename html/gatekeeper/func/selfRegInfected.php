<?php
//func/selfRegInfected.php

require_once("../taraLib.php");


function selfRegInfected()
{

	if (isset($_GET["conf"]) && $_GET["conf"]+0 == 1)
	{
		reportHacking("demo", "User self registered as infected");
		print "You are registered as infected.<br><br><a href=\"index.php?f=infections\">Click here or \"Infections in the menu\"</a> to see it.";
		return;
	}

?>
<table style="border: none;"><tr><td style="border: none;"><div style="text-align: left;">
You have cliced link to register yourself as infected.<br><br>
This means:
<ul>
<li>Traffic between your TaraSec gateway and other units in the TaraSec network will be tagged</li>
<li>You can deactivate the tagging by clicking <a href="index.php?f=infections">Infections in the menu</a>/</li>
<li>Deativating means tag severity = 1. To remove it completely, you need to log in as administrator.</li>
</ul>

What happens technically:
<ul>
<li>A record is added to table hackReport</li>
<li>Taralink warns your gateway (??)</li>
<li>A record is added to internalInfections on gateway</li>
<li>Taralink on gateway informs tarakernel about the new infection (dmesg or "Log" in gatekeeper to see)</li>
<li>Traffic from then on will be tagged with the given severity number</li>
<li></li>
</ul>


Are you sure you want to register yourself as infected? <a href="index.php?f=selfRegInfected&conf=1">If so, then click here</a>
</div></td></tr></table>
<?php
}
?>