<?php

//function setSubdcriptionEndTime()
function users_addtime()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	
	$cFlds = array(":name"=>request("name"));
	if (!$cFetched = $pDb->fetchNext("select expirytime from radcheck where username = :name and op = ':=' and attribute = 'Cleartext-Password'", $cFlds))
	{
		print "User not found! Aborting.";
		return;
	}
	
	$szCurrent = $szDBCurrent =substr($cFetched["expirytime"],0,16);
	$szNow = date("Y-m-d H:i",time());
	
	if (!strlen($szCurrent))
		$szCurrent = $szNow;

	$timestamp = strtotime($szNow) + 60*60;
	
	$szOneHour = date('Y-m-d H:i', $timestamp);

	$cParts = explode(":",$szOneHour);

	$szEndOfHour = $cParts[0].":"."00";

	$timestamp = strtotime($szNow) + 60*60*24;
	$sz24Hours = date('Y-m-d H:i', $timestamp);

	$szSetTimeTo = "";

	$timestamp = strtotime($szCurrent) + 60*60;
	$szAdd1Hour = date('Y-m-d H:i', $timestamp);

	$timestamp = strtotime($szCurrent) + 60*60*24;
	$szAdd24Hours = date('Y-m-d H:i', $timestamp);

	if (isset($_POST["now"]))
	{
		$szSetTimeTo = $szNow;
		unset($_POST["now"]);
	}

	if (isset($_POST["hourend"]))
	{
		$szSetTimeTo = $szEndOfHour;
		unset($_POST["hourend"]);
	}

	if (isset($_POST["onehour"]))
	{
		$szSetTimeTo = $szOneHour;
		unset($_POST["onehour"]);
	}

	if (isset($_POST["24hours"]))
	{
		$szSetTimeTo = $sz24Hours;
		unset($_POST["24hours"]);
	}

	if (isset($_POST["add1hour"]))
	{
		$szSetTimeTo = $szAdd1Hour;
		unset($_POST["add1hour"]);
	}

	if (isset($_POST["add24hours"]))
	{
		$szSetTimeTo = $szAdd24Hours;
		unset($_POST["add24hours"]);
	}
	
	if (isset($_POST["submitTime"]))
	{
		print "Time was given...<br>";
		$szSetTimeTo = $_POST["setTo"];
		unset($_POST["submitTime"]);
	}
	
	if (strlen($szSetTimeTo))
	{
		$cFlds = array(":name"=>request("name"), ":expiry"=>$szSetTimeTo);
		$pDb->execute("update radcheck set expirytime = :expiry where username = :name", $cFlds);
		requireAccessUpdate();
		users_addtime();	//Old name: setSubdcriptionEndTime();
		return; //Read the current data again...
	}
	
	$szRows = tr(td("Set subscription expiry time:",2));
	$szRows .= tr(td("Current time:").td($szNow));
	$szRows .= tr(td("Current expiry time:").td((!strlen($cFetched["expirytime"])?red("Not yet set"):$szCurrent)));
	$szRows .= tr(td('<button  type="submit" name="now">Expire now</button>').td($szNow));
	$szRows .= tr(td('<button  type="submit" name="hourend">End of hour</button>').td($szEndOfHour));
	$szRows .= tr(td('<button  type="submit" name="onehour">One hour</button>').td($szOneHour));
	$szRows .= tr(td('<button  type="submit" name="24hours">24 hours</button>').td($sz24Hours));
	$szRows .= tr(td('<button  type="submit" name="add1hour">+ 1 hour</button>').td($szAdd1Hour));
	$szRows .= tr(td('<button  type="submit" name="add24hours">+ 24 hours</button>').td($szAdd24Hours));

	$szRows .= tr(td('<input name="setTo" value="'.$szCurrent.'" width="5"><button  type="submit" name="submitTime">Submit time</button>',2));

	//$szRows .= tr(td('<button type="submit">Submit</button>',2));					
	//$szRows .= tr(td("You may have received confirmation code to email or SMS depending on the current policy.",2));					
	//print '<form  action="index.php?f=main_confCode&name='.$szName.'&pass='.$szPass.'" method="post">'.table($szRows).'</form>';
	print '<form  action="index.php?f=users_addtime&name='.request("name").'" method="post">'.table($szRows).'</form>';


}

?>
