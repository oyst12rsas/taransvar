<?php
//dbfunc.php
function getConnection()
{
	$servername = "localhost";
	$username = "scriptUsrAces3f3";
	$password = "rErte8Oi98!%&e";
	$dbname = "absecurity";

	// Create connection
	$conn = new mysqli($servername, $username, $password, $dbname);
	// Check connection
	if ($conn->connect_error) {
		die("Connection failed: " . $conn->connect_error);
	}
	return $conn;
}

function addWarningRecord($szWarning)
{
	#NOTE! This function also exists in taralink (C) and func.pm(perl)
	$conn = getConnection();
	
	#First check if recently inserted.. 
	$szSQL = "select warningId from warning where handled is null and lastWarned >= DATE_SUB(NOW(), INTERVAL 1 DAY) and warning = ?";

	$stmt = $conn->prepare($szSQL);
        $stmt->bind_param("s", $szWarning); 
        $stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result

	if ($result && $row = $result->fetch_assoc())
	{
		$szSQL = "update warning set lastWarned = now(), count = count + 1 where warningId = ?";
	        $stmt = $conn->prepare($szSQL);
                $stmt->bind_param("i", $row["warningId"]); 
	        $stmt->execute();
	}
	else
	{
		$szSQL = "insert into warning (warning) values (?)";
		$stmt = $conn->prepare($szSQL);// or die "prepare statement failed: $dbh->errstr()";
                $stmt->bind_param("s", $szWarning); 
		$stmt->execute();// or die "execution failed: $sth->errstr()";
	}
}


?>


