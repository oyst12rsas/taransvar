<?php
session_start();
require_once 'db_connect.php';
require_once 'auth_tara.php';	#OT 250318


//************ exit here is ok....

header('Location: index.php?success=' . urlencode('You have been successfully logged out.'));
exit;





//Find current session Id
$stmt = $conn->prepare("select sessionid, username from session WHERE ip = ? and active = 1 order by sessionid desc limit 1");
$ip = getUserIpAddr();
$stmt->execute($ip);
$session = $stmt->fetch();


//************ fails if exit here....



//header('Location: index.php?success=' . urlencode('Session was: '.$session["sessionid"]));


$stmt = $conn->prepare("UPDATE session SET logouttime = NOW(), active = 0 WHERE sessionid = ?");
$stmt->execute([$session['sessionid']]);

$stmt = $conn->prepare("UPDATE radcheck SET logouttime = NOW(), active = 0 WHERE id = ?");
$stmt->execute([$_SESSION['user_id']];

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}









session_destroy();

if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

if (isset($_COOKIE['wifi_session'])) {
    setcookie('wifi_session', '', time() - 3600, '/', '', false, true);
}


header('Location: index.php?success=' . urlencode('You have been successfully logged out.'));
exit;




?>
