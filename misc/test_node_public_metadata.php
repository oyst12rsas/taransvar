<?php
declare(strict_types=1);
require_once __DIR__.'/../html/script/nodePublicMetadata.php';
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$dir=sys_get_temp_dir().'/node-public-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$path=$dir.'/public-node.json';
$cfg=['mode'=>'hosted_gateway','gateway_id'=>str_repeat('a',32),'subject_key'=>str_repeat('b',64),'base_url'=>'http://node.local'];
try {
    expect(nodePublicRead($path)===null,'Missing metadata must allow legacy fallback');
    nodePublicWrite($cfg,$path);
    $data=nodePublicRead($path);
    expect($data['service_node_id']===unitServiceNodeId($cfg),'Preserve existing linked node identity');
    $raw=file_get_contents($path);
    expect(!str_contains($raw,$cfg['subject_key']) && !str_contains($raw,unitServiceNodeSecret($cfg)),'No private keys in public metadata');
    expect((fileperms($path)&0777)===0644,'Metadata readable but not writable by web server');
    $data['subject_key']='accidental extra field';
    file_put_contents($path,json_encode($data));
    expect(!array_key_exists('subject_key',nodePublicRead($path)),'API returns only allowlisted public fields');
    file_put_contents($path,'broken');
    $rejected=false; try { nodePublicRead($path); } catch (Throwable $e) { $rejected=true; }
    expect($rejected,'Invalid metadata must not fall back to private configuration');
    nodePublicWrite($cfg,$path);
    expect(nodePublicRead($path)['gateway_id']===$cfg['gateway_id'],'Atomic replacement repairs invalid metadata');
    echo "Public metadata identity, permissions, credential isolation and transitional fallback passed\n";
} finally { if (is_file($path)) unlink($path); rmdir($dir); }
