<?php
// TaraSec partner trust API.
// GET  action=list   -> publish partner trust metadata.
// POST action=enroll -> idempotently enroll a hotspot at low trust.
//
// This endpoint deliberately publishes trust metadata only. It does not make
// blocking decisions on behalf of receiving gateways.

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
        $cfg[$key] = trim($value, "\"'");
    }
    return $cfg;
}

function require_api_token($cfg) {
    $expected = isset($cfg['PARTNER_TRUST_TOKEN']) ? trim($cfg['PARTNER_TRUST_TOKEN']) : '';
    if ($expected === '') fail_json(503, 'PARTNER_TRUST_TOKEN not configured');
    $provided = isset($_SERVER['HTTP_X_TARASEC_TOKEN']) ? trim($_SERVER['HTTP_X_TARASEC_TOKEN']) : '';
    if ($provided === '' || !hash_equals($expected, $provided)) fail_json(403, 'invalid partner trust token');
}

function clean_text($value, $max) {
    $value = trim((string)$value);
    if ($value === '') return null;
    return substr($value, 0, $max);
}

$cfg = read_tarasec_fw_conf();
require_api_token($cfg);
$conn = getConnection();
$action = strtolower(trim(isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list'));

if ($action === 'enroll') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_json(405, 'enroll requires POST');

    $externalId = clean_text(isset($_POST['externalId']) ? $_POST['externalId'] : '', 64);
    $name = clean_text(isset($_POST['name']) ? $_POST['name'] : '', 100);
    if ($externalId === null || $name === null) fail_json(400, 'externalId and name are required');

    $adminEmail = clean_text(isset($_POST['adminEmail']) ? $_POST['adminEmail'] : '', 150);
    $adminPhone = clean_text(isset($_POST['adminPhone']) ? $_POST['adminPhone'] : '', 150);
    $techEmail = clean_text(isset($_POST['techEmail']) ? $_POST['techEmail'] : '', 150);
    $techPhone = clean_text(isset($_POST['techPhone']) ? $_POST['techPhone'] : '', 150);

    $sql = "INSERT INTO partner (externalId,name,adminEmail,adminPhone,techEmail,techPhone,sourceType,trustScore,trustStatus) " .
           "VALUES (?,?,?,?,?,?,'hotspot',10,'low') " .
           "ON DUPLICATE KEY UPDATE name=VALUES(name), adminEmail=VALUES(adminEmail), adminPhone=VALUES(adminPhone), " .
           "techEmail=VALUES(techEmail), techPhone=VALUES(techPhone)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) fail_json(500, 'unable to prepare enrollment');
    $stmt->bind_param('ssssss', $externalId, $name, $adminEmail, $adminPhone, $techEmail, $techPhone);
    if (!$stmt->execute()) fail_json(500, 'unable to enroll partner');
    $stmt->close();

    $stmt = $conn->prepare("SELECT partnerId, externalId, name, sourceType, trustScore, trustStatus, enrolledAt, trustUpdatedAt FROM partner WHERE externalId=? LIMIT 1");
    $stmt->bind_param('s', $externalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(array('ok' => true, 'partner' => $row));
    exit;
}

if ($action === 'list') {
    $rows = array();
    $result = $conn->query("SELECT partnerId, externalId, name, sourceType, trustScore, trustStatus, enrolledAt, trustUpdatedAt FROM partner ORDER BY partnerId");
    if (!$result) fail_json(500, 'unable to read partner trust list');
    while ($row = $result->fetch_assoc()) {
        $row['partnerId'] = (int)$row['partnerId'];
        $row['trustScore'] = (int)$row['trustScore'];
        $rows[] = $row;
    }
    $result->close();
    echo json_encode(array(
        'ok' => true,
        'generatedAt' => gmdate('c'),
        'policy' => array(
            'scoreMin' => 0,
            'scoreMax' => 100,
            'newHotspotScore' => 10,
            'receiverDecides' => true
        ),
        'partners' => $rows
    ));
    exit;
}

fail_json(400, 'action must be list or enroll');
