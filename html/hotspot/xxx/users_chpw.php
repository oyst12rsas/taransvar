<?php

//function showUser()
function users_chpw()
{
	if (!isSuperUser())
	{
		print "Aborting";
		return;
	}

	$szUser = request("nm");
	
	print "Change password for $szUser<br><br>";

	if (isset($_POST["submit"]))
	{
		$szPwd1 = request("pwd");
		$szPwd2 = request("pwd2");

		if (strcmp($szPwd1, $szPwd2))
		{
			print "<br>".red("THE PASSWORDS DIFFER!")."<br><br>";
		}
		else
		{
			//print "<br>".red("NOT YET LEARNED TO SAVE")."<br><br>";
			$pDb = new CDb;
			$cFlds = array(":name" => $szUser);
			$szSQL = "update radcheck set value = ? where username = ? and op = ':=' and attribute = 'Cleartext-Password'";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("ss", $szPwd1, $szUser);
	                $stmt->execute();
			print 'Password is changed.<br><br><a href="index.php?f=users_show&nm='.$szUser.'">Back to user</a>';
			return;
		}
	} 
	else
	{
		$szPwd1 = "";
		$szPwd2 = "";
	}
	
			
	print '<form  action="index.php?f=users_chpw&nm='.$szUser.'" method="post">';
	$szRows = tr(td('Password:').td('<input type="edit" name="pwd" value="'.$szPwd1.'">')).
	tr(td('Repeat password:').td('<input type="edit" name="pwd2" value="'.$szPwd2.'">>')).
	tr(td('<button type="submit" name="submit">Submit</button>',2));
	
	print table($szRows);
}

?>
