<?php

/*
	- db.pl should open access to RADIUS routers based on info in nas table... 
	- Upload Location info to server and valid user names.. 
*/

//No open session found
//Unknown selection
//Doesn't show admin user name even if only....

require_once "radiuslib.php";

class CSystem
{
	function layoutStyle()	{ return "box"; }
	function getLanguageCode() {return "ENG";}
	function getMainMenu()
	{ 
		return array( 
				array("class"=>"mnu_top", "script" => "index.php?f=main", "more"=>"", "txt"=>"Home"),
				array("class"=>"mnu_top", "script" => "index.php?f=users", "more"=>"", "txt"=>"Users"),
				array("class"=>"mnu_top", "script" => "index.php?f=list", "more"=>"", "txt"=>"Lists"),
				array("class"=>"mnu_top", "script" => "index.php?f=logs", "more"=>"", "txt"=>"Logs"),
				array("class"=>"mnu_top", "script" => "index.php?f=radius", "more"=>"", "txt"=>"RADIUS"),
				array("class"=>"mnu_top", "script" => "index.php?f=main_about", "more"=>"", "txt"=>"About")
			); 
	}
	
	function getSideMenu() 
	{
		$cParts = explode("_", get("f"));
		if (sizeof($cParts))
		{
			$szMenu = $cParts[0];
			switch ($szMenu)
			{
				case "main":
					$cArray = array( 
						array("class"=>"mnu_top", "script" => "index.php?f=main_partner", "more"=>"", "txt"=>"Partner")
						);
						
					if (isSuperUser())
					{
						$cArray = array_merge($cArray, array(
									array("class"=>"mnu_top", "script" => "index.php?f=main_setup", "more"=>"", "txt"=>"Setup"),
									array("class"=>"mnu_top", "script" => "index.php?f=fw_db", "more"=>"", "txt"=>"Firewall")
									));
					}
						
					return array_merge($cArray, array(array("class"=>"mnu_top", "script" => "index.php?f=main_log".(loggedIn()?"out":"in"), "more"=>"", "txt"=>"Log ".(loggedIn()?"out":"in"))));
				case "users":
					return array( 
						array("class"=>"mnu_top", "script" => "index.php?f=users_list", "more"=>"", "txt"=>"List"),
						array("class"=>"mnu_top", "script" => "index.php?f=users_add", "more"=>"", "txt"=>"Add"),
						array("class"=>"mnu_top", "script" => "index.php?f=users_groups", "more"=>"", "txt"=>"Groups"),
						array("class"=>"mnu_top", "script" => "index.php?f=users_active", "more"=>"", "txt"=>"Active"),
						array("class"=>"mnu_top", "script" => "index.php?f=users_sess", "more"=>"", "txt"=>"Sessions"),
						array("class"=>"mnu_top", "script" => "index.php?f=users_usage", "more"=>"", "txt"=>"Usage")
						
					); 
				case "list":
					return array( 
						array("class"=>"mnu_top", "script" => "index.php?f=main_partner", "more"=>"", "txt"=>"Partner"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??")
					); 
				case "logs":
					if (isSuperUser())
						return array( 
							array("class"=>"mnu_top", "script" => "index.php?f=logs_status", "more"=>"", "txt"=>"Status"),
							array("class"=>"mnu_top", "script" => "index.php?f=logs_load", "more"=>"", "txt"=>"Load"),
							array("class"=>"mnu_top", "script" => "index.php?f=logs_syslog", "more"=>"", "txt"=>"Syslog"),
							array("class"=>"mnu_top", "script" => "index.php?f=uselog_menu", "more"=>"", "txt"=>"Use logs")
							);
					else
						return array();
				case "radius":
					return array( 
						array("class"=>"mnu_top", "script" => "index.php?f=radius_wifi", "more"=>"", "txt"=>"Routers"),
						array("class"=>"mnu_top", "script" => "index.php?f=radius_users", "more"=>"", "txt"=>"Users"),
						array("class"=>"mnu_top", "script" => "index.php?f=radius_auth", "more"=>"", "txt"=>"Authentication log"),
						array("class"=>"mnu_top", "script" => "index.php?f=radius_acct", "more"=>"", "txt"=>"Accounting")
					); 
				case "fw":
					return array( 
						array("class"=>"mnu_top", "script" => "index.php?f=fw_db", "more"=>"", "txt"=>"Dashboard"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??"),
						array("class"=>"mnu_top", "script" => "index.php?f=main_xxx", "more"=>"", "txt"=>"??")
					); 
					
				case "uselog":
					return array( 
						array("class"=>"mnu_top", "script" => "index.php?f=uselog_menu", "more"=>"", "txt"=>"Main"),
						array("class"=>"mnu_top", "script" => "index.php?f=uselog_last", "more"=>"", "txt"=>"Last reported"),
						array("class"=>"mnu_top", "script" => "index.php?f=uselog_arin", "more"=>"", "txt"=>"ARIN Lookups"),
						array("class"=>"mnu_top", "script" => "index.php?f=uselog_xxx", "more"=>"", "txt"=>"??")
					); 
					
					
				default: 
					return array();
			}
	
		}
	}
}

$pGlobalSystem = false;

class CXmlCommand {
	static function alert($szTxt) {print $szTxt;}
	static function flushXml() {}
}

function getSystem()
{
	global $pGlobalSystem;
	if (!$pGlobalSystem)
		$pGlobalSystem = new CSystem;
	return $pGlobalSystem; 
}

/*function getTxt($szTxt)
{	
	return $szTxt; //Supposed to handle translations
}*/

$bLoginFormPrinted = false;

function doLogin()
{
	global $bLoginFormPrinted;
	if ($bLoginFormPrinted)
		return;
	else
		$bLoginFormPrinted = true;
	
	//print "Your IP address is ".$_SERVER['REMOTE_ADDR']."<br>(".(isInternal()?"Inside firewall": "Outside firewall").")<br>";
	
/*	if (!isset($_GET["theCode"]))
	{
		print "Not logged in!!!";
		exit(0);
	}*/
	
	
	if (!isset($_GET["theCode"]) || (htmlspecialchars($_GET["theCode"]) != "hereIam"))
	{
		print "Please login";
		$szRows = tr(td("Username").td('<input name="name">')).
			tr(td("Password").td('<input name="pass" type="password">'));

		$szRows .= tr(td('<button type="submit">Submit</button>',2));					
		
		//Check if initial login
		$cFlds = array();
		$nUsers = CDb::getString("select count(*) from radcheck",$cFlds);
		if ($nUsers == 1 && onServer())//$_SERVER['REMOTE_ADDR'] == "127.0.0.1"))
		{
			$pDb = new CDb;
			$cRec = $pDb->fetch("select username, value from radcheck",$cFlds);
			$nUsers = CDb::getString("select count(*) from radcheck",$cFlds);
			$szTxt = "<br><br><b>NOTE! We have registered one test user for you.</b><br><br>
					Username: ".$cRec["username"]."<br>
					Password: ".$cRec["value"]."<br><br>
					You should not give this username to others, but rather click add user to register new users.<br><br>
					Good luck testing the system.<br><br>This message will disappear when you've registered new user. It will also only appear on this computer (and not from other computers you connect)<br>";
			$szRows .= tr(td($szTxt,2));
		}
		else
		{
			$szLoginMsg = CDb::getString("select loginmsg from hotspotSetup", $cFlds);
		
			$szTxt =  "<br>".$szLoginMsg; //If you don't yet have a user name, then you can obtain one by contacting the owner of this WiFi router. You may be granted a number of MB or unlimited access for a given time."; 
			$szRows .= tr(td($szTxt,2));
		}
		
		print '<form  action="index.php?f=main_subLogin" method="post">'.table($szRows).'</form>';

		//print "Wrong login!";
		
		
		
		return;//exit(0);
	}
	else
	{
		$_SESSION["loggedin"] = true;
		$_SESSION["superuser"] = true;
		print h1("Superuser login registered!");
		print "The code is: ".htmlspecialchars($_GET["theCode"])."<br>";
		unset($_SESSION["user"]);
	}
}

function createSessionAndGrantAccessTo($szName)
{
	//Set previous sessions inactive...
	setIpInactive($_SERVER['REMOTE_ADDR']);

	//Register session
	$pDb = new CDb;
	$cParam = array(":ip" => $_SERVER['REMOTE_ADDR'], ":username" => $szName);
	$pDb->execute("insert into session (ip, username, active) values (:ip, :username, 1)", $cParam);

	//Check give access
	//if (doGrantAccessOk($szName, $nSecondsUntilUpdate))
	if (grantedAccess($szName, $nSecondsUntilUpdate))	//Call this because checking if user has quota.....
	{
		//Printed by doGrantAccess: print h1("You will have access in $nSecondsUntilUpdate seconds.");
	}
	else
		if (!onServer())
			print "<font color=\"red\">You are logged in here, but your subscription is expired or no more quota, so access will not yet be granted. Please contact supervisor for instructions.</font>";
		else
			print "You're on the server, so no access is changed..";
}


function getSenderIp()
{
	if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	} else {
    		$ip = $_SERVER['REMOTE_ADDR'];
 	}
 
 	if ($ip == "::1")	//inet6 - sometimes receives
 	{
	 	$ip = "127.0.0.1";
 	}
 	return $ip;
} 

function beingAttacked($bBefore)
{
	$cFlds = array();
	$szRows = "";
	$pDb = new CDb;	//Note, can't use db from caller... Result there gets distorted.
	if ($cFetched = $pDb->fetchNext("select count(*) as fails from loginAttempt where theTime > date_sub(now(), INTERVAL 10 MINUTE)", $cFlds))
	{
		$nLoginFails = $cFetched["fails"];
		if (!$bBefore && $nLoginFails > 5)
			print table(tr(td(red("$nLoginFails failing attempts to log in last 10 minutes. Suspending if more than 10.<br><br>"))));
		
		return ($nLoginFails > 10);
	}
	return 0;
}

function submitLogin($bBefore)
{
//	if (!$bBefore)
//		print "<h1>Supposed to submit login</h1>";
//	else
//		print "In submit";
		
	
		
	$szName = request("name");
	$szPass = request("pass");
	//print "Name: $szName<br>Pass: $szPass";

	if (beingAttacked($bBefore))
	{
		//print red("This Hotspot is currently under bruteforse attack so all login is disabled.");
		return;
	}

	$pDb = new CDb;
	$cFlds = array(":name"=>$szName);
	$szRows = "";
	if ($cFetched = $pDb->fetchNext("select value, confirmedTime, subscriptionType, expirytime, giveHoursAfterLogin from radcheck where username = :name and op = ':=' and attribute = 'Cleartext-Password'", $cFlds))
	{
		$szDbPass = $cFetched["value"];
		//print "From db: $szDbPass";
		
		if ($szPass != $szDbPass)
		{
			if (!$bBefore)
			{
				if (0)//onServer()) 
				{
					print red("Wrong user name/password, but it's not being recorded because you're on the server.");
				}
				else
				{
					$szSenderIp = getSenderIp();
					//print "IP: ".$szSenderIp."<br>";
					$cFlds = array(":name"=>$szName, ":pass"=>$szPass, ":ip"=>$szSenderIp);
					$pDb->doExec("insert into loginAttempt (userName, password, ip) values (:name, :pass, inet_aton(:ip))", $cFlds);
				}
				
				//print red("Sorry, error in username or password! ($szPass != $szDbPass)<br><br>");
				print red("Sorry, error in username or password!<br><br>");
				doLogin();
				//exit(0);
			}
			//else 
			//	print "wrong..";
		}
		else
		{
			if (!strlen($cFetched["confirmedTime"]))
			{
				if (!$bBefore)
				{
					print red("This user name is not yet confirmed. Please type confirmation code.");
					$szRows = tr(td("Confirmation code:").td('<input name="code"></input>'));
					$szRows .= tr(td('<button type="submit">Submit</button>',2));					
					$szRows .= tr(td("You may have received confirmation code to email or SMS depending on the current policy.",2));					
					print '<form  action="index.php?f=main_confCode&name='.$szName.'&pass='.$szPass.'" method="post">'.table($szRows).'</form>';
				}
				return;
			}
			
		
			if ($bBefore)
			{
				setLoggedIn($szName);
				//print "... session set..";
			}
			else
			{
				if (!strlen((isset($cFetched["expirytime"])?$cFetched["expirytime"]:"")) && ($cFetched["subscriptionType"] == "limited" || $cFetched["subscriptionType"] == "expiry"))
				{
					$cFlds = array(":user"=>$szName,":hours"=>$cFetched["giveHoursAfterLogin"]);
					$pDb->execute("update radcheck set expirytime = DATE_ADD(Now(), INTERVAL :hours HOUR) where username = :user", $cFlds);
				}
			
				createSessionAndGrantAccessTo($szName);
			}
		}
	}
	else
	{
		if (!$bBefore)
		{
			print red("Error in username or password!")."<br><br>";
			doLogin();
			//exit(0);
		}
	}
}


function listUsers()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	//print "In the function";
	//return;

