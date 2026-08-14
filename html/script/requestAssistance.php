<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "../dbfunc.php";
require_once "getSenderIp.php";

function hex_to_ip($hex)
{
    $hex = preg_replace('/^0x/i', '', trim((string)$hex));

    if (!preg_match('/^[0-9a-fA-F]{1,8}$/', $hex)) {
        return false;
    }

    $hex = str_pad($hex, 8, '0', STR_PAD_LEFT);
    $binary = pack('H*', $hex);
    return inet_ntop($binary);
}

function senderIsRegisteredPartner($conn, $senderIp)
{
    $stmt = $conn->prepare("select 1 from partnerRouter where ip = inet_aton(?) limit 1");
    $stmt->bind_param("s", $senderIp);
    $stmt->execute();
    $result = $stmt->get_result();
    $registered = ($result && $result->fetch_row());
    $stmt->close();
    return (bool)$registered;
}

if (!isset($_GET["f"]) || $_GET["f"] !== "request") {
    http_response_code(400);
    exit("error in parameters");
}

if (!isset($_GET["ip"], $_GET["port"])) {
    http_response_code(400);
    exit("missing params");
}

$reportedIp = hex_to_ip($_GET["ip"]);
if ($reportedIp === false || !filter_var($reportedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
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
    // Control messages are accepted only from routers already registered with this global DB.
    // Do not trust HTTP_CLIENT_IP / X-Forwarded-For for this identity; getSenderIp() uses REMOTE_ADDR.
    if (!senderIsRegisteredPartner($conn, $senderIp)) {
        http_response_code(403);
        exit("unregistered partner");
    }

    $sql = "insert into assistanceRequest "
         . "(purpose, ip, port, senderIp, senderPort, category, requestQuality, wantSpoofed) "
         . "values ('forDistribution', inet_aton(?), ?, inet_aton(?), ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisisii", $reportedIp, $port, $senderIp, $senderPort, $category, $requestQuality, $wantSpoofed);
    $stmt->execute();
    $stmt->close();

    header('Content-Type: text/plain; charset=utf-8');
    echo "ok";
} catch (Throwable $e) {
    error_log("requestAssistance failed: sender=" . $senderIp . " error=" . $e->getMessage());
    http_response_code(500);
    echo "error";
} finally {
    $conn->close();
}
?>
