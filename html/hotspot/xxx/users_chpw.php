<?php

function users_chpw()
{
	if (!isSuperUser())
	{
		print "Aborting";
		return;
	}

	$szUser = request("nm");
	$szUserEsc = htmlspecialchars($szUser, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	print "Change password for $szUserEsc<br><br>";

	$pDb = new CDb;
	$cUser = $pDb->fetch("select username from hotspotSubscriber where username=:name limit 1", array(":name"=>$szUser));
	if (!$cUser)
	{
		print "User not found! Aborting.";
		return;
	}

	$szPwd1 = "";
	$szPwd2 = "";
	if (isset($_POST["submit"]))
	{
		$szPwd1 = (string)request("pwd");
		$szPwd2 = (string)request("pwd2");

		if ($szPwd1 === "")
			print "<br>".red("PASSWORD MAY NOT BE EMPTY!")."<br><br>";
		else if (!hash_equals($szPwd1, $szPwd2))
			print "<br>".red("THE PASSWORDS DIFFER!")."<br><br>";
		else
		{
			$cFlds = array(":name"=>$szUser, ":password"=>$szPwd1);
			$pDb->execute("update hotspotSubscriber set password=:password where username=:name", $cFlds);
			// Keep the explicit legacy FreeRADIUS password row synchronized.
			$pDb->execute("update radcheck set value=:password where username=:name and op=':=' and attribute='Cleartext-Password'", $cFlds);
			print 'Password is changed.<br><br><a href="index.php?f=users_show&nm='.rawurlencode($szUser).'">Back to user</a>';
			return;
		}
	}

	print '<form action="index.php?f=users_chpw&nm='.rawurlencode($szUser).'" method="post">';
	$szRows = tr(td('Password:').td('<input type="password" name="pwd" value="">')).
		tr(td('Repeat password:').td('<input type="password" name="pwd2" value="">')).
		tr(td('<button type="submit" name="submit">Submit</button>',2));
	print table($szRows).'</form>';
}

?>