	$pDb = new CDb;
	
	if (!$pDb)
	{
		print "Unable to login!";
		return;
	}
	
	$cFlds = array();
	$szRows = "";


	$szSQL = "select username, value as password, mbquota, round(mbusage,1) as theusage, if(isnull(confirmedTime),confirmCode,'') as theCode, subscriptionType, expirytime from radcheck order by username";
				/*.....,theusage from radcheck   left outer join 
					(select user, sum(theusage) as theusage from (			
						select user, left(yyyymmddhh,10) as thedate, max(mb) as theusage 
							from userusage group by user, left(yyyymmddhh,10) ) as t1 group by user 
					) as t2 on radcheck.username = t2.user;";*/


	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
	{
		$szSubType = $cFetched["subscriptionType"];
		
		switch ($szSubType)
		{
			case "quota":
				$szSubField = td(($cFetched["mbquota"]>0?$cFetched["mbquota"]:red("No Quota")),1,'align="right"').td($cFetched["theusage"],1,'align="right"').td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
				break;
			case "expiry":
				$szSubField = td((!strlen($cFetched["expirytime"])?red("Expiry not set"):substr($cFetched["expirytime"],0,16))).td($cFetched["theusage"],1,'align="right"').td(a("[Add time]",func("users_addtime&name=".$cFetched["username"]))); 
				break;
			case "limited":
				$bMoreQuota = ($cFetched["mbquota"]+0 > $cFetched["theusage"]+0);
				$szTxt = ($bMoreQuota?$cFetched["mbquota"]."MB":red("Quota used"));
				
				if ($bMoreQuota)
					$szTxt .= "/".(!strlen($cFetched["expirytime"])?red("Expiry not set"):(!expired($cFetched["expirytime"])?substr($cFetched["expirytime"],0,16):red("Expired")));
				
				$szSubField = td($szTxt,1,'align="right"').td($cFetched["theusage"],1,'align="right"').td(a("[Add quota]",func("users_addquota&name=".$cFetched["username"])));
			
				break;
			default: $szSubField = td(red("Invalid subscription type"),3);
		}
	
		
		$szRows .= tr(td(a($cFetched["username"],"index.php?f=users_show&nm=".$cFetched["username"])).td("*****").$szSubField.
				td(a("[Delete]","index.php?f=users_deluser&unm=".$cFetched["username"])).
				td($cFetched["theCode"]) 
				);
	}
	
	print h1("Registered users:").table(tr(th("User name").th("Password").th("Quota/Expiry").th("Used").th("&nbsp;").th("&nbsp;").th("Code")).$szRows);
	
	//NOTE RESULTS IN ABORT..: delete $pDb;
	
}

function listClients()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	
	if (!$pDb)
		return;
	
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select * from nas", $cFlds))
		$szRows .= tr(td($cFetched["nasname"]).td($cFetched["shortname"].td($cFetched["secret"])));

	$szRows .= tr(td("<br>".a("Add new router", "index.php?f=radius_addclient"),3));
	$szRows .= tr(td("<br><br>All WiFi routers that are using RADIUS (Wpa Enterprise) authentication have to be registered here. For other routers, there's no point registering them here. The only think you should do is to disable the DHCP server function, otherwise all users connected through that router will share one user name and quota.<br>",3));

	print h1("Registered Routers:").table(tr(th("IP").th("Nick name").th("Secret")).$szRows);
	
}

function listAccounting()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select * from radacct order by radacctid desc limit 50", $cFlds))
		$szRows .= tr(td($cFetched["radacctid"]).td($cFetched["acctsessionid"]).td($cFetched["username"]).td($cFetched["acctstarttime"]).td($cFetched["acctstoptime"]).td($cFetched["acctsessiontime"]).
					td($cFetched["acctinputoctets"]).td($cFetched["acctoutputoctets"]));

	$szHeading = tr(th("Id").th("Session").th("User").th("Start").th("Stop").th("Time").th("Download").th("Upload"));
	print h1("Accounting info:").table($szHeading.$szRows);
}


function printMenu()
{
		
	print "<br>User: ".(isset($_SESSION["user"]) ?$_SESSION["user"]:"Superuser")."<br><br>";

	if (isSuperUser())
		$cSuperMenu = array(
			a("List users", "index.php?f=users_list"), 
			a("Add user", "index.php?f=users_add"),
			a("Authentication log", "index.php?f=radius_auth"),
			a("Sessions", "index.php?f=users_sess"),
			a("List wi-fi routers", "index.php?f=radius_wifi"),
			a("List accounting", "index.php?f=radius_acct"),
			a("Active users", "index.php?f=users_active")
			);
	else
		$cSuperMenu = array();
	
	$cMenu = array_merge($cSuperMenu, array(
			a("List usage", "index.php?f=users_usage"),
			a("Log out", "index.php?f=main_logout")
			));
		
		
	print ul($cMenu);
}

function addUser()
{
	$func = request("f");
	
	if ((!selfRegistrationEnabled() || !strcmp($func, "users_add")) && !isSuperUser())
		return; 	//Should report hacking..

	print "Register new user<br><br>";
	$szRows = tr(td("Username").td('<input name="name" maxlength="20" value="'.request("name").'">')).
			tr(td("Password").td('<input name="pass" maxlength="20" value="'.request("pass").'">')).
			tr(td("Confirm").td('<input name="conf" maxlength="20" value="'.request("conf").'">')).
			tr(td("Email").td('<input name="email" maxlength="100" value="'.request("email").'">')).
			tr(td("Phone#").td('<input name="phone" maxlength="100" value="'.request("phone").'">'));
	
	if (isSuperUser())
	{
		$cSetup = getSetup();
		$szRows .= tr(td("Type").td(getSubscriptionTypeDropList($cSetup["defaultSubscriptionType"])));
		$szRows .= tr(td("Quota").td('<input name="quota" value="'.request("quota").'">'));
		$szExpiry = (strlen($szDate = request("expiry"))?$szDate:date("Y-m-d H:i",time()));
		$szRows .= tr(td("Expiry").td('<input name="expiry" value ="'.$szExpiry.'">')); 
	}		
			
	print '<form  action="index.php?f=users_addSub&f2='.$func.'" method="post">'.table($szRows.tr(td('<button type="submit">Submit</button>','colspan="2"'))).'</form>';
	//print form($szRows, $szFunc="subuser", $nId=0, $szFlds="", $bSubmitOnNewRow = true, $bAddSubmit=true, $szSubButtonLabel = "");
	
}

function verifyDate($date, $strict = true)
{
	//print "The date: $date<br>";
	$dateTime = DateTime::createFromFormat('+Y-m-d H:i', $date);
	if ($dateTime === false || $strict) 
	{
		$errors = DateTime::getLastErrors();
		//print_r($errors);

		if (!empty($errors['warning_count'])) 
			return false;
	}
	return $dateTime !== false;
}

function okExpiry()
{
	$szExpiry = request("expiry");
	$pDb = new CDb;
	$cFlds = array(":date"=>$szExpiry);
	$szRows = "";
	if ($cFetched = $pDb->fetchNext("select str_to_date(:date, 'YYYY-MM-DD hh:mm:ss') as reply", $cFlds))
	{
		if ($cFetched["reply"]+0 == 1)
			return 1;
	}
	return 0;
}

