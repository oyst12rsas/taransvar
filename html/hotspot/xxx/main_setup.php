<?php


//function setup()
function main_setup()
{
	global $global_PBO_connection;
	
	if (!isSuperUser())
		return;

	$pGkSetup = new CDb;
	
	$cFlds = array();
	if ($cGkSetupFetched = $pGkSetup->fetchNext("select ssid, CAST(requestReboot AS UNSIGNED) as reboot, CAST(requestShutdown AS UNSIGNED) as shutdown from setup", $cFlds))
	{
	}

	if (request("submit") == "1")
	{
		//print h2("Submitted... should save, selfreg=".request("selfreg"));
		$szNewMsg = request("comment");
		$cFlds = array(":msg"=> $szNewMsg, "selfreg" => request("selfreg"), ":subtype" => request("subtype"));
		
		$pDb = new CDb;
		$pDb->execute("update hotspotSetup set loginmsg = :msg, selfreg = :selfreg, defaultSubscriptionType = :subtype", $cFlds);
		
		$szSSID = request("ssid");
		//print "ssid changed? New: -".$szSSID."-, old: -".$cGkSetupFetched["ssid"]."-<br><br>";
		if (strcmp($szSSID, $cGkSetupFetched["ssid"]) !== 0)
		{
			$cFlds = array(":ssid"=> $szSSID);		
			$pDb->execute("update setup set ssid = :ssid",  $cFlds);
			
			print b(red("SSID is changed. To be implemented, the server needs to be restarted.<br><br>"));
			print "<br>".a("Reboot server", "index.php?f=main_reboot")."<br><br>";
		}

		$bNewReboot = (request("reboot") == "on"?1:0);
		//$szNewDbVal = "b'".$bNewReboot."'";
		//$szNewDbVal = $bNewReboot;
		//print "Request: ".$bNewReboot.", in db: ".$cGkSetupFetched["reboot"].", save: ".$szNewDbVal."<br><br>"; 

		if ($bNewReboot != $cGkSetupFetched["reboot"])
		{
			//$cFlds = array(":boot"=> $szNewDbVal);		
			//$pDb->execute("update setup set requestReboot = :boot",  $cFlds);
			$conn = $global_PBO_connection;
			//$cFlds = array(":boot"=> $szNewDbVal);
			$statement = $conn->prepare('update setup set requestReboot = :bool') ;
			$statement->bindValue(':bool', ($bNewReboot?true:false), PDO::PARAM_BOOL);
			$statement->execute() ;

			if ($bNewReboot)
				print b(red("The server is set to be restarted.<br><br>"));
			else
				print b(red("Server reboot is cancelled (unless it's too late).<br><br>"));
			
		}

		$bNewShutdown = (request("shutdown") == "on"?1:0);

		if ($bNewShutdown != $cGkSetupFetched["shutdown"])
		{
			$conn = $global_PBO_connection;
			//$cFlds = array(":boot"=> $szNewDbVal);
			$statement = $conn->prepare('update setup set requestShutdown = :bool') ;
			$statement->bindValue(':bool', ($bNewShutdown?true:false), PDO::PARAM_BOOL);
			$statement->execute() ;

			if ($bNewShutdown)
				print b(red("The server is set to shut down.<br><br>"));
			else
				print b(red("Server shutdown is cancelled (unless it's too late).<br><br>"));
			
		}

		print "New msg saved. This is what it will look like:<br><br> $szNewMsg";
		return;
	}
	
	$cRec = getSetup();
	$szLoginMsg = $cRec["loginmsg"];

	$szRows = tr(td("Login welcome text (displayed below the login box)",2)).
			tr(td('<textarea name="comment" rows="12" cols="75">'.$szLoginMsg."</textarea>",2));
	
	$szRows .= tr(td("NOTE! You can use all normal html tags here. Like &lt;b&gt;<b>to make text bold</b>&lt;/b&gt; or &lt;font color=\"red\"&gt;<font color=\"red\">to make text red</font>&lt;/font&gt;. You can also place pictures or links to advertise your services or products. Google html or check <a href=\"https://www.w3schools.com/tags/tag_font.asp\">W3schools</a> for how use html.",2));
	
	$cElem = array ("none" => "No self registration", 
			"sms" => "Manually send confirmation code by SMS",
			"email"=> "Automatic confirmation cody by e-mail",
			"semiEmail" => "Manually check and send email");
	
	$szDropList = '<select name="selfreg">';
	$szCurrent = $cRec["selfreg"];
	if (!(isset($szCurrent)?strlen($szCurrent):0))
		$szCurrent = "none";

	foreach($cElem as $szKey => $szValue)
	{
		$szDropList .= '<option value="'.$szKey.'" '.($szKey == $szCurrent ? "selected":"").'>'.$szValue.'</option>';
	}
	$szDropList .= "</select>";

	$szSubDropList = getSubscriptionTypeDropList($cRec["defaultSubscriptionType"]);

	$szRows .= tr(td("User self registration:").td($szDropList));
	$szRows .= tr(td("Default subscription:").td($szSubDropList));

	$szRows .= tr(td("SSID:").td("<input name=\"ssid\" value=\"".$cGkSetupFetched["ssid"]."\">"));
	
	$szRows .= tr(td("Reboot ordered:").td("<input type=\"checkbox\" name=\"reboot\" ".($cGkSetupFetched["reboot"]=="1"?"checked":"").">"));
	$szRows .= tr(td("Shutdown ordered:").td("<input type=\"checkbox\" name=\"shutdown\" ".($cGkSetupFetched["shutdown"]=="1"?"checked":"").">"));

	$szRows .= tr(td('<button type="submit">Submit</button>',2));					
	
	print '<form  action="index.php?f=main_setup&submit=1" method="post">'.table($szRows).'</form>';

    print "<br><br>".a("Technical setup", "index.php?f=main_tech");
    print "<br><br>".a("Ticket setup", "index.php?f=main_ticketlayout");
	
}

?>
