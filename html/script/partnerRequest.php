<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// dbfunc.php historically emits trailing whitespace outside its PHP block.
ob_start();
include "../dbfunc.php";
ob_end_clean();

function controlPeerIp()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    if (strncasecmp($ip, '::ffff:', 7) === 0) $ip = substr($ip, 7);
    return $ip;
}

function hex_to_ipv4($hex)
{
    $hex = preg_replace('/^0x/i', '', trim((string)$hex));
    if (!preg_match('/^[0-9a-fA-F]{1,8}$/', $hex)) return false;
    $hex = str_pad($hex, 8, '0', STR_PAD_LEFT);
    return inet_ntop(pack('H*', $hex));
}

function senderIsConfiguredGlobalDb($conn, $senderIp)
{
    $sql = "select 1 from setup where globalDb1ip = inet_aton(?) or globalDb2ip = inet_aton(?) or globalDb3ip = inet_aton(?) limit 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $senderIp, $senderIp, $senderIp); $stmt->execute();
    $result = $stmt->get_result(); $registered = ($result && $result->fetch_row());
    $stmt->close(); return (bool)$registered;
}

if (!isset($_GET["f"]) || $_GET["f"] !== "assistance") { http_response_code(400); exit("error in parameters"); }
if (!isset($_GET["ip"], $_GET["port"])) { http_response_code(400); exit("missing params"); }

$requestedIp = hex_to_ipv4($_GET["ip"]);
if ($requestedIp === false || !filter_var($requestedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { http_response_code(400); exit("invalid ip"); }
$port = filter_var($_GET["port"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 0, "max_range" => 65535]]);
if ($port === false) { http_response_code(400); exit("invalid port"); }
$category = isset($_GET["cat"]) ? trim((string)$_GET["cat"]) : "other";
if ($category === "" || strlen($category) > 64) { http_response_code(400); exit("invalid category"); }
$requestQuality = isset($_GET["qual"]) ? intval($_GET["qual"]) : 0;
$wantSpoofed = isset($_GET["sp"]) ? intval($_GET["sp"]) : 0;
$active = isset($_GET["active"]) ? intval($_GET["active"]) : 1;
if ($active !== 0 && $active !== 1) { http_response_code(400); exit("invalid active"); }
$sourceRequestId = isset($_GET["rid"]) ? filter_var($_GET["rid"], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : false;
if (isset($_GET["rid"]) && $sourceRequestId === false) { http_response_code(400); exit("invalid request id"); }
$sourceRequestId = $sourceRequestId === false ? 0 : intval($sourceRequestId);
$senderIp = controlPeerIp();
$senderPort = isset($_SERVER['REMOTE_PORT']) ? intval($_SERVER['REMOTE_PORT']) : 0;
if (!filter_var($senderIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { http_response_code(403); exit("untrusted sender"); }

$conn = getConnection();
try {
    if (!senderIsConfiguredGlobalDb($conn, $senderIp)) { http_response_code(403); exit("unregistered global DB"); }

    /* Delivery is at-least-once and pending work may arrive out of order.  The
       central requestId is therefore the source sequence.  Keep one effective
       row per sender/category and never let an older start overwrite a newer
       release.  An inactive event must create a tombstone when its start has
       not arrived yet. */
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT requestId,COALESCE(regardingRequestId,0) sourceRequestId,CAST(active AS UNSIGNED) active,COALESCE(requestQuality,0) requestQuality,CAST(COALESCE(wantSpoofed,b'0') AS UNSIGNED) wantSpoofed FROM assistanceRequest WHERE purpose='fromPartner' AND ip=inet_aton(?) AND port=? AND category=? AND senderIp=inet_aton(?) ORDER BY requestId DESC LIMIT 1 FOR UPDATE");
    $stmt->bind_param("siss", $requestedIp, $port, $category, $senderIp);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stale = $latest && $sourceRequestId > 0
        && intval($latest["sourceRequestId"]) > $sourceRequestId;
    $duplicate = $latest && !$stale
        && ($sourceRequestId === 0 || intval($latest["sourceRequestId"]) === $sourceRequestId)
        && intval($latest["active"]) === $active
        && intval($latest["requestQuality"]) === $requestQuality
        && intval($latest["wantSpoofed"]) === $wantSpoofed;

    if (!$stale && !$duplicate) {
        if ($latest) {
            $comment = $active ? 'Updated by global DB' : 'Released by global DB';
            $stmt = $conn->prepare("UPDATE assistanceRequest SET regardingRequestId=?,senderPort=?,requestQuality=?,wantSpoofed=?,active=?,handled=NULL,sentPartners=b'1',handlingComment=? WHERE requestId=?");
            $requestId = intval($latest["requestId"]);
            $stmt->bind_param("iiiiisi", $sourceRequestId, $senderPort, $requestQuality, $wantSpoofed, $active, $comment, $requestId);
        } else {
            $sql = "INSERT INTO assistanceRequest (purpose,ip,port,senderIp,senderPort,category,regardingRequestId,requestQuality,wantSpoofed,comment,fromOther,handled,sentPartners,active) VALUES ('fromPartner',inet_aton(?),?,inet_aton(?),?,?,?,?,?,'From DB server',b'1',NULL,b'1',?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sisisiiii", $requestedIp, $port, $senderIp, $senderPort, $category, $sourceRequestId, $requestQuality, $wantSpoofed, $active);
        }
        $stmt->execute();
        $stmt->close();
    }
    $conn->commit();

    header('Content-Type: text/plain; charset=utf-8'); echo "ok";
} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    error_log("partnerRequest failed: sender=" . $senderIp . " error=" . $e->getMessage());
    http_response_code(500); echo "error";
} finally { $conn->close(); }
?>