function okQuota($szQuota, $szSubtype)
{
	print "Subtype: $szSubtype<br>";

	switch ($szSubtype)
	{
		case "limited":
			if (okExpiry()) {
				return false;
			}

		case "quota":
			if (preg_match('/(\d+)(\s*)(MB|GB|TB|)$/i', $szQuota, $cMatches))
			{
				$szNewQuota = $cMatches[1];
				$szPostfix = $cMatches[2];
				if (trim($szPostfix) == "" && sizeof($cMatches) > 3)
					$szPostfix = $cMatches[3];
				switch (strtoupper($szPostfix))
				{
					case "MB":
						break;	//default..
					case "TB":
						$szNewQuota *= 1024;
						//Don't break... multiply with 1024 again...
					case "GB": 
						$szNewQuota *= 1024;
						break;
				}
				
				//print "Matched: $szNewQuota<br>0: ".$cMatches[0]."<br>Postfix: ".$szPostfix."<br>";
				return $szNewQuota+0;
			}
			//else
				//print red("No match!<br>");
			break; 	//ØT 250313
		case "expiry":	//ØT 250313 - section wasn't here...
			//Check if "expiry" holds legal date in db format...
			return okExpiry();
	}
	
	//print $szSubtype."<br>";
	return false;
}


function userSubmitted()
{
	$szOrigFunc = request("f2");
	
	if ((!selfRegistrationEnabled() || !strcmp(request("f"), "users_add")) && !isSuperUser())
		return; 	//Should report hacking..

	$szName = trim(request("name"));
	$szPass = trim(request("pass"));
	
	if ($szName == "" or strlen($szPass) < 4)
	{
		print red("User name can't be blank and password must have at least 4 chars. You should be careful, or others may steal your data.")."<br><br>";
		addUser();
		return;
	}
	
	$szConf = request("conf");
	$szEmail = urlencode(request("email"));
	$szPhone = urlencode(request("phone"));
	
	if (strcmp($szPass,$szConf))
	{
		print red("Passwords don't match. Please try again...")."<br><br>";
		addUser();
		return;
	}

	if (isSuperUser())
	{
		$szSubtype = request("subtype");
        $szQuota = okQuota(urldecode(request("quota")), $szSubtype);

		if ($szQuota === false)
		{
			$_POST["f"] = $_GET["f"] = $szOrigFunc;
			print red("Quota must be blank or integer (megabytes of data to grant)<br><br>");
			addUser();
			return;
		}
		
		$szExpiry = urldecode(request("expiry"));
        //print "Quota: $szQuota<br>";
		
		if (!verifyDate($szExpiry))
		{
			$_POST["f"] = $_GET["f"] = $szOrigFunc;
			print red("Invalid date!<br><br>");
			addUser();
			return;
		}
		
		
		$szUrlAdd = "&quota=$szQuota&expiry=$szExpiry&subtype=$szSubtype";
	}	
	else
		$szUrlAdd = "";
	

	print "<br>".h1("NOTE! Click link below to submit:");
	print table(tr(td("User name:").td($szName)).
		tr(td("Password:").td(urldecode($szPass))).
		tr(td("Email:").td(urldecode($szEmail))).
		tr(td("Phone:").td(urldecode($szPhone)))
		);
	//print "<br>User name: ".$szName."<br>";
	//print "Password: ".$szPass."<br><br>";
	print a("Click here to confirm registration of new user","index.php?f=users_confSub&name=$szName&pass=$szPass&email=$szEmail&phone=$szPhone$szUrlAdd&f2=".$szOrigFunc);

}

function confUserSubmitted()
{
	$szOrigFunc = request("f2");
	
	if ((!selfRegistrationEnabled() || !strcmp(request("f"), "users_add")) && !isSuperUser())
		return; 	//Should report hacking..

	$szName = request("name");
	$szPass = request("pass");

	$pDb = new CDb;
	
	if (!$pDb)
		return;
	
	$cFlds = array(":user"=>$szName);
	$szRegisterd = $pDb->getString("select username from radcheck where username = :user",$cFlds);
	if (strlen($szRegisterd))
	{
		print red("$szName is already registered. Please choose another name");
		addUser();
		return;
	}
	//else
	//	print "$szName is not yet registered<br>";

	$szEmail = request("email");
	$szPhone = request("phone");

	$cParam = array(":name" => $szName, ":pass" => $szPass, ":email"=>$szEmail, ":phone"=>$szPhone);

	if (isSuperUser())
	{
		$szQuota = request("quota");
		$szExpiry = urldecode(request("expiry"));
		$szSubtype = request("subtype");
		print "Expiry: $szExpiry<br>";
        print "Quota: $szQuota<br>";
		
		$cParam = array_merge($cParam, array(":quota" => $szQuota, ":expiry" => $szExpiry, ":subtype" => $szSubtype));
		$szExtraFlds = ", mbquota, expirytime, subscriptionType, confirmedTime";
		$szExtraVals = ", :quota, :expiry, :subtype, now()";
		//$cParam = array_merge($cParam, array(":quota" => $szQuota));
		//$szExtraFlds = ", mbquota = :quota";
		
		//print_r ($cParam);
	}	
	else
		$szExtraFlds = $szExtraVals = "";
	

	$szSQL = "insert into radcheck (username, attribute, op, value, email, phone, confirmCode $szExtraFlds) values (:name, 'Cleartext-Password', ':=', :pass, :email, :phone, LEFT(UUID(), 5) $szExtraVals)";
	//print "SQL: $szSQL<br>";
	$pDb->execute($szSQL, $cParam);

	$cParam = array(":name" => $szName);
	$pDb->execute("insert into radusergroup(username, groupname, priority) values (:name, 'thisgroup', 1)", $cParam);
	
	print "<br>".h1("User registered!")."<br><br>";
	print table(tr(td("User name:").td($szName)).
		tr(td("Password:").td(urldecode($szPass))).
		tr(td("Email:").td(urldecode($szEmail))).
		tr(td("Phone:").td(urldecode($szPhone)))
		);
	//print h1("User is saved!");

	if (!loggedIn())
	{
		print "<br>Thank you for registering. Before using this username, it has to be confirmed through email or SMS.<br>";
	}
}

function showAuthenticationLog()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	
	if (!$pDb)
		return;
	
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select * from radpostauth order by id desc limit 10", $cFlds))
		$szRows .= tr(td($cFetched["username"]).td($cFetched["pass"]).td($cFetched["reply"]).td($cFetched["authdate"]));
	
	print h1("Authentication log:").table(tr(th("Username").th("Password").th("Reply").th("Timestamp")).$szRows);
	
}

function deleteUser()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$szName = request("unm");
	
	if (request("conf") == "1")
	{
		$pDb = new CDb;
		$cFlds = array(":user"=>$szName);
		$nAcct = $pDb->getString("select count(*) from radacct where username = :user", $cFlds);
		$nUsage = $pDb->getString("select count(*) from userusage where user = :user", $cFlds);
		$nSessions = $pDb->getString("select count(*) from session where username = :user", $cFlds);
		
		if ($nAcct+$nUsage+$nSessions)
			h1("You may not delete this user because there is registered one or more logins. Better create new user");
		else
		{
			$pDb->execute("delete from radcheck where username = :user", $cFlds);
			$pDb->execute("delete from radusergroup where username = :user", $cFlds);
			h1("User is deleted");
		}
	}
	else
	{
		print "Supposed to let you delete $szName<br><br>";
		print a("Click here to confirm deletion of $szName", "index.php?f=users_deluser&unm=$szName&conf=1")."<br>";
	}

}

function addClient()
{
	print h1("Add new router");
	if ($szSecret = request("secret") == "")
	{
		$szRows = tr(td("IP (as seen from the RADIUS SERVER):").td('<input name="ip">')).
			tr(td("Nickname (too be seen in logs - should be short and unique):").td('<input name="name">')).
			tr(td("Secret (to be specified in the Wi-Fi router setup):").td('<input name="secret">'));
			
		$szExplanationRow= tr(td("<br>Regarding IP address: You should normally put the RADIUS server inside your own network. This means that the IP address you use here will be the same as the IP address you type when changing the Router setup. You can also set up a network of several Wi-Fi routers, all connected to one Internet gateway. In such cases, the RADIUS server will be outside the various Wi-Fi routers and you must specify the WAN IP address of the Wi-Fi router. You'll find it in the router setup.", 3));
			
		print '<form  action="index.php?f=radius_addclient" method="post">'.table($szRows.tr(td('<button type="submit">Submit</button>',2)).$szExplanationRow,'width="800"').'</form>';
	}
	else
	{
		$szIP = request("ip");
		$szName= request("name");
		$szSecret= request("secret");
		
		if (request("conf") == "1")
		{
			$pDb = new CDb;
			$cParam = array(":ip" => $szIP, ":name" => $szName, ":secret" => $szSecret);
			$pDb->execute("insert into nas (nasname, shortname, type, secret, description) values (:ip, :name, ';other', :secret, 'RADIUS Client')", $cParam);
			$szConfirmsString = b("New router saved!");
		}
		else
			$szConfirmsString = a("Click here to confirm saving new router", "index.php?f=radius_addclient&conf=1&ip=$szIP&name=$szName&secret=$szSecret");
	
		print table(tr(td("IP:").td($szIP)).
			tr(td("Nickname:").td($szName)).
			tr(td("Secret:").td($szSecret)).
			tr(td($szConfirmsString,2)));
			
		
	}
	
}

function listUsage()
{
	if (!loggedIn())
		return;

	if (isSuperUser())
		$szWhere = "user = :user or user <> 'ertyljrltyjrwerhihiwehrwekrhwekr'";
	else
		$szWhere = "user = :user";
	
	print h1("Data usage");
	$pDb = new CDb;
	
	$cFlds = array(":user"=>$_SESSION["user"]);
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select user, ip, yyyymmddhh, round(mb,1) as mb from userusage where $szWhere order by yyyymmddhh desc, ip limit 100", $cFlds))
		$szRows .= tr(td($cFetched["user"]).td($cFetched["ip"]).td($cFetched["yyyymmddhh"]).td($cFetched["mb"]));//.td(a("[Delete]","index.php?f=users_deluser&unm=".$cFetched["username"]))
	
	print table(tr(th("User name").th("IP").th("Time").th("MB")).$szRows);
	
}

