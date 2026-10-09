<?php
declare(strict_types=1);
require_once __DIR__.'/unitLinkCommon.php';

const TARA_NODE_METADATA = '/var/lib/tarasec-node/public-node.json';

function nodePublicValidate(array $data): array {
    if (($data['version'] ?? null)!==1 || ($data['link_mode'] ?? '')!=='hosted_gateway'
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($data['gateway_id'] ?? ''))
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($data['service_node_id'] ?? ''))
        || !is_string($data['name'] ?? null) || !is_array($data['account_services'] ?? null))
        throw new RuntimeException('Invalid public node metadata.');
    // Project only public fields; never return configuration or credential fields.
    return ['version'=>1,'link_mode'=>'hosted_gateway','gateway_id'=>$data['gateway_id'],
        'service_node_id'=>$data['service_node_id'],'name'=>substr($data['name'],0,100),
        'account_services'=>taraAccountServices($data['account_services'])];
}

function nodePublicFromConfig(array $cfg): array {
    if ($cfg['mode']!=='hosted_gateway') throw new RuntimeException('Hosted node required.');
    return nodePublicValidate(['version'=>1,'link_mode'=>'hosted_gateway',
        'gateway_id'=>$cfg['gateway_id'],'service_node_id'=>unitServiceNodeId($cfg),
        'name'=>gethostname() ?: 'TaraSec node','account_services'=>taraAccountServices()]);
}

function nodePublicRead(string $path=TARA_NODE_METADATA): ?array {
    if (!file_exists($path)) return null; // Transitional fallback only when absent.
    $raw=file_get_contents($path);
    if ($raw===false || strlen($raw)>16384) throw new RuntimeException('Public node metadata unavailable.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid public node metadata.');
    return nodePublicValidate($data);
}

function nodePublicWrite(array $cfg,string $path=TARA_NODE_METADATA): void {
    $json=json_encode(nodePublicFromConfig($cfg),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    $temp=tempnam(dirname($path),'.public-node-');
    if ($temp===false) throw new RuntimeException('Cannot publish node metadata.');
    try {
        if (file_put_contents($temp,$json)!==strlen($json) || !chmod($temp,0644) || !rename($temp,$path))
            throw new RuntimeException('Cannot publish node metadata.');
    } finally { if (file_exists($temp)) unlink($temp); }
}
