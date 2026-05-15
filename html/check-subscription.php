<?php
session_start();
require_once 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'hasSubscription' => false,
        'error' => 'User not logged in'
    ]);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    // Get user data from radcheck table
    $stmt = $conn->prepare("SELECT mbquota, mbusage, expirytime, subscriptionType FROM radcheck WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        header('Content-Type: application/json');
        echo json_encode([
            'hasSubscription' => false,
            'error' => 'User data not found'
        ]);
        exit;
    }
    
    // Check if user has a subscription
    $hasSubscription = (!empty($userData['mbquota']) || !empty($userData['expirytime']));
    
    // Check if subscription is expired
    $isExpired = false;
    if (!empty($userData['expirytime'])) {
        $expiryTime = strtotime($userData['expirytime']);
        $isExpired = $expiryTime < time();
    }
    
    // Check if data is depleted
    $isDataDepleted = false;
    if (!empty($userData['mbquota']) && !empty($userData['mbusage'])) {
        $mbQuota = floatval($userData['mbquota']);
        $mbUsage = floatval($userData['mbusage']);
        $isDataDepleted = $mbUsage >= $mbQuota;
    }
    
    // Prepare response
    $response = [
        'hasSubscription' => $hasSubscription,
        'isExpired' => $isExpired,
        'isDataDepleted' => $isDataDepleted,
        'type' => $userData['subscriptionType'] ?? 'quota',
        'mbQuota' => $userData['mbquota'] ?? null,
        'mbUsage' => $userData['mbusage'] ?? null,
        'expiryTime' => $userData['expirytime'] ?? null
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'hasSubscription' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>