<?php

function loginAttempt($username, $password)
{
	//print "About to log..<br>";
	//include "../script/tagged.php";
	$szSQL = "insert into loginAttempt(ip, username, password) values(inet_aton(?), ?, ?)";
	//print "$szSQL<br>";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	require_once "../script/getSenderIp.php";
	$szIp = getSenderIp();
	//print "Prepared...($szIp)<br>";
	//NOTE! Should check if just registered first to exclude resubmitting by error
	$stmt->bind_param("sss", $szIp, $username, $password);
	$stmt->execute();
}

function submitLogin()
{
	$szUserName = $_GET["email"];

	$szSenderIp = getSenderIp();
	//Check if tried to login with an IP address... If so, only own IP is allowed.
	if (filter_var($szUserName, FILTER_VALIDATE_IP) && strcmp($szUserName, $szSenderIp)) 
	{
			include "login.php";
			login();
			print '<font color="red">You can login here with IP address, but only your own - which is '.$szSenderIp.'</font>';
			return;
	}			

	//print "Trying to login... User: ".$szUserName.", pass: ".$_GET["pass"]."<br>";
	$szSQL = "select userId, password, loginFailsSinceSuccess, loginFailReportedTime from user where username = ?";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $szUserName);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
		$row = $result->fetch_assoc();
	else 
		$row = 0;
	if ($row)
	{
		$nLoginFailsSinceSuccess = $row["loginFailsSinceSuccess"]+0;
		$szLoginFailReportedTime = $row["loginFailReportedTime"];

		if ($row["password"] == $_GET["pass"])
		{
			print "WELCOME! You are logged in.";
			$_SESSION["userid"] = $row["userId"];
			$_SESSION["hold"] = 0;	//Otherwise hold might be default on in Log window...
			$szSenderIp = getSenderIp();
			$szSQL = "update user set lastLogin = now(), lastLoginIp = inet_aton(?), loginFailsSinceSuccess = 0 where username = ?";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("ss", $szSenderIp, $szUserName);
			$stmt->execute();
		}
		else
		{
			print "Error in user name or password.<br>";
			loginAttempt($szUserName, $_GET["pass"]);

			$szSQL = "update user set loginFailsSinceSuccess = loginFailsSinceSuccess + 1 where username = ?";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("s", $szUserName);
			$stmt->execute();

			if ($nLoginFailsSinceSuccess > 10 && !isset($szLoginFailReportedTime))
			{
				//Report this user to partnering router...
				//require_once "../script/reportHacking.php";
				reportHacking("$nLoginFailsSinceSuccess failing attempts to log into web server");
				$szSQL = "update user set loginFailReportedTime = now() where username = ?";
				$stmt = $conn->prepare($szSQL);
				$stmt->bind_param("s", $szUserName);
				$stmt->execute();
			}

			include "login.php";
			login();
		}
	}
	else {
		if (!strcmp($szUserName, $szSenderIp))
			$selfReg = true;
		else
		{
			$szSQL = "select CAST(requireRegistration AS UNSIGNED) as requireRegistration, CAST(selfRegistration AS UNSIGNED) as selfRegistration from setup limit 1";
			$conn = getConnection();
			$stmt = $conn->prepare($szSQL);
			//$stmt->bind_param("s", $szUserName);
			$stmt->execute();
			$result = $stmt->get_result(); // get the mysqli result
			if ($result)
			{
				$row = $result->fetch_assoc();
				$reqReg = $row['requireRegistration']+0;			
				$selfReg = $row['selfRegistration']+0;	
				//print "Self registration? ".$selfReg."<br>";
			}
			else
			{
				print "Unable to read the setup.<br>Please try again later.";
				return;
			}
		}

		if ($selfReg)
		{
			//First user should be set as admin..
			$szSQL = "select count(*) as count from user";
			$stmt = $conn->prepare($szSQL);
			$stmt->execute();
			$result = $stmt->get_result(); // get the mysqli result
			$bIsAdmin = 0;
			if ($result)
			{
				$row = $result->fetch_assoc();
				if ($row && ($row["count"] == "0"))
					$bIsAdmin = 1;
			}

			$szSQL = "insert into user(username, password, isAdmin) values (?, ?, b'".$bIsAdmin."')";
			$stmt = $conn->prepare($szSQL);
			$stmt->bind_param("ss", $szUserName, $_GET["pass"]);
			$stmt->execute();
			$_SESSION["userid"] = last_insert_id($conn);
			if ($bIsAdmin)
				print "You are the first user here and will be set as admin. You might want to save the user name. Or you'll find it in the user table.";
			else
				print "New user registered.";
		}
		else
			{
				print "Wrong password or user does not exist (about to log..)!<br>";
				loginAttempt($szUserName, $_GET["pass"]);
				checkIfTooManyLoginAttemptFromIp(0);
			}
	}
	$conn->close();
}


?>
