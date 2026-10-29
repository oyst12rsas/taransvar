<?php
declare(strict_types=1);
ini_set('display_errors','0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../dbfunc.php';
require_once __DIR__.'/managerSshCommon.php';
require_once __DIR__.'/managerGoogleSession.php';
require_once __DIR__.'/managerSshApprovalTarget.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function sshReply(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
session_set_cookie_params(['path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Strict']);
session_start();
if (!managerGoogleSessionActive()) sshReply(401,['ok'=>false,'error'=>'manager_login_required']);
try {
    $db=getConnection();
    $id=(int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    $email=(string)($_SESSION['tarasec_manager_email'] ?? '');
    $q=$db->prepare('SELECT CAST(active AS UNSIGNED) active,rejectedTime,expires FROM managerRequest WHERE managerRequestId=? AND email=? LIMIT 1');
    $q->bind_param('is',$id,$email); $q->execute(); $manager=$q->get_result()->fetch_assoc(); $q->close();
    if (!$manager || !managerSshAuthorized($manager)) sshReply(401,['ok'=>false,'error'=>'manager_access_revoked']);
    $source=(string)($_SERVER['REMOTE_ADDR'] ?? '');
    $target=managerSshApprovalTargetRead();
    $state=managerSshPublic();
    $enabled=($state['enabled'] ?? false)===true;
    if (!isset($_SESSION['manager_ssh_csrf'])) $_SESSION['manager_ssh_csrf']=bin2hex(random_bytes(32));
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method==='POST') {
        if (!$enabled) sshReply(503,['ok'=>false,'error'=>$state['error'] ?? 'ssh_disabled_by_owner']);
        if (!hash_equals($_SESSION['manager_ssh_csrf'],(string)($_POST['csrf'] ?? ''))) sshReply(403,['ok'=>false,'error'=>'csrf_required']);
        $action=(string)($_POST['action'] ?? ''); $seconds=(int)($_POST['seconds'] ?? 0);
        if (!in_array($action,['open','close'],true) || ($action==='open' && !managerSshDuration($seconds))) sshReply(400,['ok'=>false,'error'=>'invalid_ssh_request']);
        // Opening requires the Google/TOTP approval service, not only an app session.
        // Keep close available: ending a legacy temporary allowance grants no access.
        if ($action==='open') sshReply(403,[
            'ok'=>false,'error'=>'ssh_mfa_required','mfaRequired'=>true,
            'approvalUrl'=>$target['approval_url'] ?? null
        ]);
        if ($action==='close') $seconds=0;
        // Bound repeated requests; never extend a window merely by polling status.
        $q=$db->prepare("SELECT COUNT(*) n FROM managerSshRequest WHERE managerRequestId=? AND createdAt>DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
        $q->bind_param('i',$id); $q->execute(); $count=(int)$q->get_result()->fetch_assoc()['n']; $q->close();
        if ($count>=12) sshReply(429,['ok'=>false,'error'=>'ssh_request_rate_limit']);
        $request=bin2hex(random_bytes(16));
        $q=$db->prepare('INSERT INTO managerSshRequest(requestId,managerRequestId,sourceIp,action,seconds) VALUES(?,?,?,?,?)');
        $q->bind_param('sissi',$request,$id,$source,$action,$seconds); $q->execute(); $q->close();
    } elseif ($method!=='GET') sshReply(405,['ok'=>false,'error'=>'method_not_allowed']);
    $latest=null;
    if ($enabled) {
        $q=$db->prepare('SELECT action,state,createdAt FROM managerSshRequest WHERE managerRequestId=? ORDER BY createdAt DESC, sequenceId DESC LIMIT 1');
        $q->bind_param('i',$id); $q->execute(); $latest=$q->get_result()->fetch_assoc(); $q->close();
    }
    $remaining=max(0,(int)($state['remainingSeconds'] ?? 0)-max(0,time()-(int)($state['updated'] ?? 0)));
    $db->close();
    $public=managerSshClientState(array_replace($state,['remainingSeconds'=>$remaining]));
    sshReply(200,array_merge($public,[
        'ok'=>true,'sourceIp'=>$source,'latestRequest'=>$latest,'csrf'=>$_SESSION['manager_ssh_csrf'],
        'approvalTarget'=>$target,'approvalUrl'=>$target['approval_url'] ?? null
    ]));
} catch (Throwable $e) { error_log('Manager SSH API unavailable'); sshReply(503,['ok'=>false,'error'=>'manager_ssh_unavailable']); }
