<?php

$host = 'localhost';
$dbname = 'wifi_hotspot1';
$username = 'root';
$password = '';

		$szDBHost = "localhost";
		$dbname = "taransvar";
		$username = "scriptUsrAces3f3";
		$password = "rErte8Oi98!%&e";



$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";


$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];


try {
    $conn = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    
    error_log("Connection failed: " . $e->getMessage());
    die("Sorry, there was a problem connecting to the database. Please try again later.");
}

?>
