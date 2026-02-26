<?php

$bForceLogin = false;


function getMainContent()
{
	global $bForceLogin;

//$pSystem = new CSystem();

//function getSystem() {global $pSystem; return $pSystem;}
//function getLanguageCode() {return "EN";}

$szF = (isset($_GET)&&isset($_GET["f"])?$_GET["f"]:"");	//request("f"); //

if ($szF == "main_subLogin")
{
	submitLogin($bBefore = false);
}
else
	if ($bForceLogin || !isset($_SESSION["loggedin"]))
	{
		switch ($szF)
		{
			case "main_login":
				doLogin();
				$_GET["f"] = "main";	//To prevent that it's displayed twice..
				break;

			case "main_reg":			//Gets here by self registration.. not yet logged in
				addUser();
				return; 
				
			case "users_addSub":		//Gets here by self registration.. not yet logged in
				userSubmitted();
				return;
				
			case "users_confSub":		//Gets here by self registration.. not yet logged in
				confUserSubmitted();
				return; 

			case "main_confCode":
				confirmCode();
				return;

			case "main_logout":
				//print "Got here now.... <br>";
				//Otherwise session will not be ended and user not logged out...
				break;

			default: 
				if (quotaLessUserComingBack())
					return;
				else
					doLogin();

		}
	
	}

//No longer true: When gets here, user is logged in (has valid session)

if (loggedIn())
{
	//Check if also has valid session record. 
	checkValidSession();
}


$cParts = explode("_", get("f"));
if (sizeof($cParts))
{
	$szMenu = $cParts[0];
	switch ($szMenu)
	{
		case "fw":
			require_once "fw.php";
			fwMenu();
			return;	//Exit function..
		case "uselog":
			require_once "uselog.php";
			uselogMenu();
			return;	//Exit function..
		default: 
			break; 	//See processing below.
	}
}

//print "Here!";
//return;


//include "class/Db.class.php";


$phpFileName = "xxx/".$szF.".php";

if (file_exists($phpFileName))
{
	include $phpFileName;
//	print "Func file exists..<br>";

	if (function_exists($szF))
		$szF();
	else 
		print "Error! Not able to launch this function!";

	return;
}



switch ($szF)
{
	case "main_subLogin":
		break; 	//Submit login handled above.
	case "main_logout":
		logOut($bBefore = false);	//- Handled above
		break;
	case "main_login":
		doLogin();	//Just testing... may give multiple login forms...
		break;
	case "users_list":
	//case "":
		listUsers();
		break;
	case "users_add":	//Superuser adds.
	case "main_reg":		//Self registration
		addUser();
		break;
	case "users_addSub":
		userSubmitted();
		break;
	case "users_confSub":
		confUserSubmitted();
		break;
	case "users_deluser":
		deleteUser();
		break;
	case "users_usage":
		listUsage();
		break;
	case "users_sess":
		listSessions();
		break;
	case "users_active":
		listActiveUsers();
		break;
	case "users_upload":
		uploadAccessInfo();
		break;
	case "users_addquota":
		addQuota();
		break;
	case "users_subQuota":
		submitQuota();
		break;
	case "users_changesubtype":
		changeSubscriptionType();
		break;
	case "users_updateAccess":
		updateAccess();
		break;
	case "users_group":
		showUserGroup();
		break;
	case "users_groups":
		userGroups();
		break;
	case "users_addgroup":
		addUserGroup();
		break;
	case "users_changegrpusers":
		changeGroupUsers();
		break;
	case "users_addcamp":
		addUserGroupCamp();
		break;
	case "users_showcamp":
	case "users_grantCampQuota":
		showCampaign();
		break;
	case "users_gencampusers":
		genCampUsers();
		break;
	case "radius_wifi":
		listClients();
		break;
	case "radius_auth":
		showAuthenticationLog();
		break;
	case "radius_acct":
		listAccounting();
		break;
	case "radius_addclient":
		addClient();
		break;
	case "radius_users":
		radiusUsers();
		break;
	case "logs":
		logsMenu();
		break;
	case "radius":
		radiusMenu();
		break;
	case "main":
		mainMenu();
		break;
	case "main_partner":
		printAd();
		break;
	case "logs_load":
		listServerLoadStats();
		break;
	case "logs_syslog":
		listServerSyslog();
		break;
	case "users_printlabels":
		printLabels();
		break;
	case "list":
	case "users":
		print "Choose from menu to the left";
		break;
	default: 
		print "Unknown function: $szF";
}

//print "Should have printed";
//printMenu();

}

?>
