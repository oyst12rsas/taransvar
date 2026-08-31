<?php

$bForceLogin = false;
$bBackofficeLoginAttempted = false;
$bBackofficeLoginSucceeded = false;

function submitBackofficeAdminLogin()
{
	$szName = trim((string)request("name"));
	$szPass = (string)request("pass");
	$pDb = new CDb;
	$cAdmin = false;
	try {
		$cAdmin = $pDb->fetch(
			"select username,password from user where username=:name and cast(isAdmin as unsigned)=1 and (suspendedUntil is null or suspendedUntil < NOW()) limit 1",
			array(":name"=>$szName)
		);
	} catch (Throwable $e) {
		$cAdmin = false;
	}

	if (!$cAdmin || !isset($cAdmin["password"]) || !hash_equals((string)$cAdmin["password"], $szPass))
		return false;

	$_SESSION["loggedin"] = true;
	$_SESSION["user"] = (string)$cAdmin["username"];
	$_SESSION["superuser"] = true;
	try {
		$pDb->execute(
			"update user set lastLogin=NOW(), lastLoginIp=inet_aton(:ip), loginFailsSinceSuccess=0, loginFailReportedTime=NULL where username=:name and cast(isAdmin as unsigned)=1",
			array(":ip"=>getSenderIp(), ":name"=>$szName)
		);
	} catch (Throwable $e) { }

	$_GET["f"] = "main";
	return true;
}

// content.php is included near the top of hotspot/index.php. Process the
// back-office login here so the page header sees the authenticated admin.
if (request("f") == "main_subLogin" && !loggedIn() &&
	isset($_SERVER["REQUEST_METHOD"]) && $_SERVER["REQUEST_METHOD"] == "POST")
{
	$bBackofficeLoginAttempted = true;
	$bBackofficeLoginSucceeded = submitBackofficeAdminLogin();
}

function getMainContent()
{
	global $bForceLogin, $bBackofficeLoginAttempted, $bBackofficeLoginSucceeded;

$szF = (isset($_GET)&&isset($_GET["f"])?$_GET["f"]:"");

if ($szF == "main_subLogin")
{
	// Authentication was already attempted once when this file was included,
	// before index.php renders the username in its header. Never pass this
	// back-office route to the legacy RADIUS/subscriber login handler and never
	// retry an empty POST after an administrator is already logged in.
	if (loggedIn() || $bBackofficeLoginSucceeded)
	{
		$szF = "main";
		$_GET["f"] = "main";
	}
	else
	{
		if ($bBackofficeLoginAttempted)
			print red("Error in username or password!")."<br><br>";
		doLogin();
		return;
	}
}
else
	if ($bForceLogin || !isset($_SESSION["loggedin"]))
	{
		switch ($szF)
		{
			case "main_login":
				doLogin();
				$_GET["f"] = "main";
				break;

			case "main_reg":
				addUser();
				return;

			case "users_addSub":
				userSubmitted();
				return;

			case "users_confSub":
				confUserSubmitted();
				return;

			case "main_confCode":
				confirmCode();
				return;

			case "main_logout":
				break;

			default:
				if (quotaLessUserComingBack())
					return;
				else
					doLogin();
		}
	}

if (loggedIn())
{
	if (!isSuperUser())
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
			return;
		case "uselog":
			require_once "uselog.php";
			uselogMenu();
			return;
		default:
			break;
	}
}

$phpFileName = "xxx/".$szF.".php";
if (file_exists($phpFileName))
{
	include $phpFileName;
	if (function_exists($szF))
		$szF();
	else
		print "Error! Not able to launch this function!";
	return;
}

switch ($szF)
{
	case "main_subLogin":
		break;
	case "main_logout":
		logOut($bBefore = false);
		break;
	case "main_login":
		doLogin();
		break;
	case "users_list":
		listUsers();
		break;
	case "users_add":
	case "main_reg":
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
}

?>
