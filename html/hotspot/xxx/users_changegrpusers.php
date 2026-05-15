<?php


function users_changegrpusers()
{
	print "Add new users to the group..<br>";
	
	$nId = request("id");
	//$szGroupName = request("nm");
	$szUsers = request("users");
	$cInternalDuplicates = array();
	$pExecute = new CDb;
	$nCount = 0;
    $szUserNameRows = "";

	if (isset($_POST["submit"]))
	{
		$cUsers = explode("\n", $szUsers);

		if (isset($_POST["generate"]))
			$szUserNameRows = tr(td("<br>Generated user names:</b>"));
		else
			$szUserNameRows = tr(td("<br>Sample user names:</b>"));
			
		foreach($cUsers as $szUser)
		{
			$szUser = str_replace(" ","",$szUser);
			$szUser = str_replace("-","",$szUser);
			$szUser = str_replace(",","",$szUser);
			$szUser = preg_replace('/(\s*)(\w*)(.*)/', '$2', $szUser);
			
			if (!strlen($szUser))
				continue;

			if (strlen($szUser) < 5)
				$szUser .= random_str(5 - strlen($szUser));
			else
				$szUser = substr($szUser, 0, 5);
				
			//Check if exists and if so, rancomize case and check again
			for ($n = 0; $n < 1000; $n++)
			{
				$cFlds = array(":user" => $szUser);
				$szFound = CDb::getString("select username from radcheck where username = :user", $cFlds);
				
				if (!strlen($szFound))
				{
					//Not yet in database.. chedk if there's internal duplicates..
					if (in_array($szUser, $cInternalDuplicates))
						$szFound = $szUser;
				}
				
				if (!strlen($szFound))
				{
					if (isset($_POST["generate"]))
					{
						$szLog = $szUser.green(" - generated");
						$cParam = array(":name"=> $szUser, ":pass" => random_str(5));
						$pExecute->execute("insert into radcheck (username, attribute, op, value, confirmedTime, subscriptionType, mbquota) values (:name, 'Cleartext-Password', ':=', :pass, now(),'quota',0)", $cParam);

						$cParam = array(":name"=> $szUser, ":id" => $nId);
						$pExecute->execute("insert into radusergroup (username, groupid, priority) values (:name, :id, 1)", $cParam);
						$nCount++;
					}
					else
						$szLog = $szUser;
					
					$szUserNameRows .= tr(td($szLog));
					
					array_push($cInternalDuplicates, $szUser);
					$n = 2000; //To exit the loop.
				}
				else
				{
					if ($n % 100 == 0)
					{
						$szUser .= random_str(1);
					}
				
					//Find new user name to test...
					$nChar = rand(0,strlen($szUser)-1);
					$szChar = substr($szUser,$nChar,1);
					$szUpper = strtoupper($szChar);
					
					if ($szChar == $szUpper)
						$szChar = strtolower($szChar);
					else
						$szChar = $szUpper;
						
					$szUser = substr($szUser,0,$nChar).$szChar.substr($szUser,$nChar+1);
					//$szUserNameRows .= tr(td("Was used already.. now trying: $szUser"));
				}
			}
		
		}

		if (isset($_POST["generate"]))
			$szUserNameRows.= tr(td(red("($nCount user names generated)")));
		else
			$szUserNameRows.= tr(td(red("NOTE! This is just a sample. Final user names will differ")));
	}
	
	$szGroupName = 	getGroupName($nId); 
	
	$szRows = tr(td("Group:").td(a($szGroupName,"index.php?f=users_group&nm=".$szGroupName))).
			tr(td(a("Try our user name generator", "index.php?f=users_group_generator&id=".$nId),2)).
			tr(td('Or type user names to add below:',2)).
			tr(td(table(tr(td('<textarea name="users" rows="20" cols="40">'.$szUsers."</textarea>").td('<span class="tight">'.table($szUserNameRows.'</span>','class="tight"'),1,'valign="top" class="tight" '))),2)).
			tr(td('Paste a list of name (or user names) in the box above and then click submit to see how the system will interprete that',2));

	if (!isset($_POST["submit"]))
		$szRows .= tr(td('<button  name="submit" type="submit">Submit</button>',2));
	else
		if (!isset($_POST["generate"]))
			$szRows .= tr(td('<input type="hidden" name="nm" value="'.$szGroupName.'"><button  name="generate" type="submit">Generate users</button><input type="hidden" name="submit" value="1">',2));
		else
			$szRows .= tr(td('User names generated',2));
	
	print '<form  action="index.php?f=users_changegrpusers&nm='.$szGroupName.'" method="post">'.table($szRows).'</form>';
	
}

function changeGroupUsers()
{
	users_changegrpusers();	//Doubt it's in use;
}

?>
