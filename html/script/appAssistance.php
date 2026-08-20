<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include '../dbfunc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function assistanceReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function startManagerSessionForAssistance(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

startManagerSessionForAssistance();
if (empty($_SESSION['tarasec_manager_authenticated'])) {
    assistanceReply(401, ['ok' => false, 'error' => 'manager_auth_required']);
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));

try {
    $conn = getConnection();

    if ($action === 'create') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            assistanceReply(405, ['ok' => false, 'error' => 'create_requires_post']);
        }

        $ip = trim((string)($_POST['ip'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            assistanceReply(400, ['ok' => false, 'error' => 'invalid_ip']);
        }

        $portText = trim((string)($_POST['port'] ?? '0'));
        $port = ($portText === '') ? 0 : filter_var($portText, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 65535]
        ]);
        if ($port === false) {
            assistanceReply(400, ['ok' => false, 'error' => 'invalid_port']);
        }

        $threshold = filter_var($_POST['threshold'] ?? 5, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 10]
        ]);
        if ($threshold === false) {
            assistanceReply(400, ['ok' => false, 'error' => 'invalid_threshold']);
        }

        $category = trim((string)($_POST['category'] ?? 'bruteForce'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $category)) {
            assistanceReply(400, ['ok' => false, 'error' => 'invalid_category']);
        }

        $email = (string)($_SESSION['tarasec_manager_email'] ?? '');
        $comment = 'Added through TaraSec app by manager ' . $email;

        $stmt = $conn->prepare("INSERT INTO assistanceRequest (ip, port, category, comment, purpose, requestQuality) VALUES (INET_ATON(?), ?, ?, ?, 'internalRequest', ?)");
        $stmt->bind_param('sissi', $ip, $port, $category, $comment, $threshold);
        $stmt->execute();
        $requestId = (int)$stmt->insert_id;
        $stmt->close();
        $conn->close();

        assistanceReply(201, [
            'ok' => true,
            'assistanceRequestId' => $requestId,
            'ip' => $ip,
            'port' => (int)$port,
            'category' => $category,
            'threshold' => (int)$threshold,
            'handled' => false,
            'sentPartners' => false,
            'deliveryState' => 'local_pending',
            'comment' => $comment
        ]);
    }

    if ($action === 'list') {
        /*
         * assistanceRequest.sentPartners is an old field which really means
         * "taralink queued outward work". The App must not present that as
         * successful delivery. Derive sentPartners from pendingWget instead:
         * every AssistanceRequest delivery for this request must have an exact
         * "ok" reply and be handled. DB v83 keeps failed rows unhandled so they
         * remain retryable.
         */
        $sql = "SELECT
                    AR.requestId AS assistanceRequestId,
                    INET_NTOA(AR.ip) AS ip,
                    AR.port,
                    AR.category,
                    AR.comment,
                    AR.purpose,
                    AR.requestQuality,
                    AR.handled,
                    AR.sentPartners AS outwardQueued,
                    (SELECT COUNT(*) FROM pendingWget PW
                      WHERE PW.category='AssistanceRequest'
                        AND PW.regardingId=AR.requestId) AS deliveryCount,
                    (SELECT COUNT(*) FROM pendingWget PW
                      WHERE PW.category='AssistanceRequest'
                        AND PW.regardingId=AR.requestId
                        AND PW.handled IS NOT NULL
                        AND TRIM(COALESCE(PW.reply,''))='ok') AS acceptedCount,
                    (SELECT COUNT(*) FROM pendingWget PW
                      WHERE PW.category='AssistanceRequest'
                        AND PW.regardingId=AR.requestId
                        AND PW.handled IS NULL) AS pendingCount
                FROM assistanceRequest AR
                WHERE AR.purpose='internalRequest'
                ORDER BY AR.requestId DESC
                LIMIT 25";
        $result = $conn->query($sql);
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $outwardQueued = !empty($row['outwardQueued']);
            $deliveryCount = (int)$row['deliveryCount'];
            $acceptedCount = (int)$row['acceptedCount'];
            $pendingCount = (int)$row['pendingCount'];
            $delivered = $outwardQueued && $deliveryCount > 0 && $acceptedCount === $deliveryCount;

            if ($delivered) {
                $deliveryState = 'db_accepted';
            } elseif ($deliveryCount > 0 && $pendingCount > 0) {
                $deliveryState = 'sending_or_retrying';
            } elseif ($outwardQueued) {
                $deliveryState = 'queued';
            } else {
                $deliveryState = 'local_pending';
            }

            $items[] = [
                'assistanceRequestId' => (int)$row['assistanceRequestId'],
                'ip' => (string)$row['ip'],
                'port' => (int)$row['port'],
                'category' => (string)$row['category'],
                'comment' => (string)$row['comment'],
                'threshold' => (int)$row['requestQuality'],
                'handled' => !empty($row['handled']),
                'sentPartners' => $delivered,
                'deliveryState' => $deliveryState,
                'deliveryCount' => $deliveryCount,
                'acceptedCount' => $acceptedCount
            ];
        }
        $conn->close();
        assistanceReply(200, ['ok' => true, 'requests' => $items]);
    }

    $conn->close();
    assistanceReply(400, ['ok' => false, 'error' => 'invalid_action']);
} catch (Throwable $e) {
    error_log('appAssistance.php: ' . $e->getMessage());
    assistanceReply(503, ['ok' => false, 'error' => 'assistance_unavailable']);
}
