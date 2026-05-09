<?php

function logout()
{
	unset($_SESSION["userid"]);
	print "You are logged out. <a href=\"index.php\">Log back in again</a>..";
}

?>