<?php
/**
 * TaraSec web entry point.
 *
 * setup.hotspot is the existing installation switch:
 *   1 = expose the WiFi hotspot portal at /
 *   0 = expose Gatekeeper at /
 *
 * Keep the hotspot application itself in index_hotspot.php so the two systems
 * do not need to know about each other's internals.
 */

require_once __DIR__ . '/db_connect.php';

$hotspotEnabled = false;

try {
    $stmt = $conn->query("SELECT CAST(hotspot AS UNSIGNED) AS hotspot FROM setup LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hotspotEnabled = ((int)$row['hotspot'] === 1);
    }
} catch (Throwable $e) {
    // Fail closed to Gatekeeper if setup cannot be read.  Do not expose DB
    // details to a web client; PHP/system logs will contain the exception.
    error_log('TaraSec index: unable to read setup.hotspot: ' . $e->getMessage());
}

if ($hotspotEnabled) {
    require __DIR__ . '/index_hotspot.php';
    exit;
}

header('Location: /gatekeeper/');
exit;
