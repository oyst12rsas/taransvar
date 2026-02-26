<?php
//open_access.php
$szVar = "thisistoopen";
$szValue = "45$57!4ghREW";
//Parameters: open_access.php?thisistoopen=45$57!4ghREW
if (!isset($_GET[$szVar]) || strcmp($_GET[$szVar], $szValue) != 0)
{
  http_response_code(404);
  //include('my_404.php'); // provide your own HTML for the error page
  die();
}

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
?>
<html>
<header>
</header>
<body>
<?php


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

function ipv4touint($ipv4){
    return sprintf('%u',ip2long($ipv4));
}

if (isset($_GET["name"]))
{
        print "<br>Name is set.. should save..<br><br>";
        $conn = getConnection();
        $szSQL = "update setup set adminIP = ".ipv4touint($_GET["name"]);
        $conn->query($szSQL);
        //print $szSQL."<br>";
        print "Nice name!<br>";
}

if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
    $ip = $_SERVER['REMOTE_ADDR'];
    print "<br>Your name is: ".$ip; ?>
    
    <form action="open_access.php">
    <tr><td>Name</td><td><input name="name" value="<?php print (isset($_GET["name"])?$_GET["name"]:""); ?>"></td></tr>
    <tr><td>&nbsp;</td><td><input type="submit" name="submit"><input type="hidden" name="<?php print $szVar; ?>" value="<?php print $szValue; ?>"></td></tr>
    </form><?php
    
}
?>
</body>
</html>
