<?php
// TaraSec remote SSH control endpoint.
// Firewall and SSH policy is authoritative in /etc/tarasecfw.conf.

header('Content-Type: application/json');
include_once "../dbfunc.php";

function fail_json($status, $message) {
    http_response_code($status);
    echo json_encode(array('ok' => false, 'error' => $message));
    exit;
}

function read_tarasec_fw_conf() {
    $cfg = array();
    $file = '/etc/tarasecfw.conf';
    if (!is_readable($file)) return $cfg;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $p = strpos($line, '=');
        if ($p === false) continue;
        $key = trim(substr($line, 0, $p));
        $value = trim(substr($line, $p + 1));
        $value = trim($value, "\"'");
        $cfg[$key] = $value;
    }
    return $cfg;
}

function cfg_on($value) { return in_array(strtolower(trim((string)$value)), array('1','yes','true','on'), true); }
function ipv4_in_cidr($ip, $cidr) {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    list($network, $bits) = explode('/', $cidr, 2);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) return false;
    $ipn = ip2long($ip); $netn = ip2long($network); $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return (($ipn & $mask) === ($netn & $mask));
}
function dbserver_ips($conn) {
    $ips = array();
    $sql = "select inet_ntoa(globalDb1ip) db1, inet_ntoa(globalDb2ip) db2, inet_ntoa(globalDb3ip) db3 from setup limit 1";
    if ($result = $conn->query($sql)) {
        if ($row = $result->fetch_assoc()) foreach (array('db1','db2','db3') as $k) if (!empty($row[$k])) $ips[] = $row[$k];
        $result->close();
    }
    return $ips;
}
function requester_allowed($remoteIp, $rules, $conn) {
    $items = array_filter(array_map('trim', explode(',', $rules)));
    foreach ($items as $item) {
        if (strcasecmp($item, 'dbserver') === 0) { if (in_array($remoteIp, dbserver_ips($conn), true)) return true; continue; }
        if (ipv4_in_cidr($remoteIp, $item)) return true;
    }
    return false;
}

$cfg = read_tarasec_fw_conf();
if (!cfg_on(isset($cfg['SSH_REMOTE_CONTROL']) ? $cfg['SSH_REMOTE_CONTROL'] : 'off')) fail_json(403, 'remote SSH control disabled by owner');
$conn = getConnection();
$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$rules = isset($cfg['SSH_REMOTE_ALLOWED_REQUESTERS']) ? $cfg['SSH_REMOTE_ALLOWED_REQUESTERS'] : '';
if ($rules === '' || !requester_allowed($remoteIp, $rules, $conn)) fail_json(403, 'requester not allowed');

$expectedToken = isset($cfg['SSH_REMOTE_TOKEN']) ? $cfg['SSH_REMOTE_TOKEN'] : '';
if ($expectedToken !== '') {
    $provided = isset($_SERVER['HTTP_X_TARASEC_TOKEN']) ? $_SERVER['HTTP_X_TARASEC_TOKEN'] : '';
    if ($provided === '' || !hash_equals($expectedToken, $provided)) fail_json(403, 'invalid remote-control token');
}

$action = strtolower(trim(isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : 'status')));
if (!in_array($action, array('open','close','status'), true)) fail_json(400, 'action must be open, close or status');
if ($action === 'open' || $action === 'close') {
    $allow = ($action === 'open') ? 1 : 0;
    $stmt = $conn->prepare("update setup set iptablesAllowSsh = ?, iptablesSetupChanged = b'1'");
    if (!$stmt) fail_json(500, 'unable to prepare SSH update');
    $stmt->bind_param('i', $allow);
    if (!$stmt->execute()) fail_json(500, 'unable to update SSH state');
    $stmt->close();
}
$result = $conn->query("select coalesce(CAST(iptablesAllowSsh as UNSIGNED),0) sshOpen, coalesce(sshPort,22) sshPort from setup limit 1");
$row = $result ? $result->fetch_assoc() : array('sshOpen' => 0, 'sshPort' => 22);
$actual = !empty($row['sshOpen']) ? 'open' : 'closed';
$broadcast = strtolower(isset($cfg['SSH_BROADCAST']) ? $cfg['SSH_BROADCAST'] : 'on');
$response = array('ok'=>true, 'action'=>$action, 'queued'=>($action !== 'status'), 'sshPort'=>isset($cfg['SSH_PORT']) ? (int)$cfg['SSH_PORT'] : (int)$row['sshPort']);
if ($broadcast === 'on') $response['ssh'] = $actual;
elseif ($broadcast === 'open' || $broadcast === 'closed') $response['ssh'] = $broadcast;
elseif ($broadcast !== 'off') $response['sshBroadcastError'] = 'invalid SSH_BROADCAST value';
error_log('TARASEC_SSH_REMOTE requester=' . $remoteIp . ' action=' . $action . ' actual=' . $actual);
echo json_encode($response);
