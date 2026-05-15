<?php
session_start();
require_once '..\db_connect.php';
require_once 'classes/Wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?error=' . urlencode('Please login to export wallet transactions'));
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  
    session_destroy();
    header('Location: index.php?error=' . urlencode('User not found'));
    exit;
}

// Initialize wallet
$wallet = new Wallet($conn, $userId);

// Get filter parameters
$type = isset($_GET['type']) ? $_GET['type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Get all transactions for export
$transactions = $wallet->getTransactions(null, 0, $type, $status);


header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="wallet_transactions_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');


fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add CSV header row
fputcsv($output, [
    'Date', 
    'Type', 
    'Amount', 
    'Status', 
    'Payment Method', 
    'M-Pesa Code', 
    'Reference', 
    'Description'
]);


foreach ($transactions as $transaction) {
    // Format amount with sign
    $amount = $transaction['amount'];
    if ($transaction['type'] == 'withdrawal' || $transaction['type'] == 'purchase') {
        $amount = -$amount;
    }
    
    fputcsv($output, [
        date('Y-m-d H:i:s', strtotime($transaction['created_at'])),
        ucfirst($transaction['type']),
        $amount,
        ucfirst($transaction['status']),
        ucfirst($transaction['payment_method']),
        $transaction['mpesa_code'] ?: 'N/A',
        $transaction['reference_id'] ?: 'N/A',
        $transaction['description'] ?: 'N/A'
    ]);
}


fclose($output);
exit;
?>