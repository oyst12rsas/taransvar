<?php
$cfgFile = getenv('TARASEC_DB_CONFIG') ?: '/etc/tarasec/db.php';
$cfg = is_readable($cfgFile) ? require $cfgFile : [];
if (!is_array($cfg)) { $cfg = []; }

$host = getenv('TARASEC_DB_HOST') ?: ($cfg['host'] ?? 'localhost');
$dbname = getenv('TARASEC_DB_NAME') ?: ($cfg['name'] ?? 'taransvar');
$username = getenv('TARASEC_DB_USER') ?: ($cfg['user'] ?? '');
$dbSecret = getenv('TARASEC_DB_PASSWORD');
if ($dbSecret === false) { $dbSecret = $cfg['password'] ?? ''; }
if ($username === '' || $dbSecret === '') {
    throw new RuntimeException('TaraSec database configuration is missing');
}

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $conn = new PDO($dsn, $username, $dbSecret, $options);
} catch (PDOException $e) {
    error_log('Database connection failed');
    die('Sorry, there was a problem connecting to the database. Please try again later.');
}
?>
