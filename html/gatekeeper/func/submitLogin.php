<?php


function submitLogin()
{
	print "Trying to login... User: ".$_GET["email"].", pass: ".$_GET["pass"]."<br>";
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
		$szSQL = "insert into user(username, password) values (?, ?)";
		$stmt = $conn->prepare($szSQL);
		$stmt->bind_param("ss", $_GET["email"], $_GET["pass"]);
		$stmt->execute();
		$_SESSION["userid"] = last_insert_id($conn);
		print "New user registered.";
	}
	$conn->close();
}

?>
