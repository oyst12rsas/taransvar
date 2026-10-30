<?php
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
require_once __DIR__ . '/../html/script/webPokeLib.php';
$dbIp=$argv[1]??''; $expectedId=$argv[2]??'';
if (!webPokeIp($dbIp) || !isset(webPokeNodes()[$expectedId])) throw new RuntimeException('Invalid detected node identity');
$pair=sodium_crypto_box_keypair(); $proof=bin2hex(random_bytes(32));
$pending='/run/tarasec-web-poke-bootstrap.json';
$lock=fopen('/run/tarasec-web-poke-bootstrap.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another demo enrollment is running');
try {
    $json=json_encode(['db_ip'=>$dbIp,'proof_hash'=>hash('sha256',$proof),
        'public_key'=>bin2hex(sodium_crypto_box_publickey($pair)),'expires'=>time()+120],JSON_THROW_ON_ERROR);
    if (file_put_contents($pending,$json,LOCK_EX)===false || !chmod($pending,0640) || !chgrp($pending,'www-data'))
        throw new RuntimeException('Cannot create local administrator enrollment');
    $response=webPokeHttp($dbIp,'webPokeCoordinator.php',['action'=>'enroll','proof'=>$proof]);
    $plain=sodium_crypto_box_seal_open((string)base64_decode((string)($response['sealed_config']??''),true),$pair);
    $config=$plain===false ? null : json_decode($plain,true);
    if (!is_array($config) || ($config['NODE_ID']??'')!==$expectedId || !webPokeToken((string)($config['AUTH_KEY']??'')))
        throw new RuntimeException('DB receiver enrollment could not be verified');
    $target='/etc/tarasec-web-poke-node.conf';
    $content="NODE_ID=$expectedId\nDB_IP=$dbIp\nAUTH_KEY=".$config['AUTH_KEY']."\n";
    // Root-owned configuration; no private encryption key ever leaves this process.
    if (file_put_contents($target,$content,LOCK_EX)===false || !chmod($target,0640) || !chgrp($target,'www-data'))
        throw new RuntimeException('Cannot install receiver credentials');
    echo "Receiver enrolled automatically: $expectedId\n";
} finally {
    @unlink($pending); sodium_memzero($pair); flock($lock,LOCK_UN); fclose($lock);
}
