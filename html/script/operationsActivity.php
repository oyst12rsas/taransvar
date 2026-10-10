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
    $tokens=json_decode(file_get_contents('/etc/tarasec-operations-feed/tokens.json'),true,512,JSON_THROW_ON_ERROR);
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $token=(string)($_SERVER['HTTP_X_TARASEC_OPERATIONS_TOKEN']??'');
    if (strlen($token)!==64 || !isset($tokens[$ip]) ||
        !hash_equals($tokens[$ip],hash('sha256',$token))) operationsActivityReply(403,['complete'=>false]);
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
    operationsActivityReply(200,['checked_at'=>time(),'complete'=>true,'active_demo'=>$active,
        'scope'=>'all central demo sessions and active DEMO infections']);
} catch(Throwable $e) {
    error_log('Operations activity feed unavailable: '.get_class($e));
    operationsActivityReply(503,['complete'=>false]);
}
