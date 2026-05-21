<?php

function getSenderIp()
{
	$ip = 0;
	if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
		$ip = $_SERVER['HTTP_CLIENT_IP'];
	} 
	//** Don't trust... easy to spoof */
	//elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) 
	//{
	//    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	//} 
	else {
    	$ip = $_SERVER['REMOTE_ADDR'];
 	}

	if (!strcmp($ip, "::1"))
		$ip = "127.0.0.1";

 	return $ip;
}

?>