<?php

function users_addgroup() {
	if (!isSuperUser())
		return; 	//Should report hacking..

	$name = request("name");
	$szDesc = request("comment");

	if (isset($_POST["submit"]))
	{
		if (strlen($name) > 20)
		//if (!preg_match('/^(\w*)$/', $name, $matches, PREG_OFFSET_CAPTURE))
		{
//			print red("Only alpanumeric characters plus _ are allowed in group name");
			print red("Sorry, but max group name lenth is 20 characters");

		}
		else
		{
			$pDb = new CDb;
			$cFlds = array(":name"=>$name);
			if ($cFetched = $pDb->fetchNext("select groupname from usergroup where groupname = :name", $cFlds))
			{
				print "This group is already registered!";
			}
			else
			{
				$cFlds = array_merge($cFlds,array(":desc"=>$szDesc, ":purpose"=>request("purpose")));
				$pDb->execute("insert into usergroup(groupname, description, defaultpurpose) values (:name, :desc, :purpose)", $cFlds);
			
				print "<b>New group saved!</b><br><br>";
				
				//Save this purpose selection as default....
				$cFlds = array(":purpose"=> request("purpose"));
				$pDb->execute("update hotspotSetup set defaultpurpose = :purpose", $cFlds);
				
				userGroups();
				return;
			}
		}
	}

	$cSetup = getSetup();

	$szRows = tr(td("Group name").td('<input name="name" value="'.$name.'">')).
			tr(td('Description:'.$szDesc."</textarea>",2)).
			tr(td('<textarea name="comment" rows="5" cols="60">'.$szDesc."</textarea>",2)).
			tr(td("Purpose").td(getGroupCampPurposeDrop($cSetup["defaultpurpose"])));	
	$szRows .= tr(td('<button  name="submit" type="submit">Submit</button>',2));
	
	print '<form  action="index.php?f=users_addgroup" method="post">'.table($szRows).'</form>';
	
}
 
?>
