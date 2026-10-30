<?php
// Invoked only by the local root installer. Never expose this script as a web API.
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require_once __DIR__ . '/../html/script/webPokeLib.php';
$role = $argv[1] ?? ''; $dbIp = $argv[2] ?? '100.68.126.0';
if (!webPokeIp($dbIp)) throw new RuntimeException('DB_IP must be a NetBird IPv4 address');
if ($role === 'db') {
    $path='/etc/tarasec-web-poke-db.conf';
    $config=is_file($path) ? parse_ini_file($path,false,INI_SCANNER_RAW) : ['ALLOW_ANY_GATEWAY'=>'YES','ALLOWED_GATEWAYS'=>'100.68.165.190'];
    if (!is_array($config)) throw new RuntimeException('Invalid existing DB demo configuration');
    foreach (webPokeNodes() as $id=>$node) {
        $name='KEY_'.strtoupper($id);
        if (isset($config[$name]) && !webPokeToken($config[$name])) throw new RuntimeException('Invalid existing receiver key');
        $config[$name] ??= bin2hex(random_bytes(32));
    }
    $lines=[]; foreach ($config as $key=>$value) $lines[]=$key.'="'.str_replace(['"',"\n","\r"],'',(string)$value).'"';
    if (file_put_contents($path,implode("\n",$lines)."\n",LOCK_EX)===false) throw new RuntimeException('Cannot write DB demo configuration');
    chmod($path,0640);
    $dir='/etc/tarasec-web-poke-enrollment'; if (!is_dir($dir)) mkdir($dir,0700);
    foreach (webPokeNodes() as $id=>$node) {
        $nodeConfig="NODE_ID=$id\nDB_IP=$dbIp\nAUTH_KEY=".$config['KEY_'.strtoupper($id)]."\n";
        $target="$dir/$id.conf";
        if (file_put_contents($target,$nodeConfig,LOCK_EX)===false) throw new RuntimeException('Cannot create receiver enrollment');
        chmod($target,0600);
    }
    echo "DB policy ready: ALLOW_ANY_GATEWAY=".($config['ALLOW_ANY_GATEWAY']??'NO')."\nReceiver credentials are ready; other listed nodes enroll automatically from the DB.\n";
} elseif ($role === 'gateway') {
    $path='/etc/tarasec-web-poke-gateway.conf';
    $config=is_file($path) ? parse_ini_file($path,false,INI_SCANNER_RAW) : [];
    if (!is_array($config)) throw new RuntimeException('Invalid existing gateway configuration');
    $config['DB_IP']=$dbIp; $lines=[];
    foreach ($config as $key=>$value) $lines[]=$key.'="'.str_replace(['"',"\n","\r"],'',(string)$value).'"';
    if (file_put_contents($path,implode("\n",$lines)."\n",LOCK_EX)===false) throw new RuntimeException('Cannot write gateway configuration');
    chmod($path,0640);
} else throw new RuntimeException('Unsupported setup role');