function listSessions()
{
	if (!isSuperUser())
		return; 	//Should report hacking..
	print h1("Sessions");
	$pDb = new CDb;
	
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select s.*, hasAccess from session s left outer join access a on s.ip = a.ip order by sessionid desc limit 30", $cFlds))
		$szRows .= tr(td($cFetched["username"]).td($cFetched["ip"]).td($cFetched["logintime"]).td($cFetched["lastrequest"]).td($cFetched["active"]).td($cFetched["hasAccess"]));//.td(a("[Delete]","index.php?f=users_deluser&unm=".$cFetched["username"]))
	
	print table(tr(th("User name").th("IP").th("Login Time").th("Last Request").th("Active").th("HasAccess")).$szRows);
}

function listActiveUsers()
{
	if (!isSuperUser())
		return; 	//Should report hacking..
	print h1("Users with access:");
	$pDb = new CDb;

	if (isset($_GET["toggle"]))
	{
		$szIP = request("ip");
		$cParam = array(":ip" => $szIP);
		$pDb->execute("UPDATE access SET hasaccess = IF(hasaccess=1, 0, 1) where ip = :ip;", $cParam);
				
		$szUpdateString = "<br><br>".a("Update accessinfo on server", func("users_upload&ip=$szIP"))."<br><br>NOTE!Access will be updated within an hour unless you click to update now";
	}
	else
		$szUpdateString = "";

	$pListDb = new CDb;
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pListDb->fetchNext("select * from access", $cFlds))
		$szRows .= tr(td($cFetched["ip"]).td($cFetched["hasaccess"])
				); //td(a("[Toggle]","index.php?f=users_active&toggle=1&ip=".$cFetched["ip"]))
	
	$szRows = tr(th("IP").th("Access")).$szRows;	//.th("Toggle")
	$szRows .= tr(td($szUpdateString,3));
	//$szRows .= tr(td(a("Click here to update",""),3));
	//$szRows .= tr(td(,3));

	print table($szRows);
	print "<br>NOTE! This is a temporary list, that will be updated automatically every minute<br>";
}

function doWriteAccessInfoOk($szIp, $nHasAccess, &$nSecondsUntilUpdate)
{
	if (0)//Don't do this anymoe.. !file_put_contents ("temp/giveTheseAccess.txt", "$szIp $nHasAccess\n", FILE_APPEND))
		return false;
	else
	{
		$nSecondsUntilUpdate = getSecondsTillUpdate();
		return true;
	}
}

function uploadAccessInfo()
{
	$szIp = request("ip");
	$pDb = new CDb;
	$cFlds = array(":ip"=>$szIp);
	if ($cFetched = $pDb->fetchNext("select hasaccess from access where ip = :ip", $cFlds))
	{
		$nHasAccess = $cFetched["hasaccess"];
		if (!doWriteAccessInfoOk($szIp, $nHasAccess, $nSecondsUntilUpdate))
			print h1("Error writing to file!");
		else
		{
			print h1("Access should be updated in <span id=\"countdowntimer\">$nSecondsUntilUpdate</span> seconds");
			printCountDownJs($nSecondsUntilUpdate);
			printHowToGetOnlineHelp();
		}
	}
	else
		print h1("Error. Wrong IP address!");
	
}

function printHowToGetOnlineHelp()
{
	print "<br><b>NOTE!</b><br><br>
		Even if you're given access by our system, your browser probably doesn't know that yet. The easiest way is to open a new browser window and type the address of the web page you want to visit (pressing reforesh - F5 might also work). If you still have problem, you can try to open a web page that you have not yet visited. This to ensure that the browser is not reading the web page from the broser cashing.<br><br>You can try one of these:"; 
	print "<ul><li>".a("Google.com", "http://google.com")."</li>";
	print "<li>".a("BBC.com", "http://bbc.com")."</li>";
	print "<li>".a("CNN.com", "http://cnn.com")."</li>";
	print "<li>".a("Facebook.com", "http://facebook.com")."</li>";
	print "</ul>";
		
}

function getAddQuotaForm($szName, $bSubmitSameLine=false, $bIncludeUsername=true)
{
	if ($bIncludeUsername)
		$szRows = tr(td("Username").td($szName));
	else
		$szRows = "";
	
	$szRow = td("Add quota (in MB)").td('<input name="quota">');
	$szSubmit = '<button type="submit">Submit</button>';
	
	if ($bSubmitSameLine)
		$szRows = tr($szRow.td($szSubmit));
	else
		$szRows = tr($szRow).tr(td($szSubmit,'colspan="2"'));
	
	return '<form  action="index.php?f=users_subQuota&name='.$szName.'" method="post">'.table($szRows).'</form>';
}

function addQuota()
{
	$szName = request("name");
	print "Please specify quota to be added:";
	print getAddQuotaForm($szName, $bSubmitSameLine=false);
}

function getCurrentQuota($szName)
{
	$cFlds = array(":name"=>$szName);
	$pDb = new CDb;
	$cRec = $pDb->fetch("select coalesce(mbquota,0) as mbquota, round(coalesce(mbusage,0),1) as mbusage, expirytime, subscriptionType from radcheck where username = :name", $cFlds);
	
	/*$szSQL = "select theusage from (
			select user, sum(theusage) as theusage from (
			select user, left(yyyymmddhh,10) as thedate, max(mb) as theusage 
				from userusage where user = :name group by user, left(yyyymmddhh,10)
			) as t1 group by user
			) as t2";*/
	//$szUsed = $pDb->getString($szSQL, $cFlds);

	if ($cRec["subscriptionType"] == "expiry")
	{
		$szNow = getNow(); 
		$bHasAccess = (strcmp($cRec["expirytime"], $szNow) > 0);
	}
	else
		$bHasAccess = ($cRec["mbquota"] > $cRec["mbusage"]);

	return array("quota"=>$cRec["mbquota"], "used"=> $cRec["mbusage"], "access" => $bHasAccess);
}

function doGrantAccessOk($szName, &$nSecondsUntilUpdate)
{
	global $szInternalIpRange;
	//Select internal IP addresses last known to be used by this user.. and enable them all.
	$szSQL = "select ip from session where ip like '$szInternalIpRange%' and username = :user and TIMESTAMPDIFF(HOUR,CURRENT_TIMESTAMP(),lastrequest)< 5";
	//$pDb = new CDb;
	$pUpdateDb = new CDb;
	
	/*$cFlds = array(":user"=>$szName);
	$szRows = "";
	$bOk = false;
	$nSecondsUntilUpdate = "???";
	$nFound = 0;
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
	{
		$szIp = $cFetched["ip"];
		$szRows .= $szIp.", ";
		$bOk |= doWriteAccessInfoOk($szIp, $nHasAccess = 1, $nSecondsUntilUpdate);
		$cParam = array(":ip" => $szIp);
		$pUpdateDb->execute("insert into access (ip, hasaccess) values (:ip,1) on duplicate key update hasaccess = 1;", $cParam);
	}
	
	if (isSuperUser())
		print "<br>Superuser so printing:<br><br>Should give access to: $szRows<br><br>";	

	//if (!$nFound)
	//{
	//print red("\nThis user is currently not logged in. Access will be granted upon next login.\n");
	//Not yet any session, so grant access on the computer being used... Should do that no matter what computer... Because it may be other computer this time....
	*/
	$cParam = array(":ip" => $_SERVER['REMOTE_ADDR']);
	$pUpdateDb->execute("insert into access (ip, hasaccess) values (:ip,1) on duplicate key update hasaccess = 1;", $cParam);
	//}
	$bOk = doWriteAccessInfoOk($_SERVER['REMOTE_ADDR'], $nHasAccess = 1, $nSecondsUntilUpdate);
	
	//180418
	//print "<br>Thank you for logging in! You should have access in <span id=\"countdowntimer\">$nSecondsUntilUpdate</span> seconds.<br>";
	print "<br>Thank you for logging in! You should have access in less than 10 seconds.<br>";
	//printCountDownJs($nSecondsUntilUpdate, "countdowntimer");
	printHowToGetOnlineHelp();

	if (!isSuperUser())
		printAd();

	requireAccessUpdate();

	return $bOk;
}


function grantedAccess($szName, &$nSecondsUntilUpdate)
{
	$cQuota = getCurrentQuota($szName);	

	//if ($cQuota["quota"]+0 > $cQuota["used"]+0)
	if ($cQuota["access"])
	{
		return doGrantAccessOk($szName, $nSecondsUntilUpdate); //May fail if we don't know what IP the user is on..
	}		
	else
	{
		return false;
	}

}


function submitQuota()
{
	print h1("Supposed to submit quota");
	$szQuota = request("quota")+0;
	$szName = request("name");

	if (request("conf")+0)
	{
		$szConfLine = green(b("Quota has been updated"));
		$cParam = array(":name" => $szName);//, "addQuota"=>$szQuota);
		$pDb = new CDb;
		$pDb->execute("UPDATE radcheck SET mbquota = ifnull(mbquota,0)+$szQuota where username = :name;", $cParam);
		$szQuota .= " has been added.";

		if (grantedAccess($szName, $nSecondsUntilUpdate))
			$szConfLine .= "<br><br>And make sure user has access. Server will be updated in $nSecondsUntilUpdate seconds.</br>";
		else
			$szConfLine .="<br><br>Still not enough quota. The user will not be granted access.<br>";
	}
	else	
		$szConfLine = a("Click here to add this?",func("users_subQuota&conf=1&name=$szName&quota=$szQuota")); 

	$cQuota = getCurrentQuota($szName);	

	print table(tr(td("Name").td($szName)).
			tr(td("Total quota until now").td($cQuota["quota"])).
			tr(td("Total used until now").td($cQuota["used"])).
			tr(td("To add now").td($szQuota)).
			tr(td($szConfLine,2)));
			
}

