<?php


function users_addcamp()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$cFlds = array(":id"=>request("id"));
	if (!$cRec = $pDb->fetchNext("select groupname, description, defaultpurpose from usergroup where usergroupid = :id", $cFlds))
	{
		print "Group not found!";
		return;
		
	}
	
	if (isset($_POST["submit"]))
	{
		print "Saving new campaign...";
		
		if (request("purpose") == "generatetempusers")
		{
			$cFlds = array(":id"=>request("id"), ":desc"=>request("comment"),":purpose"=>request("purpose"),
					":prefix"=>"",//request("prefix"), 
					":random"=>"b'0'",//request("random"), 
					":hours"=>request("hours"),
					":quota"=>request("quota"),
					":offset"=>request("offset"),
			//		":count"=>request("count"),
					":price"=>0,//request("price"),
					":priceinfo"=>request("priceinfo"),
				);
		
			//print "successive: ".(isset($_POST["successive"])?"b'1'":"b'0'")."<br>";
		
			$szSuccessive = "b'0'";//(isset($_POST["successive"])?"b'1'":"b'0'");
			$szNumbers = "b'0'";//(isset($_POST["numbers"])?"b'1'":"b'0'");
			$szSQL = "insert into groupcampaign (groupid, campaindescription,purpose, usernameprefix, randomchars, successive, numbersonly, printStartOffset, giveHoursAfterLogin, giveMB, count, price, priceinfo) 
				values (:id,:desc, :purpose, :prefix, :random, $szSuccessive, $szNumbers, :offset, :hours, :quota, :count, :price, :priceinfo)";



		}
		else
		{
			$nOffset = request("offset");
			$nHours = intval(request("hours"));
			$nQuota = intval(request("quota"));
			print "Offset: $nOffset, hours: $nHours<br>"; 
		
			$cFlds = array(":id"=>request("id"), 
					":desc"=>request("comment"),
					":purpose"=>'groupmembers',//request("purpose"),
					":hours"=>$nHours,
					":quota"=>$nQuota,
					":offset"=>$nOffset,
					":price"=>0,//request("price"),
					":priceinfo"=>request("priceinfo"),
				);
		
			print "successive: ".(isset($_POST["successive"])?"b'1'":"b'0'")."<br>";
		
			$szSuccessive = (isset($_POST["successive"])?"b'1'":"b'0'");
			$szNumbers = (isset($_POST["numbers"])?"b'1'":"b'0'");
			$szSQL = "insert into groupcampaign (groupid, campaindescription, purpose, printStartOffset, giveHoursAfterLogin, giveMB, price, priceinfo, usernameprefix) 
				values (:id,:desc, :purpose, :offset, :hours, :quota, :price, :priceinfo,'')";
		}
		
		$pDb = new CDb;
		$pDb->execute($szSQL, $cFlds);
			
		require_once("xxx/users_showcamp.php");	
		showCampaign(lastInsertId());
		return;
	}
	
	//$cSetup = getSetup();
	
	$szDesc = "";
	
	$szRows = tr(td("Register new user group campaign:",3));
	$szRows .= tr(td("Group name:").td(a($cRec["groupname"],"index.php?f=users_group&nm=".request("nm")),2)).
			tr(td("Group description:").td($cRec["description"],2));
			
	$szRows .= tr(td("<br>Campaign description",3)).
			tr(td('<textarea name="comment" rows="5" cols="60">'.$szDesc."</textarea>",3));
			
	//$szRows .= tr(td("Purpose").td(getGroupCampPurposeText($cRec["defaultpurpose"]).'<input type="hidden" name="purpose" value="'.$cRec["defaultpurpose"].'"',2));	

	$szRows .= tr(td("Information for tickets:",2));
	
	if ($cRec["defaultpurpose"] == "generatetempusers")
	{
	//	$szRows .= tr(td("Prefix").td('<input name="prefix" size="3">').td("Start all generated usernames with this prefix"));
	//	$szRows .= tr(td("Randomchars").td('<input name="random" value="3" size="2">').td("How many random characters to add"));
	//	$szRows .= tr(td("Successive").td('<input name="successive" type="checkbox">').td("Number new users successively (after the prefix)"));
	//	$szRows .= tr(td("Numbers only").td('<input name="numbers" type="checkbox">').td("Numbers only. Leave unchecked to use also A-Z and a-z"));
	//	$szRows .= tr(td("Count").td('<input name="count" value="20" size="2">').td("Number of new user names to generate"));
	}
	
	$szRows .= tr(td("Hours access").td('<input name="hours" value="48" size="2">').td("How many hours to give after registration (unless quota exceeded)"));
	$szRows .= tr(td("Quota").td('<input name="quota" value="100" size="2">').td("Quota (in MB) to give unless expired. You may omit quota or hours."));
	$szRows .= tr(td("Print offset").td('<input name="offset" value="0" size="2">').td("When printing, start at this number (for printing several pages)"));

	//$szRows .= tr(td("Price").td('<input name="price" value="0" size="2">').td("Price per username (for printing)"));
	$szRows .= tr(td("Price info").td('<input name="priceinfo" value="100 KSH" size="2">').td("Will be printed after the price. Printed after the price unless price is part of the text"));


	$szRows .= tr(td('<button  name="submit" type="submit">Submit</button>',3));
	
	print '<form  action="index.php?f=users_addcamp&id='.request("id").'" method="post">'.table($szRows).'</form>';
}

?>
