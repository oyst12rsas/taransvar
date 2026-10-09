<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/gatewayAppLinkCommon.php';
require_once __DIR__.'/nodePublicMetadata.php';
require_once __DIR__.'/../dbfunc.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function gatewayLinkReply(int $code,array $data): never { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_SLASHES); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $public=nodePublicRead();
    if ($public!==null) {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') gatewayLinkReply(405,['ok'=>false,'error'=>'use_https_account_service']);
        gatewayLinkReply(200,['ok'=>true]+$public);
    }
    $cfg=unitLinkConfig();
    if ($cfg['mode']==='hosted_gateway') {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') gatewayLinkReply(405,['ok'=>false,'error'=>'use_https_account_service']);
        gatewayLinkReply(200,['ok'=>true,'gateway_id'=>$cfg['gateway_id'],'service_node_id'=>unitServiceNodeId($cfg),'name'=>gethostname() ?: 'TaraSec node','link_mode'=>'hosted_gateway','account_services'=>taraAccountServices()]);
    }
    unitLinkTransport($cfg);
    $db=getConnection(); $name=gethostname() ?: 'TaraSec gateway';
    $gateway=['gateway_id'=>$cfg['gateway_id'],'name'=>$name,'base_url'=>$cfg['base_url']];
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='GET') {
        $db->query('SELECT requestId FROM gatewayAppRequest LIMIT 0');
        gatewayLinkReply(200,['ok'=>true]+$gateway+['link_mode'=>'gateway_app_approval','transport'=>$cfg['transport'] ?? 'https','account_services'=>taraAccountServices()]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') gatewayLinkReply(405,['ok'=>false,'error'=>'post_required']);
    $action=(string)($_POST['action'] ?? '');
    if ($action==='status') {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$m)) gatewayLinkReply(401,['ok'=>false,'error'=>'app_token_required']);
        $q=unitLinkQuery($db,"SELECT linkId FROM gatewayAppLink WHERE tokenHash=? AND gatewayId=? AND provider=? AND active=b'1' AND expiresAt>NOW()",[hash('sha256',$m[1]),$cfg['gateway_id'],taraAccountServices()['identity_api_base']]);
        $r=$q->get_result()->fetch_assoc(); $q->close();
        if (!$r) gatewayLinkReply(401,['ok'=>false,'error'=>'app_link_revoked_or_expired']);
        gatewayLinkReply(200,['ok'=>true,'scope'=>'gateway_read_only','gateway'=>$gateway+['reachable'=>true],'server_time'=>gmdate('c'),'privacy'=>['managerAccess'=>false]]);
    }
    $hash=unitRedeemIdentity((string)($_POST['ticket'] ?? ''),$cfg);
    $clientHash=gatewayAppClient((string)($_POST['client_id'] ?? ''));
    if ($action==='request') gatewayLinkReply(201,['ok'=>true]+$gateway+gatewayAppRequest($db,$cfg,$hash,$clientHash));
    if ($action==='unlink') {
        gatewayAppUnlink($db,$hash,$clientHash);
        gatewayLinkReply(200,['ok'=>true]);
    }
    if ($action!=='list') throw new UnitLinkException('Unsupported app-link operation.');
    $token=gatewayAppGrant($db,$cfg,$hash,$clientHash);
    gatewayLinkReply(200,['ok'=>true]+$gateway+['scope'=>'gateway_read_only','token'=>$token,'approved'=>$token!==null]);
} catch (Throwable $e) {
    error_log('Gateway app linking unavailable; no identity or credential logged');
    gatewayLinkReply(503,['ok'=>false,'error'=>'gateway_app_link_unavailable']);
}
