<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

include "../gatekeeper/dbfunc.php";

if (!isset($_GET["f"])) {
    http_response_code(400);
    exit("missing function");
}

$szF = $_GET["f"];

switch ($szF)
{
    case "rpt":
        if (!isset($_GET["json"])) {
            http_response_code(400);
            exit("missing json");
        }

        $szJsonString = urldecode($_GET["json"]);
        $cJson = json_decode($szJsonString, true);

        if (!is_array($cJson) || json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit("invalid json");
        }

        foreach (["ip", "id", "line", "lineSince", "df", "memAvail", "swap"] as $required) {
            if (!array_key_exists($required, $cJson)) {
                http_response_code(400);
                exit("missing field: " . $required);
            }
        }

        $nIp = intval($cJson["ip"]);
        $szHash = trim((string)$cJson["id"]);
        $szOnLine = intval($cJson["line"]);
        $szLineSince = (string)$cJson["lineSince"];

        if ($szHash === "" || strlen($szHash) > 150) {
            http_response_code(400);
            exit("invalid router id");
        }

        $statusDHCP = (isset($cJson["sinceDhcp"]) && intval($cJson["sinceDhcp"]) < 65) ? 1 : 0;
        $statusIPFM = 1;
        $statusSleeping = 1;
        $statusDmsgUpdated = (isset($cJson["sinceDmesg"]) && intval($cJson["sinceDmesg"]) < 65) ? 1 : 0;
        $statusPortusageUpdated = (isset($cJson["sincePortUsage"]) && intval($cJson["sincePortUsage"]) < 65) ? 1 : 0;

        $df = intval($cJson["df"]);
        $memAvail = intval($cJson["memAvail"]);
        $swap = intval($cJson["swap"]);

        $conn = getConnection();

        try {
            // The status lookup below is keyed by hash, so store the supplied router id here as well.
            $sql = "insert into partnerRouterStatus "
                 . "(routerIP, hash, statusOnline, statusInternetSince, statusDHCP, statusIPFM, statusSleeping, statusDmsgUpdated, statusPortusageUpdated, df, memAvail, swap, info) "
                 . "values (?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "isisiiiiiiiis",
                $nIp,
                $szHash,
                $szOnLine,
                $szLineSince,
                $statusDHCP,
                $statusIPFM,
                $statusSleeping,
                $statusDmsgUpdated,
                $statusPortusageUpdated,
                $df,
                $memAvail,
                $swap,
                $szJsonString
            );
            $stmt->execute();
            $stmt->close();
            echo "Router status report stored.";
        } catch (Throwable $e) {
            error_log("routerstatus rpt failed: " . $e->getMessage());
            http_response_code(500);
            echo "error";
        } finally {
            $conn->close();
        }
        return;

    case "stats":
        if (!isset($_GET["hash"]) || trim((string)$_GET["hash"]) === "") {
            http_response_code(400);
            exit("missing hash");
        }

        $szHash = trim((string)$_GET["hash"]);
        if (strlen($szHash) > 150) {
            http_response_code(400);
            exit("invalid hash");
        }

        // Removed the old debug condition "statusID = 296 or ...", which could return
        // an unrelated router's status regardless of the requested hash.
        $szSQL = "select statusInternetSince, "
               . "CAST(statusOnline AS UNSIGNED) as statusOnline, "
               . "CAST(statusDHCP AS UNSIGNED) as statusDHCP, "
               . "CAST(statusIPFM AS UNSIGNED) as statusIPFM, "
               . "CAST(statusSleeping AS UNSIGNED) as statusSleeping, "
               . "CAST(statusDmsgUpdated AS UNSIGNED) as statusDmsgUpdated, "
               . "CAST(statusPortusageUpdated AS UNSIGNED) as statusPortusageUpdated, "
               . "df, memAvail, swap "
               . "from partnerRouterStatus where hash = ? order by statusID desc limit 1";

        $conn = getConnection();
        try {
            $stmt = $conn->prepare($szSQL);
            $stmt->bind_param("s", $szHash);
            $stmt->execute();
            $stats = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($stats ?: new stdClass());
        } catch (Throwable $e) {
            error_log("routerstatus stats failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["error" => true]);
        } finally {
            $conn->close();
        }
        return;

    default:
        http_response_code(400);
        exit("unknown function");
}
?>
