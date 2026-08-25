<?php

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/hotspotApiCommon.php';
require_once dirname(__DIR__) . '/db_connect.php';

hotspotRequirePost();
$input = hotspotJsonInput();
$hotspot = hotspotAuthenticate($conn, $input);

$precision = strtolower(hotspotString($input, 'locationPrecision', 32));
$publicPrecision = strtolower(hotspotString($input, 'publicLocationPrecision', 32));
if (hotspotPrecisionRank($precision) < 0 || hotspotPrecisionRank($publicPrecision) < 0) {
    hotspotReply(400, ['ok' => false, 'error' => 'Invalid location precision']);
}
if (hotspotPrecisionRank($publicPrecision) > hotspotPrecisionRank($precision)) {
    hotspotReply(400, ['ok' => false, 'error' => 'Public precision cannot be more precise than the supplied location']);
}

$country = strtoupper(hotspotString($input, 'countryCode', 2));
$region = hotspotString($input, 'region', 150);
$city = hotspotString($input, 'city', 150);
$postalCode = hotspotString($input, 'postalCode', 40);
$address = hotspotString($input, 'address', 255);
$source = strtolower(hotspotString($input, 'locationSource', 16));
if (!in_array($source, ['none','owner','device','app','import'], true)) $source = 'owner';

$lat = $input['latitude'] ?? null;
$lon = $input['longitude'] ?? null;
$accuracy = $input['locationAccuracyMeters'] ?? null;
$latitude = ($lat === null || $lat === '') ? null : filter_var($lat, FILTER_VALIDATE_FLOAT);
$longitude = ($lon === null || $lon === '') ? null : filter_var($lon, FILTER_VALIDATE_FLOAT);
if ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) hotspotReply(400, ['ok'=>false,'error'=>'Invalid latitude']);
if ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180)) hotspotReply(400, ['ok'=>false,'error'=>'Invalid longitude']);
if (($latitude === null) !== ($longitude === null)) hotspotReply(400, ['ok'=>false,'error'=>'Latitude and longitude must be supplied together']);
if (in_array($precision, ['approximate','exact'], true) && $latitude === null) hotspotReply(400, ['ok'=>false,'error'=>'Coordinates are required for approximate/exact precision']);
if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) hotspotReply(400, ['ok'=>false,'error'=>'countryCode must be a two-letter code']);
$accuracyMeters = ($accuracy === null || $accuracy === '') ? null : filter_var($accuracy, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>10000000]]);
if ($accuracy !== null && $accuracy !== '' && $accuracyMeters === false) hotspotReply(400, ['ok'=>false,'error'=>'Invalid locationAccuracyMeters']);

try {
    $stmt = $conn->prepare(
        'UPDATE hotspotRegistry SET countryCode=?, region=?, city=?, postalCode=?, address=?, latitude=?, longitude=?, locationAccuracyMeters=?, locationSource=?, locationPrecision=?, publicLocationPrecision=?, locationUpdated=CURRENT_TIMESTAMP WHERE hotspotId=?'
    );
    $stmt->execute([
        $country !== '' ? $country : null,
        $region !== '' ? $region : null,
        $city !== '' ? $city : null,
        $postalCode !== '' ? $postalCode : null,
        $address !== '' ? $address : null,
        $latitude, $longitude, $accuracyMeters, $source, $precision, $publicPrecision,
        $hotspot['hotspotId'],
    ]);

    hotspotReply(200, [
        'ok' => true,
        'hotspotId' => $hotspot['publicId'],
        'locationPrecision' => $precision,
        'publicLocationPrecision' => $publicPrecision,
        'mapEligible' => $publicPrecision !== 'none',
    ]);
} catch (Throwable $e) {
    error_log('hotspotLocation failed: ' . $e->getMessage());
    hotspotReply(500, ['ok'=>false,'error'=>'Location update is temporarily unavailable']);
}
