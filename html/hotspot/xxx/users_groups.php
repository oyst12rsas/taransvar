<?php

function users_groups() { 
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$szRow = "";
	$cFlds = array();
	
	while ($cRec = $pDb->fetchNext("select usergroupid, groupname, right(description,30) as description from usergroup order by groupname", $cFlds))
	{
		$szRow .= tr(td(a($cRec["groupname"],"index.php?f=users_group&id=".$cRec["usergroupid"])).td($cRec["description"]));
	}
	
	if (!strlen($szRow))
		$szRow = tr(td("No groups registered yet."));

	print table($szRow);
	
	print "<br><br>".a("Add group", "index.php?f=users_addgroup");
}

?>
