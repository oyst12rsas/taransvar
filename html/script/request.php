<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

include "../gatekeeper/dbfunc.php";

$szPhone = $_GET["phn"];
$szF = $_GET["f"];

switch ($szF) 
{
	case "trans":
		$conn = getConnection();
		$sql = "select * from transactions where phone = '$szPhone'";
		$result = $conn -> query($sql);

		// Fetch all
		$recs = $result -> fetch_all(MYSQLI_ASSOC);
		$json = json_encode($recs);
		print $json; 
}
?>