function setIpInactive($szIP)
{
	$pDb = new CDb;
	$cFlds = array(":ip"=>$szIP);
	$pDb->execute("update session set lastrequest = now(), logouttime = now(), active = 0 where active = 1 and IP = :ip", $cFlds);
	
	//print "Logging out for $szIP<br>"; - Don't do this... function also used to end previous session...????
}

function logOut($bBefore)
{
	if ($bBefore)
	{
		unset($_SESSION["loggedin"]);
		unset($_SESSION["superuser"]);
		unset($_SESSION["user"]);
	}
	else
	{
		print h1("You have been logged out. Welcome back");


		/*
		$pDb = new CDb;
		$cFlds = array(":ip"=>$_SERVER['REMOTE_ADDR']);

		$nSessionId = $pDb->getString("select max(sessionId) from session where active = 1 and IP = :ip", $cFlds);
		
		if ($nSessionId+0)
		{
			//Clear database... 
			$pUpdateDb = new CDb;
			$cFlds = array(":sessid"=>$nSessionId);
			$pUpdateDb->execute("update session set lastrequest = now(), logouttime = now(), active = 0 where sessionid = :sessid", $cFlds);
			//print "Session $nSessionId ended\n";
		}
		else
		{
			print "Weird... No active session found to end\n";
		}*/
		
		//Now set all active settions as inactive...
		setIpInactive($_SERVER['REMOTE_ADDR']);
		requireAccessUpdate();
	}
}


function logsMenu()
{
//	print h1("Please make your selection in menu to the left.");
    include "xxx/logs_status.php";
    logs_status();
	
}


function radiusMenu()
{
	print h1("RADIUS");
	print "<br>RADIUS or \"WPA Enterprise\" means that a separate \"server\" handles the authentication instead of the router with 
		its familiar WiFi password. This gives better security. Most modern WiFi routers have this capability. The radius protocol
		also support \"accounting\", meaning that you can set individual quota for each user. Apparently, most WiFi routers do 
		not support this, though. However, with this server, that is no longer a problem, because we will handle the quota.";
}

function showUsageHistoryFor($szUser)
{
	if ($szUser == "admin" && request("sure") == "")
	{
		$szRows = "<br><b>You're logged in as admin, so showing total of all users</b>. (Show <a href=\"index.php?f=users_show&nm=admin&sure=1\">admin only</a>)<br>";
		$szFlds = "";
		$cFlds = array();
	}
	else
	{
		$szRows = "";
		$szFlds = "user = :user and ";
		$cFlds = array(":user" => $szUser);
	}
	
	$szSQL = "select left(yyyymmddhh, 13) as yyyymmddhh, round(sum(mb),1) as sumMb from userusage where $szFlds STR_TO_DATE(yyyymmddhh, '%Y-%m-%d %H:%i') > DATE_SUB(NOW(), INTERVAL 24 HOUR) group by left(yyyymmddhh,13) order by yyyymmddhh desc;";
	$szHourSQL = "select left(yyyymmddhh, 16) as yyyymmddhh, round(sum(mb),1) as sumMb from userusage where $szFlds STR_TO_DATE(yyyymmddhh, '%Y-%m-%d %H:%i') > DATE_SUB(NOW(), INTERVAL 1 HOUR) group by left(yyyymmddhh,16) order by yyyymmddhh desc;";
	$szWeekSQL = "select left(yyyymmddhh, 10) as yyyymmddhh, round(sum(mb),1) as sumMb from userusage where $szFlds STR_TO_DATE(yyyymmddhh, '%Y-%m-%d') >= date(DATE_SUB(NOW(), INTERVAL 1 WEEK)) group by left(yyyymmddhh,10) order by yyyymmddhh desc;";
	$pDb = new CDb;
	$pHourDb = new CDb;
	$pWeekDb = new CDb;
	$cFetched = $cHourFetched = $cWeekFetched = true;
	
	while ($cFetched || $cHourFetched || $cWeekFetched)
	{
		$cFetched = $pDb->fetchNext($szSQL, $cFlds);
		$cHourFetched = $pHourDb->fetchNext($szHourSQL, $cFlds);
		$cWeekFetched = $pWeekDb->fetchNext($szWeekSQL, $cFlds);
		
		if ($cWeekFetched)
			$szRow = td($cWeekFetched["yyyymmddhh"]).td($cWeekFetched["sumMb"],1,'align="right"');
		else
			$szRow = td("&nbsp;",2);

		$szRow .= td("&nbsp;");
		
		if ($cFetched)
			$szRow .= td($cFetched["yyyymmddhh"]).td($cFetched["sumMb"],1,'align="right"');
		else
			$szRow .= td("&nbsp;",2);
			
		$szRow .= td("&nbsp;");

		if ($cHourFetched)
			$szRow .= td($cHourFetched["yyyymmddhh"]).td($cHourFetched["sumMb"],1,'align="right"');
		else
			$szRow .= td("&nbsp;",2);

		$szRows .= tr($szRow);
	}

	print "<br><br>".h1("Data Usage (in MB):").table(tr(th("Usage last week",2,'align="center"').th("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;").th("Usage last 24 hours",2,'align="center"').th("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;").th("Usage last hour",2,'align="center"')).tr(th("Time").th("Usage").th("&nbsp;").th("Time").th("Usage")).$szRows);
}

function checkSystemWarnings()
{
	if (!isSuperUser())
		return;
	
	if (isset($_GET["seen"]))
	{
		$pDb = new CDb;
		$cFlds = array(":id" => $_GET["seen"] +0);
		$pDb->execute("update systemMessage set seen = b'1' where systemMessageId = :id", $cFlds);
	}
	
	$pDb = new CDb;
	$cFlds = array();
	$szRows = "";
	while ($cFetched = $pDb->fetchNext("select * from systemMessage where seen = b'0' order by systemMessageId desc limit 20", $cFlds))
	{
		$szSeenLink = a("[Seen]","index.php?f=main&seen=".$cFetched["systemMessageId"]);
		$szLogLink = (strlen($cFetched["sysSnapshotSection"])?a("[See log]","http://192.168.0.1/cgi-bin/debugserver?section=".$cFetched["systemMessageId"]):"");
		$szRows .= tr(td($cFetched["createdTime"]).td($cFetched["message"]).td($cFetched["lastDiscovered"]).td($cFetched["count"]).td($szLogLink).td($szSeenLink ));
	}
	
	if (strlen($szRows))
		print table(tr(th("Time").th("Message").th("Last seen").th("Count").th("&nbsp;").th("&nbsp;")).$szRows);
	
}

