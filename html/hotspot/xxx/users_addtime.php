<?php

function users_addtime()
{
	if (!isSuperUser())
		return;

	$pDb = new CDb;
	$szUser = request("name");
	$cFlds = array(":name"=>$szUser);
	$cFetched = $pDb->fetch("select expiryTime from hotspotSubscriber where username=:name limit 1", $cFlds);
	if (!$cFetched)
	{
		print "User not found! Aborting.";
		return;
	}

	$szCurrent = !empty($cFetched["expiryTime"])?substr((string)$cFetched["expiryTime"],0,16):"";
	$szNow = date("Y-m-d H:i",time());
	$szBase = strlen($szCurrent)?$szCurrent:$szNow;

	$szOneHour = date('Y-m-d H:i', strtotime($szNow) + 60*60);
	$cParts = explode(":",$szOneHour);
	$szEndOfHour = $cParts[0].":00";
	$sz24Hours = date('Y-m-d H:i', strtotime($szNow) + 60*60*24);
	$szAdd1Hour = date('Y-m-d H:i', strtotime($szBase) + 60*60);
	$szAdd24Hours = date('Y-m-d H:i', strtotime($szBase) + 60*60*24);
	$szSetTimeTo = "";

	if (isset($_POST["now"])) $szSetTimeTo = $szNow;
	if (isset($_POST["hourend"])) $szSetTimeTo = $szEndOfHour;
	if (isset($_POST["onehour"])) $szSetTimeTo = $szOneHour;
	if (isset($_POST["24hours"])) $szSetTimeTo = $sz24Hours;
	if (isset($_POST["add1hour"])) $szSetTimeTo = $szAdd1Hour;
	if (isset($_POST["add24hours"])) $szSetTimeTo = $szAdd24Hours;
	if (isset($_POST["submitTime"])) $szSetTimeTo = trim((string)request("setTo"));

	if (strlen($szSetTimeTo))
	{
		$ts = strtotime($szSetTimeTo);
		if ($ts === false)
		{
			print red("Invalid expiry time.")."<br><br>";
		}
		else
		{
			$szSetTimeTo = date('Y-m-d H:i:s', $ts);
			$cUpdate = array(":name"=>$szUser, ":expiry"=>$szSetTimeTo);
			$pDb->execute("update hotspotSubscriber set expiryTime=:expiry where username=:name", $cUpdate);
			// Keep the explicit legacy RADIUS row synchronized for old reporting paths.
			$pDb->execute("update radcheck set expirytime=:expiry where username=:name and op=':=' and attribute='Cleartext-Password'", $cUpdate);
			requireAccessUpdate();
			$cFetched = $pDb->fetch("select expiryTime from hotspotSubscriber where username=:name limit 1", array(":name"=>$szUser));
			$szCurrent = !empty($cFetched["expiryTime"])?substr((string)$cFetched["expiryTime"],0,16):"";
		}
	}

	$szRows = tr(td("Set subscription expiry time:",2));
	$szRows .= tr(td("Current time:").td($szNow));
	$szRows .= tr(td("Current expiry time:").td((!strlen($szCurrent)?red("Not yet set"):htmlspecialchars($szCurrent))));
	$szRows .= tr(td('<button type="submit" name="now">Expire now</button>').td($szNow));
	$szRows .= tr(td('<button type="submit" name="hourend">End of hour</button>').td($szEndOfHour));
	$szRows .= tr(td('<button type="submit" name="onehour">One hour</button>').td($szOneHour));
	$szRows .= tr(td('<button type="submit" name="24hours">24 hours</button>').td($sz24Hours));
	$szRows .= tr(td('<button type="submit" name="add1hour">+ 1 hour</button>').td($szAdd1Hour));
	$szRows .= tr(td('<button type="submit" name="add24hours">+ 24 hours</button>').td($szAdd24Hours));
	$szRows .= tr(td('<input name="setTo" value="'.htmlspecialchars($szCurrent,ENT_QUOTES).'" width="5"><button type="submit" name="submitTime">Submit time</button>',2));

	print '<form action="index.php?f=users_addtime&name='.rawurlencode($szUser).'" method="post">'.table($szRows).'</form>';
	print '<br><a href="index.php?f=users_show&nm='.rawurlencode($szUser).'">Back to user</a>';
}

?>