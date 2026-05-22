<script>
var szUpdateRoutine = "tagStatus";	
</script>

<?php

error_reporting( E_ALL );
ini_set('display_errors', '1');
ini_set('display_startup_errors', 1); 

$szLib = "XmlCommand.class.php";
if (file_exists($szLib))
	require_once $szLib;	//So that can test call the php file directly for debugging
else
	require_once("../".$szLib);	

function login()
{
	//$szSQL = "select count(*) theCount from loginAttempt where theTime > now() - interval 1 miunute";
	$szSQL = "SELECT ".
    			"SUM(theTime > NOW() - INTERVAL 1 MINUTE) AS last1Minute, ".
    			"SUM(theTime > NOW() - INTERVAL 5 MINUTE) AS last5Minutes ".
				"FROM loginAttempt";
	$conn = getConnection();
	$stmt = $conn->prepare($szSQL);
	//$stmt->bind_param("", $_GET["email"]);
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	if ($result)
	{
		$row = $result->fetch_assoc();
		if ($row["last1Minute"]+0 > 5 || $row["last5Minutes"]+0 > 10)
		{
			print "Too many failing login attempts lately. Please try again in few minutes";
			$result->free();

			checkIfTooManyLoginAttemptFromIp(0);
			return;
		}
	}

	?>
	<form action="index.php">
	<table>
		<tr><td>Email:</td><td><input name="email"></td></tr>
		<tr><td>Password:</td><td><input type="password" name="pass"></td></tr>
		<tr><td colspan="2"><input type="submit" name="Submit"><input type="hidden" name="f" value="submitLogin"></td></tr> 
	<!---------------	<tr><td colspan="2"><a href="index.php?f=sendPass">Send password email</td></tr> 
		<tr><td colspan="2"><a href="index.php?f=sendPass">Send password email</td></tr> ------->
	</table>
	</form>
	<?php
}
?>