function checkHasAccess()
{
	//Check if in access table...
	$cArr = array(":ip" => $_SERVER['REMOTE_ADDR']);
	$nAccess = CDb::getString("select hasaccess from access where ip = :ip", $cArr)+0;
	
	if (!$nAccess)
	{
		$cArr = array(":user" => $_SESSION["user"]);
		if (!$cAccessData = CDb::fet("select subscriptionType, expirytime, coalesce(mbquota,0) as mbquota, coalesce(mbusage, 0) as mbusage, lastrequest,
			if (lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR),1,0) as inuse, round(unix_timestamp(now()) - unix_timestamp(coalesce(lastAccessUpdate,'2000-01-01 01:00'))) as secondsSinceAccessUpdate, 
            round(unix_timestamp(now()) - unix_timestamp(coalesce(lastAccessUpdatePoll,'2000-01-01 01:00'))) as secondsSincePolled,
            CAST(requiresAccessUpdate AS UNSIGNED) as requiresAccessUpdate, if(subscriptionType = 'quota',coalesce(mbusage,0) < mbquota, if(subscriptionType = 'expiry',expirytime > now(), coalesce(mbusage,0) < mbquota AND expirytime > now())) as notExpired
		from radcheck r join session s on s.username = r.username join hotspotSetup where active = 1 and r.username = :user order by sessionid desc limit 1", $cArr))
		{
			print red("Unable to find session data. Try to log out and back in to regain access.");
			return false;
		}

     //   print "Inuse: ".$cAcces   sData["inuse"]."<br>".
     //       "Last use: ".$cAccessData["lastrequest"]."<br>".
     //       "<br>";

        
        if ($cAccessData["secondsSincePolled"] > 10) 
            print red("Background process seems not to be running!")."<br>Last poll was ".$cAccessData["secondsSincePolled"]." seconds ago.<br>";

        if ($cAccessData["requiresAccessUpdate"]+0)
            print red("Access update is pending. Try again in 10 seconds.<br>");

        if ($cAccessData["secondsSinceAccessUpdate"]+0 > 120)
            print red("Access has not been updated the last ".$cAccessData["secondsSinceAccessUpdate"]." seconds. Maybe you should try again in 1 minute?.<br>");
		
		if (!$cAccessData["inuse"]+0)
		{
			print red("Connection has been idle too long and has been closed. Log out and back in to regain access.");
			return false;
		}
		else
			if (!$cAccessData["notExpired"]+0)
			{
				print red("Subscription has expired or you have used your quota.");
				return false;
			}
	}
	else
		print "You have access to internet";
	
	//If not, check subscription type, quota, expiry and last request time.... < 1 hour.
	
	return true; //Displays use history.... Should check if there's ad to display instead.
}

function mainMenu()
{
	quotaLessUserComingBack();
	
	if (loggedIn())	
	{
		if (checkHasAccess())
			showUsageHistoryFor($_SESSION["user"]);

		if (isSuperUser())
			checkSystemWarnings();
	}
	else
		print h1("Login to see usage history");
}

function printAd()
{
	print "<br><br>Would you like to share your own internet connection with others as a business? You can of course set up your own line or you can also set up your own computer to resell what you receive from this connection. Let us know if you're interested."; 
}

function quotaLessUserComingBack()
{
	//Check if there's open session on this IP. 
	$szIp = $_SERVER['REMOTE_ADDR'];
//	print "Checking if coming back after quota used...<bR>";
	
	$pDb = new CDb;
	$cFlds = array(":ip"=>$szIp);

	$szSQL = "select sessionid, s.username, lastrequest, DATE_SUB(NOW(), INTERVAL 1 HOUR) as anHourAgo, subscriptionType, expirytime, coalesce(mbquota,0) as mbquota, round(coalesce(mbusage,0),1) as mbusage, round(coalesce(mbquota,0)-coalesce(mbusage,0),1) as mbleft from session s join radcheck r on r.username = s.username where ip = :ip and active = 1 and logouttime is null order by sessionid desc limit 1";
	if ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
	{

		if (loggedIn())
		{
			$szMsg = "Logged in as : ".$_SESSION["user"];
		}
		else
		{
			$szMsg = a("Log in", "index.php?f=main_login");

			if (selfRegistrationEnabled())
			{
				$szMsg .= "<br>".a("Register new username", "index.php?f=main_reg")."<br>";
			}
		}
		
		$szRows = tr(td($szMsg,2));
		
		if (!isInside())
			$szRows .= tr(td("<br><br>You're not inside the router so won't make any change anyway....<br>",2));
	
		//print "Session found(".$cFetched["sessionid"].": ".$cFetched["username"].", last active: ".$cFetched["lastrequest"].(strcmp($cFetched["lastrequest"], $cFetched["anHourAgo"]) <0?"(more":"(less")."  than an hour ago) (one hour ago: ".$cFetched["anHourAgo"].")<br><br>";
		$fQuota = $cFetched["mbquota"];
		$fUsage = $cFetched["mbusage"];
		
		switch ($cFetched["subscriptionType"])
		{
			case "expiry":
				$szRows .= tr(td("Your access expires: ").td(($cFetched["expirytime"] == "")?red("Expiry date not yet set"):substr($cFetched["expirytime"],0,16),1,'align="right"')).
						tr(td("Total data used so far is:").td($fUsage,1,'align="right"'));
				break;
			case "quota":
				$szRows .= tr(td("Your total quota so far is:").td($fQuota,1,'align="right"')).
						tr(td("Total data used far is:").td($fUsage,1,'align="right"'));
					
				$fLeft = $cFetched["mbleft"];
				$szRows .= tr(td(($fLeft>=0?"Quota left:":'<font color="red">NOTE! OVERUSAGE</font>')).
						td(($fLeft>0?"":'<font color="red">').$fLeft.($fLeft>0?"":'</font>'),1,'align="right"'));
				break;
			case "limited":
				
				$fLeft = $cFetched["mbleft"];

				if ($fLeft<=0)
					$szClosed = red("Your account is closed because there's no quota left!");
				else
					if (expired($cFetched["expirytime"]))
						$szClosed = red("Your account is closed because it's expired!");
					else
						$szClosed = "";
						
				if (strlen($szClosed))
					$szRows .= tr(td($szClosed, 2));

				$szRows .= tr(td("Your access expires: ").td(($cFetched["expirytime"] == "")?red("Expiry date not yet set"):substr($cFetched["expirytime"],0,16)));
				$szRows .= tr(td("Your total quota paid for so far is:").td($fQuota,1,'align="right"')).
						tr(td("Total data used far is:").td($fUsage,1,'align="right"'));
					
				$szRows .= tr(td(($fLeft>=0?"Quota left:":'<font color="red">NOTE! OVERUSAGE</font>')).
						td(($fLeft>0?"":'<font color="red">').$fLeft.($fLeft>0?"":'</font>'),1,'align="right"'));
			
			
				break; 
		}

		//Check if already has access..
		$cFlds = array(":ip" => $_SERVER['REMOTE_ADDR']);
		$szHasAccess = CDb::getString("select ip from access where ip = :ip", $cFlds);
		
//		if (!strlen($szHasAccess))
//		{
//			$nSecondsUntilUpdate = getSecondsTillUpdate();
//			$szRows .= tr(td("Access should be updated in <span id=\"countdowntimer\">$nSecondsUntilUpdate</span> seconds", 2));
//		}
//		else
//			$szRows .= tr(td("You should have access to Internet now", 2));

		print table($szRows);

//		if (!strlen($szHasAccess))
//			printCountDownJs($nSecondsUntilUpdate, "countdowntimer", "<font color=\"red\"><b>You can now press refresh to update usage data</b></font><br>");

	
	}
	else
	{
		//print "No open session found.... <br>";
	
		$cSetup = getSetup();
		if (strcmp((isset($cSetup["selfreg"])?$cSetup["selfreg"]:""), "none"))
		{
			print "<br>".a("Register new username", "index.php?f=main_reg")."<br>";
		}
		
		return false;
	}
	
	return true;
}


function getSubscriptionTypeDropList($szCurrent)
{
	$cElem = array ("quota" => "User has access until quota (magabytes of data) is used", 
			"expiry" => "User has access until a specified date and time",
			"limited" => "Access until either quota or specified expiry reached");
	
	$szSubDropList = '<select name="subtype">';

	foreach($cElem as $szKey => $szValue)
	{
		$szSubDropList .= '<option value="'.$szKey.'" '.($szKey == $szCurrent ? "selected":"").'>'.$szValue.'</option>';
	}
	$szSubDropList .= "</select>";
	return $szSubDropList;
}

function confirmCode()
{
	$szName = request("name");
	$szPass = request("pass");
	$szCode = request("code");
	$pDb = new CDb;
	
	$cFlds = array(":name" => $szName);
	if ($cFetched = $pDb->fetchNext("select value, confirmedTime, confirmCode, wrongConfCodeCount, wrongPasswordTime, wrongPasswordCount, round(coalesce(mbquota,0)-coalesce(mbusage,0),1) as quotaLeft from radcheck where username = :name and op = ':=' and attribute = 'Cleartext-Password'", $cFlds))
	{
		if ($cFetched["wrongConfCodeCount"] + $cFetched["wrongPasswordCount"] > 10)
		{
			print red("Too many faling attempts lately, do confirming users is disabled. Please supervisor");
			return;
		}
	
		$szDbPass = $cFetched["value"];
		if (strcmp($szDbPass, $szPass))
		{
			$pDb->update("update radcheck set wrongPasswordTime = coalesce(wrongPasswordTime,now()), wrongPasswordCount = wrongPasswordCount + 1 where username = :name", $cFlds);
			print red("Wrong user name or password");
		}
		else
		{
			if (!strcmp($cFetched["confirmCode"], $szCode))
			{
				$pDb->execute("update radcheck set confirmedTime = now() where username = :name", $cFlds);
				setLoggedIn($szName);
				print "Welcome as a registered user! You are now logged in.<br>";
				
				if ($cFetched["quotaLeft"]+0 > 0)
				{
					print "You have ".$cFetched["quotaLeft"]."MB to your disposal.<br>";
					createSessionAndGrantAccessTo($szName);
				}
				else
					print red("However, you have no quota left. Please contact supervisor to fix this.<br>");
			}
			else
			{
				$pDb->execute("update radcheck set wrongConfCodeCount = wrongConfCodeCount + 1 where username = :name", $cFlds);
				print red("Wrong code!");
			}
		}
	}
	else
		print red("Wrong user name or password");

}

function listServerLoadStats()
{
	$pDb = new CDb;
	$cFlds = array();
	$szRows = tr(th("Average load per minute",4)).tr(th("Log time").th("1 min").th("5 min").th("10 min"));

	while ($cFetched = $pDb->fetchNext("select * from loadavg order by logtime desc limit 20", $cFlds))
		$szRows .= tr(td(substr($cFetched["logtime"],0,16)).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));
	
	print h1("Load average:");
	
	$szDesc = "<br><br><p>The server load tells how many tasks are waiting to be processed. A server load less than 10 is normally considered acceptable.</p><p>The 3 columns give the load average for last 1, 5 and 10 minutes respectively.</p><p>This snapshot is taken immediately after the access is updated.</p>";

	//Max per hour last 24 hour
	$pDb->pStmt = NULL;
	
	$szMaxLast24h = tr(th("Max load per hour",4)).tr(th("Time").th("1 min").th("5 min").th("10 min"));
	$szSQL = "select left(logtime,13) as logtime, max(min1) as min1, max(min5) as min5, max(min10) as min10 from loadavg where logtime > DATE_SUB(NOW(), INTERVAL 24 hour) group by left(logtime,13) order by left(logtime,13) desc";
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
		$szMaxLast24h .= tr(td($cFetched["logtime"]).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));
	
	$pDb->pStmt = NULL;
	$szMaxLastMonth = tr(th("Max load per month",4)).tr(th("Time").th("1 min").th("5 min").th("10 min"));
	$szSQL = "select left(logtime,10) as logtime, max(min1) as min1, max(min5) as min5, max(min10) as min10 from loadavg where logtime > DATE_SUB(NOW(), INTERVAL 1 month) group by left(logtime,10) order by left(logtime,10) desc";
	while ($cFetched = $pDb->fetchNext($szSQL, $cFlds))
		$szMaxLastMonth .= tr(td($cFetched["logtime"]).td($cFetched["min1"],1,'align="right"').td($cFetched["min5"],1,'align="right"').td($cFetched["min10"],1,'align="right"'));

	print table(tr(td(table($szRows).table($szMaxLastMonth),1,'width="60%"').td($szDesc.table($szMaxLast24h),1,'valign="top"')));

}

function printRunSetupNetworkLink()
{
	print a("Run routine that sets up network (you should do this after changing the network setup)", "index.php?f=logs_setupnetwork")."<br><br>";
}


function listServerSyslog()
{
	if (isSuperUser())
	{
		print a("Click here to see the server system log (syslog)", "/cgi-bin/syslog",'target="new"')."<br><br>NOTE! This will open in a new tab in your browser. Close it to get back here.";
	}
}

function changeSubscriptionType()		
{
	if (!isSuperUser())
		return;
	
	$szUser = request("nm");
	$szNewSubType = request("subtype");
	
	$cParam = array(":name" => $szUser, "subtype" => $szNewSubType);//, "addQuota"=>$szQuota);
	$pDb = new CDb;
	$pDb->execute("update radcheck SET subscriptionType = :subtype where username = :name;", $cParam);
	
	print "Subscription type changed for $szUser";
}


