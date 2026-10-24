<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../dbfunc.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function operationsActivityReply(int $code,array $body): never {
    http_response_code($code);echo json_encode($body);exit;
}
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET') operationsActivityReply(405,['complete'=>false]);
    $registry='/etc/tarasec-operations-feed/tokens.json';
    $tokens=is_file($registry) ? json_decode(file_get_contents($registry),true,512,JSON_THROW_ON_ERROR) : [];
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $token=(string)($_SERVER['HTTP_X_TARASEC_OPERATIONS_TOKEN']??'');
    $localAuth=strlen($token)===64 && isset($tokens[$ip]) && hash_equals($tokens[$ip],hash('sha256',$token));
    if (!$localAuth) {
        require_once __DIR__.'/operationsActivityAuth.php';
        if (!operationsActivityRemoteAuth($token,$ip)) operationsActivityReply(403,['complete'=>false]);
    }
    $db=getConnection();
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($role['central'])) operationsActivityReply(409,['complete'=>false]);
    // Deliberately global: any active exercise blocks disruption on all pilot nodes.
    // Missing tables/columns fail closed; never silently omit an unsupported demo.
    $queries=[
        "SELECT EXISTS(SELECT 1 FROM demoSshSession WHERE state IN ('awaiting_node_a','demo_infected','awaiting_node_b','owner_clear_required') AND expires>NOW()) active",
        "SELECT EXISTS(SELECT 1 FROM demoAssistanceSession WHERE state IN ('active','contained','releasing')) active",
        'SELECT EXISTS(SELECT 1 FROM demo4Session WHERE expiresAt>NOW()) active',
        "SELECT EXISTS(SELECT 1 FROM demo5Session WHERE state NOT IN ('released','expired') AND expiresAt>NOW()) active",
        "SELECT EXISTS(SELECT 1 FROM internalInfections WHERE active=b'1' AND why LIKE 'DEMO:%') active"
    ];
    $active=false;
    foreach ($queries as $sql) {
        $row=$db->query($sql)->fetch_assoc();$active=$active || (bool)$row['active'];
    }
    if ($ip === '127.0.0.1' || $ip === '::1') {
        // Authenticated central-local observer: use the central server's own report.
        $node=$db->query('SELECT UNIX_TIMESTAMP(networkStatusChecked) received, networkStatus status FROM setup LIMIT 1')->fetch_assoc();
    } else {
    $receipt=$db->prepare('SELECT UNIX_TIMESTAMP(partnerStatusReceived) received, status FROM partnerRouter WHERE ip=INET_ATON(?) ORDER BY partnerStatusReceived DESC LIMIT 1');
    $receipt->bind_param('s',$ip);
    $receipt->execute();
    $node=$receipt->get_result()->fetch_assoc();
    $receipt->close();
    }
    $status=$node ? json_decode((string)$node['status'],true) : null;
    require_once __DIR__.'/upgradeRolloutLib.php';
    try {$rollout=upgradeRolloutDecision($db,$ip,(string)($_GET['revision']??''),(int)($_GET['version']??0));}
    catch(Throwable $e) {$rollout=['allowed'=>false,'reason'=>'Rollout evidence unavailable'];}
    operationsActivityReply(200,['central_received_at'=>$node ? (int)$node['received'] : null,
        'operations_boot_id'=>is_array($status) ? ($status['operationsAgent']['boot_id']??null) : null,
        'kernel_running'=>is_array($status) && (string)($status['knl']??'')==='1',
        'link_running'=>is_array($status) && (string)($status['lnk']??'')==='1',
        'checked_at'=>time(),'complete'=>true,'active_demo'=>$active,
        'rollout'=>$rollout,'scope'=>'all central demo sessions and active DEMO infections']);
} catch(Throwable $e) {
    error_log('Operations activity feed unavailable: '.get_class($e));
    operationsActivityReply(503,['complete'=>false]);
}
