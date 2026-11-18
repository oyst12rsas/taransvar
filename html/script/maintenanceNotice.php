<?php
// Public schedule only. The operator controls it on the central web host.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$path = '/etc/tarasec/maintenance-notice.json';
$raw = @file_get_contents($path, false, null, 0, 1025);
$schedule = is_string($raw) && strlen($raw) <= 1024 ? json_decode($raw, true) : null;
$start = $schedule['starts_at'] ?? null;
$end = $schedule['ends_at'] ?? null;
$notice = null;
if (is_int($start) && is_int($end) && $start > 0 && $end > $start &&
    $end <= 253402300799 && $end > time()) {
    $notice = ['starts_at' => $start, 'ends_at' => $end];
}
echo json_encode(['notice' => $notice]);