function userGroups()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$szRow = "";
	$cFlds = array();
	while ($cRec = $pDb->fetchNext("select groupname, right(description,30) as description from usergroup order by groupname", $cFlds))
	{
		$szRow .= tr(td(a($cRec["groupname"],"index.php?f=users_group&nm=".$cRec["groupname"])).td($cRec["description"]));
	}
	
	print table($szRow);
	
	print "<br><br>".a("Add group", "index.php?f=users_addgroup");
}

function addUserGroup()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$name = request("name");
	$szDesc = request("comment");

	if (isset($_POST["submit"]))
	{
		if (!preg_match('/^(\w*)$/', $name, $matches, PREG_OFFSET_CAPTURE))
		{
			print red("Only alpanumeric characters plus _ are allowed in group name");
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

function showUserGroup()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	//print "HERE NOW<br>";
	$pDb = new CDb;
	$szName = request("nm");
	$cFlds = array(":name"=>$szName);
	if (!$cRec = $pDb->fetchNext("select groupname, description from usergroup where groupname = :name", $cFlds))
	{
		print "Group not found!";
		return;
		
	}
	
	$szRows = tr(td("User group information:",2));
	$szRows .= tr(td("Name:").td($cRec["groupname"])).
			tr(td("Description:").td($cRec["description"]));
			
	$szRows .= tr(td("Campaigns",2));
	
	$pCampDb = new CDb;
	$szCampRows = "";
	while ($cRec = $pCampDb->fetchNext("select campaignid, campaindescription, createtime, campaindescription, purpose, usernameprefix, giveHoursAfterLogin, giveMB, price, count from groupcampaign where groupname = :name", $cFlds))
	{
		$szCampRows .= tr(td(a($cRec["createtime"],"index.php?f=users_showcamp&id=".$cRec["campaignid"])).td($cRec["campaindescription"]).td($cRec["purpose"]).td($cRec["usernameprefix"]).td($cRec["giveHoursAfterLogin"]).td($cRec["giveMB"]).td($cRec["price"]).td($cRec["count"]));
	}
	
	$szRows .= tr(td(table($szCampRows),2));
	
	$szRows .= tr(td(a("Add campaign","index.php?f=users_addcamp&nm=".$szName),2));
	print table ($szRows);
	
	//List group members
	$pUsersDb = new CDb;
	$szRows = "";
	while ($cRec = $pUsersDb->fetchNext("select r.username, mbquota, mbusage, expirytime from radusergroup g join radcheck r on r.username = g.username where groupname = :name", $cFlds))
	{
		$szRows .= tr(td($cRec["username"]).td($cRec["mbquota"]).td($cRec["mbusage"]).td($cRec["expirytime"]));
	}

	if (!strlen($szRows))
		$szRows = tr(td("No users in group",2));
		
	$szRows .= tr(td(a("Click here to add or remove users from group","index.php?f=users_changegrpusers&nm=$szName"),2));

	print table ($szRows);
}

function getGroupCampPurposeArray()
{
	return array('groupmembers'=>"Campaign targeting existing group members",
				'generatetempusers' => "Generate new user names with access");
}

function getGroupCampPurposeDrop($szDefault)
{
	$cArray = getGroupCampPurposeArray();
	
	$szSubDropList = '<select name="purpose">';

	foreach($cArray as $szKey => $szValue)
	{
		$szSubDropList .= '<option value="'.$szKey.'" '.($szKey == $szDefault ? "selected":"").'>'.$szValue.'</option>';
	}
	$szSubDropList .= "</select>";
	return $szSubDropList;
}				

function getGroupCampPurposeText($szPurpose)
{
	$cArray = getGroupCampPurposeArray();
	
	foreach($cArray as $szKey => $szValue)
	{
		if ($szKey == $szPurpose)
			return $szValue;
	}
	return "";
}				
		

function addUserGroupCamp()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$cFlds = array(":name"=>request("nm"));
	if (!$cRec = $pDb->fetchNext("select groupname, description, defaultpurpose from usergroup where groupname = :name", $cFlds))
	{
		print "Group not found!";
		return;
		
	}
	
	if (isset($_POST["submit"]))
	{
		print "Should save new campaign...";
		
		if (request("purpose") == "generatetempusers")
		{
			$cFlds = array(":group"=>request("nm"), ":desc"=>request("comment"),":purpose"=>request("purpose"),
					":prefix"=>request("prefix"), ":random"=>request("random"), 
					":hours"=>request("hours"),
					":quota"=>request("quota"),
					":offset"=>request("offset"),

					":count"=>request("count"),
					":price"=>request("price"),
					":priceinfo"=>request("priceinfo"),
				);
		
			print "successive: ".(isset($_POST["successive"])?"b'1'":"b'0'")."<br>";
		
			$szSuccessive = (isset($_POST["successive"])?"b'1'":"b'0'");
			$szNumbers = (isset($_POST["numbers"])?"b'1'":"b'0'");
			$szSQL = "insert into groupcampaign (groupname, campaindescription,purpose, usernameprefix, randomchars, successive, numbersonly, printStartOffset, giveHoursAfterLogin, giveMB, count, price, priceinfo) 
				values (:group,:desc, :purpose, :prefix, :random, $szSuccessive, $szNumbers, :offset, :hours, :quota, :count, :price, :priceinfo)";
		}
		else
		{
			$cFlds = array(":group"=>request("nm"), ":desc"=>request("comment"),":purpose"=>request("purpose"),
					":hours"=>request("hours"),
					":quota"=>request("quota"),
					":offset"=>request("offset"),
					":price"=>request("price"),
					":priceinfo"=>request("priceinfo"),
				);
		
			print "successive: ".(isset($_POST["successive"])?"b'1'":"b'0'")."<br>";
		
			$szSuccessive = (isset($_POST["successive"])?"b'1'":"b'0'");
			$szNumbers = (isset($_POST["numbers"])?"b'1'":"b'0'");
			$szSQL = "insert into groupcampaign (groupname, campaindescription,purpose, printStartOffset, giveHoursAfterLogin, giveMB, price, priceinfo, usernameprefix) 
				values (:group,:desc, :purpose, :hours, :offset, :quota, :price, :priceinfo,'')";
		}
		
		$pDb = new CDb;
		$pDb->execute($szSQL, $cFlds);
				
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
			
	$szRows .= tr(td("Purpose").td(getGroupCampPurposeText($cRec["defaultpurpose"]).'<input type="hidden" name="purpose" value="'.$cRec["defaultpurpose"].'"',2));	//asdf
	
	if ($cRec["defaultpurpose"] == "generatetempusers")
	{
		$szRows .= tr(td("Prefix").td('<input name="prefix" size="3">').td("Start all generated usernames with this prefix"));
		$szRows .= tr(td("Randomchars").td('<input name="random" value="3" size="2">').td("How many random characters to add"));
		$szRows .= tr(td("Successive").td('<input name="successive" type="checkbox">').td("Number new users successively (after the prefix)"));
		$szRows .= tr(td("Numbers only").td('<input name="numbers" type="checkbox">').td("Numbers only. Leave unchecked to use also A-Z and a-z"));
		$szRows .= tr(td("Count").td('<input name="count" value="20" size="2">').td("Number of new user names to generate"));
	}
	
	$szRows .= tr(td("Hours access").td('<input name="hours" value="48" size="2">').td("How many hours to give after registration (unless quota exceeded)"));
	$szRows .= tr(td("Quota").td('<input name="quota" value="100" size="2">').td("Quota (in MB) to give unless expired. You may omit quota or hours."));
	$szRows .= tr(td("Print offset").td('<input name="offset" value="0" size="2">').td("When printing, start at this number (for printing several pages)"));

	$szRows .= tr(td("Price").td('<input name="price" value="0" size="2">').td("Price per username (for printing)"));
	$szRows .= tr(td("Price info").td('<input name="priceinfo" value="peso" size="2">').td("Will be printed after the price. Printed after the price unless price is part of the text"));


	$szRows .= tr(td('<button  name="submit" type="submit">Submit</button>',3));
	
	print '<form  action="index.php?f=users_addcamp&nm='.request("nm").'" method="post">'.table($szRows).'</form>';
}

function showCampaign($nCampaignId=0)
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	if (!$nCampaignId)
		$nCampaignId = request("id");

	$pDb = new CDb;
	$cFlds = array(":id"=>$nCampaignId);
	if (!$cRec = $pDb->fetchNext("select g.groupname, left(description,30) as description, left(campaindescription,30) as campaindescription, purpose, usernameprefix, randomchars, giveHoursAfterLogin, giveMB, createtime, count, price, priceinfo from usergroup g join groupcampaign c on c.groupname = g.groupname where campaignid = :id", $cFlds))
	{
		print "Campaign not found!";
		return;
		
	}
	
	$szRows = tr(td("Group:").td(a($cRec["groupname"],"index.php?f=users_group&nm=".$cRec["groupname"])));
	$szRows .= tr(td("Description:").td($cRec["description"]));
	$szRows .= tr(td("Campaign:").td($cRec["campaindescription"]));
	
	$szRows .= tr(td("Purpose:").td($cRec["purpose"]));
	$szRows .= tr(td("Prefix:").td($cRec["usernameprefix"]));
	//$szRows .= tr(td(":").td($cRec["randomchars"]));
	$szRows .= tr(td("Hours:").td($cRec["giveHoursAfterLogin"]));
	$szRows .= tr(td("MB:").td($cRec["giveMB"]));
	$szRows .= tr(td("Created:").td(substr($cRec["createtime"],0,16)));
	$szRows .= tr(td("Count:").td($cRec["count"]));
	$szRows .= tr(td("Price:").td($cRec["price"]));
	$szRows .= tr(td("Price info:").td($cRec["priceinfo"]));

	if ($_GET["f"] == "users_grantCampQuota")
	{
		
		if (!isset($_GET["confirmed"]))
		{
			print a("Please confirm that you want to grant quota or increase expiry date","index.php?f=users_grantCampQuota&confirmed=1&id=".$nCampaignId);
			print table($szRows);
		}
		else
		{
			$szRows .= tr(td(red("Should grant...."),2));

			if ($cRec["giveMB"]+0)
			{
				if ($cRec["giveHoursAfterLogin"]+0)
				{
					$szSubscriptionType = "limited";
				}
				else
					$szSubscriptionType = "quota";
			}
			else
				$szSubscriptionType = "expiry";
				
			
			$pScanDb = new CDb;
			$cFlds = array(":group" => $cRec["groupname"]);
			$szUserRows = tr(th("Current status:",4,'align="center"'),th("&nbsp;")).tr(th("Username").th("Quota").th("Used").th("Expires").th("Granted"));
			while ($cGrpmem = $pScanDb->fetchNext("select g.username, mbquota, mbusage, expirytime, giveHoursAfterLogin from radusergroup g join radcheck r on r.username = g.username where groupname = :group", $cFlds))
			{
				$szStatusTxt = $szSubscriptionType.": ";
				$cFlds = array(":user" => $cGrpmem["username"]);
				$szFlds = "";
				switch ($szSubscriptionType)
				{
					case "limited":
						$cFlds = array_merge($cFlds, array(":quota"=>$cRec["giveMB"]+$cGrpmem["mbquota"]+0, ":hours" => $cRec["giveHoursAfterLogin"] + $cGrpmem["giveHoursAfterLogin"]+0));
						$szFlds = "mbquota = :quota, giveHoursAfterLogin = :hours";
						$szStatusTxt .= "Quota: ".$cFlds[":quota"].", hours: ".$cFlds[":hours"];
						break;
					case "quota":
						$cFlds = array_merge($cFlds, array(":quota"=>$cRec["giveMB"]+$cGrpmem["mbquota"]+0));
						$szFlds = "mbquota = :quota";
						$szStatusTxt .= "Quota: ".$cFlds[":quota"];
						break;
					case "expiry":
						$cFlds = array_merge($cFlds, array(":hours" => $cRec["giveHoursAfterLogin"] + $cGrpmem["giveHoursAfterLogin"]+0));
						$szFlds = "giveHoursAfterLogin = :hours";
						$szStatusTxt .= "Hours: ".$cFlds[":hours"];
						break;
					default : 
						$szStatusTxt .= " INVALID SUBSCRIPTION TYPE";
				}
				
				
				$pDb->execute("update radcheck set $szFlds where username = :user", $cFlds);
				
				$szUserRows .= tr(td($cGrpmem["username"]).td($cGrpmem["mbquota"]).td($cGrpmem["mbusage"]).td($cGrpmem["expirytime"]).td($szStatusTxt));
			}
			$szRows .= tr(td(table($szUserRows),2));
			print table($szRows);
		}
		return;
	}


	$cUserDb = new CDb;
	
	$nCount = 0;
	$szUserRows = tr(th("Username").th("Quota").th("Used").th("Expires"));
	while ($cMember = $cUserDb->fetchNext("select username, mbquota, round(mbusage,1) as mbusage, expirytime from radcheck where campaignid = :id order by username", $cFlds))
	{
		$nCount++;
		$szUserRows .= tr(td($cMember["username"]).td($cMember["mbquota"]).td($cMember["mbusage"]).td(substr($cMember["expirytime"],0,16)));
	}

	if ($nCount)
	{
		$szRows .= tr(td(table($szUserRows),2));
		
		$szRows .= tr(td(a("Print usernames/password","index.php?f=users_printlabels&id=".$nCampaignId),2));
		//$szRows .= tr(td("",2));
	}
	else
	{
		if ($cRec["purpose"] == "generatetempusers")
			$szRows .= tr(td(red("No user names generated yet.. ")."  ".a("Click here to generate now","index.php?f=users_gencampusers&id=".$nCampaignId),2));
		else
		{
			$cFlds = array(":group" => $cRec["groupname"]);
			$nCount = CDb::getString("select count(username) from radusergroup where groupname = :group",$cFlds);
		
			if ($nCount)
			{
				$szRows .= tr(td("This group has $nCount members. ".a("Grant quota or increase expiry date","index.php?f=users_grantCampQuota&id=".$nCampaignId),2));
			}
			else
				$szRows .= tr(td(red("No user names selected yet.. And function for doing so is not yet implemented (this campaign is marked to target existing group members)"),2));
		}
	}
		
	print table($szRows);
}

