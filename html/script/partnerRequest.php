<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "../dbfunc.php";
require_once "getSenderIp.php";

function hex_to_ipv4($hex)
{
    $hex = preg_replace('/^0x/i', '', trim((string)$hex));

    if (!preg_match('/^[0-9a-fA-F]{1,8}$/', $hex)) {
        return false;
    }

    $hex = str_pad($hex, 8, '0', STR_PAD_LEFT);
    $binary = pack('H*', $hex);
    return inet_ntop($binary);
}

function senderIsConfiguredGlobalDb($conn, $senderIp)
{
    $sql = "select 1 from setup "
         . "where globalDb1ip = inet_aton(?) "
         . "or globalDb2ip = inet_aton(?) "
         . "or globalDb3ip = inet_aton(?) limit 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $senderIp, $senderIp, $senderIp);
    $stmt->execute();
    $result = $stmt->get_result();
    $registered = ($result && $result->fetch_row());
    $stmt->close();
    return (bool)$registered;
}

if (!isset($_GET["f"]) || $_GET["f"] !== "assistance") {
    http_response_code(400);
    exit("error in parameters");
}

if (!isset($_GET["ip"], $_GET["port"])) {
    http_response_code(400);
    exit("missing params");
}

$requestedIp = hex_to_ipv4($_GET["ip"]);
if ($requestedIp === false || !filter_var($requestedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    exit("invalid ip");
}

$port = filter_var($_GET["port"], FILTER_VALIDATE_INT, array(
    "options" => array("min_range" => 0, "max_range" => 65535)
));
if ($port === false) {
    http_response_code(400);
    exit("invalid port");
}

$category = isset($_GET["cat"]) ? trim((string)$_GET["cat"]) : "other";
if ($category === "" || strlen($category) > 64) {
    http_response_code(400);
    exit("invalid category");
}

$requestQuality = isset($_GET["qual"]) ? intval($_GET["qual"]) : 0;
$wantSpoofed = isset($_GET["sp"]) ? intval($_GET["sp"]) : 0;
$senderIp = getSenderIp();
$senderPort = isset($_SERVER['REMOTE_PORT']) ? intval($_SERVER['REMOTE_PORT']) : 0;

if (!filter_var($senderIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(403);
    exit("untrusted sender");
}

$conn = getConnection();

try {
    // This is a control endpoint. Only the global DB servers configured locally
    // may distribute Request for Assistance messages to this router.
    if (!senderIsConfiguredGlobalDb($conn, $senderIp)) {
        http_response_code(403);
        exit("unregistered global DB");
    }

    $sql = "insert into assistanceRequest "
         . "(purpose, ip, port, senderIp, senderPort, category, requestQuality, wantSpoofed, comment, fromOther, handled) "
         . "values ('fromPartner', inet_aton(?), ?, inet_aton(?), ?, ?, ?, ?, 'From DB server', b'1', b'1')";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisisii", $requestedIp, $port, $senderIp, $senderPort, $category, $requestQuality, $wantSpoofed);
    $stmt->execute();
    $stmt->close();

    header('Content-Type: text/plain; charset=utf-8');
    echo "ok";
} catch (Throwable $e) {
    error_log("partnerRequest failed: sender=" . $senderIp . " error=" . $e->getMessage());
    http_response_code(500);
    echo "error";
} finally {
    $conn->close();
}
?>
