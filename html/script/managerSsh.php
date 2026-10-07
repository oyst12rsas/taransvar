<?php
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../dbfunc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function sshReply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
$secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params(['path'=>'/', 'secure'=>$secure, 'httponly'=>true, 'samesite'=>'Strict']);
session_start();
if (empty($_SESSION['tarasec_manager_authenticated'])) sshReply(401, ['ok'=>false,'error'=>'manager_login_required']);
try {
    $conn = getConnection();
    $id = (int)($_SESSION['tarasec_manager_request_id'] ?? 0);
    $email = (string)($_SESSION['tarasec_manager_email'] ?? '');
    $stmt = $conn->prepare("SELECT 1 FROM managerRequest WHERE managerRequestId=? AND email=? AND active=b'1' AND rejectedTime IS NULL AND (expires IS NULL OR expires>NOW()) LIMIT 1");
    $stmt->bind_param('is', $id, $email);
    $stmt->execute();
    $authorized = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    $conn->close();
    if (!$authorized) sshReply(401, ['ok'=>false,'error'=>'manager_access_revoked']);
    $action = (string)($_GET['action'] ?? 'status');
    if (!in_array($action, ['status','open'], true)) sshReply(400, ['ok'=>false,'error'=>'invalid_action']);
    if (empty($_SESSION['manager_ssh_csrf'])) $_SESSION['manager_ssh_csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['manager_ssh_csrf'];
    $minutes = 0;
    if ($action === 'open') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sshReply(405, ['ok'=>false,'error'=>'post_required']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_TARASEC_CSRF'] ?? ''))) sshReply(403, ['ok'=>false,'error'=>'invalid_csrf']);
        $minutes = filter_var($_POST['minutes'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($minutes, [5,10,15], true)) sshReply(400, ['ok'=>false,'error'=>'invalid_duration']);
    }
    // Never accept a target or source IP from the caller. This controls this node only.
    $source = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) sshReply(400, ['ok'=>false,'error'=>'ipv4_required']);
    session_write_close();
    $command = ['/usr/bin/sudo','-n','/usr/local/lib/tarasec/manager_ssh.py',$action,$source];
    if ($action === 'open') $command[] = (string)$minutes;
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('helper unavailable');
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    $diagnostic = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($raw, true);
    if ($exit !== 0 || !is_array($result) || empty($result['ok'])) {
        error_log('managerSsh helper: '.substr($diagnostic, 0, 500));
        sshReply(503, ['ok'=>false,'error'=>'ssh_control_unavailable_or_policy_denied']);
    }
    if ($action === 'open') error_log('TARASEC_MANAGER_SSH managerRequestId='.$id.' source='.$source.' minutes='.$minutes);
    $result['csrfToken'] = $csrf;
    sshReply(200, $result);
} catch (Throwable $e) {
    error_log('managerSsh: '.$e->getMessage());
    sshReply(503, ['ok'=>false,'error'=>'ssh_control_unavailable']);
}
