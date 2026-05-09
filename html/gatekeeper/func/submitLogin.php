<?php


function submitLogin()
{
	//print "Trying to login... User: ".$_GET["email"].", pass: ".$_GET["pass"]."<br>";
	$szSQL = "select userId, password from user where username = ?";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	$stmt->bind_param("s", $_GET["email"]);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
		$row = $result->fetch_assoc();
	else 
		$row = 0;
	if ($row)
	{
		if ($row["password"] == $_GET["pass"])
		{
			print "WELCOME! You are logged in.";
			$_SESSION["userid"] = $row["userId"];
		}
		else
		{
			print "Error in user name or password. ";
			login();
		}
	}
	else {
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
		$stmt->bind_param("ss", $_GET["email"], $_GET["pass"]);
		$stmt->execute();
		$_SESSION["userid"] = last_insert_id($conn);
		if ($bIsAdmin)
			print "You are the first user here and will be set as admin. You might want to save the user name. Or you'll find it in the user table.";
		else
			print "New user registered.";
	}
	$conn->close();
}

?>