function genCampUsers()
{
	if (!isSuperUser())
		return; 	//Should report hacking..

	$pDb = new CDb;
	$nCampId = request("id");
	$cFlds = array(":id"=>$nCampId);
	if (!$cRec = $pDb->fetchNext("select groupname, createtime, campaindescription, purpose, usernameprefix, randomchars, CAST(successive as UNSIGNED) as successive, numbersonly, giveHoursAfterLogin, giveMB, count from groupcampaign where campaignid = :id", $cFlds))
	{
		print "Campaign not found!";
		return;
	}
	
	$nCount = CDb::getString("select count(username) as usercount from radcheck where campaignid = :id", $cFlds);
	
	if ($nCount)
	{
		print red("$nCount users are already registered for this campaign. Aborting!");
		return;
	}

	$nRandomChars = $cRec["randomchars"]+0;

	if (!$nRandomChars+0)
	{
		print red("Number of random chars must be larger than zero.. Aborting.");
		return;
	}
	
	$nCount = $cRec["count"];
	$szPrefix = $cRec["usernameprefix"];
	$nGiveHours = $cRec["giveHoursAfterLogin"]+0;
	$nGiveQuota = $cRec["giveMB"]+0;
	
	$szRows = "";
	
	
	for ($n=0; $n< $nCount;$n++)
	{
		$szUser = $szPrefix.random_str($nRandomChars);
		$szPass = random_str(4);
		
		$cFlds = array(":user" => $szUser);
		$szFound = $pDb->getString("select username from radcheck where username = :user", $cFlds);
		
		if (strlen($szFound))
			$szFound = "Already exists";
		else
			$szFound = "Doesn't exist";
		
		$cParam = array(":name" => $szUser, ":pass" => $szPass, ":camp" => $nCampId);
		
		 
		//NOTE! N hours after login or set expirytime....
		$szFlds = $szValues = "";
		
		if ($nGiveHours >0 && $nGiveQuota > 0)
		{
			$szFlds .= ", mbquota, giveHoursAfterLogin, subscriptionType";
			$szValues .= ", :quota, :hours, :type";
			$cParam = array_merge($cParam, array(":quota"=>$nGiveQuota,":hours"=>$nGiveHours, ":type" => "limited"));
		}
		else
		{
			if ($nGiveHours > 0)
			{
				$szFlds .= ", giveHoursAfterLogin, subscriptionType";
				$szValues .= ", :hours, :type";
				$cParam = array_merge($cParam, array(":hours"=>$nGiveHours, ":type" => "expiry"));
			}
			else
				if ($nGiveQuota > 0)
				{
					$szFlds .= ", mbquota, subscriptionType";
					$szValues .= ", :quota, :type";
					$cParam = array_merge($cParam, array(":quota"=>$nGiveQuota, ":type" => "quota"));
				}
				else
				{
					print red("Neither quota or hours is specified.. Aborting.");
					return;
				}
				
		}
		
		
		$szSQL = "insert into radcheck (username, attribute, op, value, campaignid, confirmedTime $szFlds) values (:name, 'Cleartext-Password', ':=', :pass, :camp, now() $szValues)";
		$pDb->execute($szSQL, $cParam);

		$szRows .= tr(td($szUser).td($szPass).td($szFound).td($szSQL)); 
	}

	showCampaign($nCampId);
	//print table($szRows);
}


function changeGroupUsers()
{
	print "Supposed to let you add users to group..";
	
	$szGroupName = request("nm");
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

						$cParam = array(":name"=> $szUser, ":group" => $szGroupName);
						$pExecute->execute("insert into radusergroup (username, groupname, priority) values (:name, :group, 1)", $cParam);
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
	
	$szRows = tr(td("Group:").td(a($szGroupName,"index.php?f=users_group&nm=".$szGroupName))).
			tr(td('List of users to add:',2)).
			tr(td(table(tr(td('<textarea name="users" rows="20" cols="40">'.$szUsers."</textarea>").td('<span class="tight">'.table($szUserNameRows.'</span>','class="tight"'),1,'valign="top" class="tight" '))),2)).
			tr(td('Paste a list of name (or user names) in the box above and then click submit to see how the system will interprete that',2));

	if (!isset($_POST["submit"]))
		$szRows .= tr(td('<button  name="submit" type="submit">Submit</button>',2));
	else
		if (!isset($_POST["generate"]))
			$szRows .= tr(td('<button  name="generate" type="submit">Generate users</button><input type="hidden" name="submit" value="1">',2));
		else
			$szRows .= tr(td('User names generated',2));
	
	print '<form  action="index.php?f=users_changegrpusers&nm='.$szGroupName.'" method="post">'.table($szRows).'</form>';
	
}

function grantCampaignQuota()
{
	$nCampaignId = request("id");
	
}

function radiusUsers()
{
	$cUserDb = new CDb;
	
	$szUserRows = tr(th("Username").th("Group").th("Priority"));
	$cFlds = array();
	while ($cUser = $cUserDb->fetchNext("select r.username, priority, g.groupname from radcheck r join radusergroup g on g.username = r.username join radgroupreply gr on gr.groupname = g.groupname ", $cFlds))
	{
		$szUserRows .= tr(td($cUser["username"]).td($cUser["groupname"]).td($cUser["priority"]));
	}
	
	$szUserRows .= tr(td("<br>NOTE! These are the users who are set up to be allowed to login on the radius server using WPA Enterprise Authantication.",3));
	
	print table($szUserRows);
}

function checkValidSession()
{
	$pDb = new CDb;
	$cFlds = array(":ip"=>$_SERVER['REMOTE_ADDR']);

	$nSessionId = $pDb->getString("select sessionId from session where active = 1 and IP = :ip order by sessionid desc limit 1", $cFlds);
	
	if (!$nSessionId)
	{
		print red("You seem logged in here, but there's no registered active session (you do not have access). Try to log out and back in here to resulve the problem)<br>");
	}
	//else
	//	print "Session: $nSessionId<br>";
}

?>
