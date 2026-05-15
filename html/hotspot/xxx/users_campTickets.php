<?php

function users_campTickets() {


	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$cFlds = array(":name"=>request("nm"));
	if (!$cRec = $pDb->fetchNext("select groupname, description, defaultpurpose from usergroup where groupname = :name", $cFlds))
	{
		print "Group not found!";
		return;
		
	}

	print a("print tickets", "index.php?f=users_printlabels");


}

?>
