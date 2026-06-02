<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

include "../dbfunc.php";
include "tagged.php";
$sender = getSenderIp();
//print $_GET["json"];
$json = json_decode($_GET["json"]);
//print "<br>IP: $json->ip<br>";
$szSQL = "update partnerRouter set status = ?, partnerStatusReceived = now() where ip = inet_aton(?)";
//print "$szSQL\n<br>";

$conn = getConnection();

$stmt = $conn->prepare($szSQL);
if (!$stmt) {
    die("Prepare failed: ".$conn->error."\nSQL: ".$szSQL);
}

$status = $_GET["json"];

if (!$stmt->bind_param("ss", $status, $sender)) {
    die("bind_param failed: ".$stmt->error);
}

if (!$stmt->execute()) {
    die("Execute failed: ".$stmt->error.
        "\nSQL: ".$szSQL.
        "\nstatus=".$status.
        "\nsender=".$sender);
}

print "ok";
?>