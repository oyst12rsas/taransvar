<?php
// open_access.php
require_once dirname(__DIR__) . '/dbfunc.php';

$szVar = "thisistoopen";
$szValue = getenv('TARASEC_OPEN_ACCESS_TOKEN');

// This maintenance endpoint is disabled unless a token is configured at runtime.
if ($szValue === false || $szValue === '' || !isset($_GET[$szVar]) || !hash_equals($szValue, (string)$_GET[$szVar]))
{
  http_response_code(404);
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

function ipv4touint($ipv4){
    return sprintf('%u',ip2long($ipv4));
}

if (isset($_GET["name"]))
{
        print "<br>Name is set.. should save..<br><br>";
        $conn = getConnection();
        $szSQL = "update setup set adminIP = ".ipv4touint($_GET["name"]);
        $conn->query($szSQL);
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
    <tr><td>&nbsp;</td><td><input type="submit" name="submit"><input type="hidden" name="<?php print $szVar; ?>" value="<?php print htmlspecialchars($szValue, ENT_QUOTES, 'UTF-8'); ?>"></td></tr>
    </form><?php

}
?>
</body>
</html>
