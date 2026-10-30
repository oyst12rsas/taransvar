<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../html/script/webPokeLib.php';
$web = $argv[1] ?? '/var/www/html';
require_once $web . '/dbfunc.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = getConnection();
$row = $db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) is_db,INET_NTOA(globalDb1ip) db_ip FROM setup LIMIT 1')->fetch_assoc();
$db->close();
if (!$row) throw new RuntimeException('TaraSec setup row is missing');
if (getenv('WEB_POKE_DB_IP')) $row['db_ip']=getenv('WEB_POKE_DB_IP');
$interfaces=json_decode((string)shell_exec('ip -j -4 address show 2>/dev/null'),true) ?: [];
$addresses=[];
foreach ($interfaces as $interface) foreach ($interface['addr_info'] ?? [] as $address) {
    if (webPokeIp((string)($address['local'] ?? ''))) $addresses[]=$address['local'];
}
echo json_encode(webPokeDetectNode($row,$addresses),JSON_THROW_ON_ERROR);
