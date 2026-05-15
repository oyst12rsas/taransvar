<?php


function useSame($nChars,$nDifferent, $nUsed, $nDifferentUsed) {
	//Now check if should add more of the same char
	$nRemaining = $nChars - $nUsed;
	$nDifferentRemaining = $nDifferent - $nDifferentUsed + 1; //(including current..)
	
	if ($nDifferentRemaining <= 1)
		return true;
	
	$nPercentChanceForChange = 100 * $nDifferentRemaining / $nRemaining;  

	$nSameChance = rand(1, 100);
//	print " Diff remains: $nDifferentRemaining, remains: $nRemaining. rand() = $nSameChance ".($nSameChance <= $nPercentChanceForChange ? " <= $nPercentChanceForChange, use same":" > $nPercentChanceForChange, new char");
	
	if ($nSameChance > $nPercentChanceForChange)
		return true;
	else
		return false;
}

function generate($nChars,$nDifferent) {
	//NOTE! Illegal chars: ()/:;#
	$szChars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwzyz23456789!%&=?-+*{[]}";
	$szGenerated = "";
	$nDifferentUsed = 0;
	
	while (strlen($szGenerated) < $nChars) {
		$char = substr($szChars, rand(0, strlen($szChars))-1, 1);
		$nDifferentUsed++;
		$szGenerated .= $char;

		while (strlen($szGenerated) < $nChars) {
//			print "$szGenerated Diff used: $nDifferentUsed";
			$bUseSame = useSame($nChars, $nDifferent, strlen($szGenerated), $nDifferentUsed);
//			print ($bUseSame?"SAME": "Change")."<br>";
			
			if ($bUseSame)
				$szGenerated .= $char;
			else
				break;
		}
	}
	
		
	
	return $szGenerated;	
}

function submittedUserGenerator() {
	$nId = $_GET["id"];
	$szGroupName = getGroupName($nId);
	$nNumber = $_GET["num"];
	$szPrefix = $_GET["prefix"];
	$nUsrChars = $_GET["userchars"];
	$nUsrRnd = $_GET["userrandom"];

	$nPassChars = $_GET["passchars"];
	$nPassRnd = $_GET["passrandom"];
	
	$szRows = tr(td(h3("Generating users for ".$szGroupName),3));

	$pDb = new CDb;
	
	$cFlds = array();
	$szLookupSQL = "select username from radcheck where username = :name";  


	for ($n = 0; $n < $nNumber; $n++) {
		for ($m = 0; $m < 100; $m++) {
			$szUsername = $szPrefix.generate($nUsrChars,$nUsrRnd);
			
			//Check if this user name is occupied.. 
			//$pDb = new CDb;
			$cFound = $pDb->fetch($szLookupSQL, array(":name"=>$szUsername));
			if (!$cFound) {
				break;	//Not in use...
			}
		}
		
		if ($m == 100) {
			$szRows .= tr(td(red("Couldn't find any available user name so skipping...<br>"),3));
		} else {
			$szPassword = generate($nPassChars,$nPassRnd);
			$szRows .= tr(td($n).td($szUsername).td($szPassword));
		
			$szSQL = "insert into radcheck(username, value) values (:username,:password)";

			$cFlds = array(":username"=>$szUsername, ":password"=>$szPassword);
			$pDb->execute($szSQL, $cFlds);

			$szSQL = "insert into radusergroup(username, groupid) values (:username,:id)";

			$cFlds = array(":username"=>$szUsername, ":id"=>$nId);
			$pDb->execute($szSQL, $cFlds);
		}
	}
	print "Users: ".table($szRows);
	return true;
}

function users_group_generator()
{
	if (isset($_GET["submit"])) {
		if (submittedUserGenerator())
			return;
	}

	$nId = $_GET["id"];
	$szGroupName = getGroupName($nId);

	$szRows = 	tr(td(h2("Generate users for: ".$szGroupName))).
			tr(td("How many?").td('<input name="num">')).
			tr(td(b("User name",2))).
			tr(td("Prefix").td('<input name="prefix">')).
			tr(td("Total number of chars").td('<input name="userchars">')).
			tr(td("Number of different chars").td('<input name="userrandom">')).
			tr(td(b("Password",2))).
			tr(td("Total number of chars").td('<input name="passchars">')).
			tr(td("Number of different chars").td('<input name="passrandom">')).
			tr(td('<input type="hidden" name="f" value="users_group_generator"><input type="hidden" name="id" value="'.$nId.'"><input id="submit" name="submit" type="submit">',2)).
			
			tr(td("<br>Total number is the number of chars in username/password.<br>Different chars is the number or different chars.<br><br>".
			"If you specify 10 and 3, you may get AAAA4xxxxx. 10 characters in total but only 3 different.<br><br>".
			"What you put as prefix will be inserted in front.. <br>So \"xx\" and the example above user name may be xxAAAA4xxxxx", 2)); 

	print '<form action="index.php?f=users_group_generator" type="get">'.table($szRows).'</form>';
	

}

?>


