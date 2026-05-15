<?php
session_start();
require_once '../db_connect.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// Get admin data
$adminId = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['status'] !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Process wallet actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'add_funds') {
            // Add funds to user's wallet
            $userId = $_POST['user_id'] ?? 0;
            $amount = floatval($_POST['amount'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'manual';
            $mpesaCode = $_POST['mpesa_code'] ?? null;
            $description = $_POST['description'] ?? 'Admin deposit';
            
            if ($userId <= 0) {
                throw new Exception('Invalid user selected');
            }
            
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero');
            }
            
            if ($paymentMethod === 'mpesa' && empty($mpesaCode)) {
                throw new Exception('M-Pesa transaction code is required');
            }
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            

            $stmt = $conn->prepare("SELECT * FROM wallets WHERE user_id = ?");
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) {
                $stmt = $conn->prepare("INSERT INTO wallets (user_id, balance, created_at) VALUES (?, 0, NOW())");
                $stmt->execute([$userId]);
                
                $stmt = $conn->prepare("SELECT * FROM wallets WHERE user_id = ?");
                $stmt->execute([$userId]);
                $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Update wallet balance
            $newBalance = $wallet['balance'] + $amount;
            $stmt = $conn->prepare("UPDATE wallets SET balance = ?, last_deposit_date = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $wallet['id']]);
            
            // Record transaction
            $stmt = $conn->prepare("INSERT INTO wallet_transactions (wallet_id, user_id, amount, type, status, payment_method, mpesa_code, description, created_at) 
                                   VALUES (?, ?, ?, 'deposit', 'completed', ?, ?, ?, NOW())");
            $stmt->execute([$wallet['id'], $userId, $amount, $paymentMethod, $mpesaCode, $description]);
            
            // Log admin activity
            $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                   VALUES (?, 'deposit', ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                "Added KSh $amount to wallet for user ID $userId",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $conn->commit();
            $response = ['success' => true, 'message' => "Successfully added KSh $amount to user's wallet"];
            
        } elseif ($action === 'add_funds_to_wallet') {
            // Add funds to specific wallet
            $walletId = $_POST['wallet_id'] ?? 0;
            $amount = floatval($_POST['amount'] ?? 0);
            $paymentMethod = $_POST['payment_method'] ?? 'manual';
            $mpesaCode = $_POST['mpesa_code'] ?? null;
            $description = $_POST['description'] ?? 'Admin deposit';
            
            if ($walletId <= 0) {
                throw new Exception('Invalid wallet selected');
            }
            
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero');
            }
            
            if ($paymentMethod === 'mpesa' && empty($mpesaCode)) {
                throw new Exception('M-Pesa transaction code is required');
            }
            
            // Check if wallet exists
            $stmt = $conn->prepare("SELECT * FROM wallets WHERE id = ?");
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) {
                throw new Exception('Wallet not found');
            }
            
            // Update wallet balance
            $newBalance = $wallet['balance'] + $amount;
            $stmt = $conn->prepare("UPDATE wallets SET balance = ?, last_deposit_date = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $walletId]);
            
            // Record transaction
            $stmt = $conn->prepare("INSERT INTO wallet_transactions (wallet_id, user_id, amount, type, status, payment_method, mpesa_code, description, created_at) 
                                   VALUES (?, ?, ?, 'deposit', 'completed', ?, ?, ?, NOW())");
            $stmt->execute([$walletId, $wallet['user_id'], $amount, $paymentMethod, $mpesaCode, $description]);
            
            // Log admin activity
            $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                   VALUES (?, 'deposit', ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                "Added KSh $amount to wallet ID $walletId",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $conn->commit();
            $response = ['success' => true, 'message' => "Successfully added KSh $amount to wallet"];
            
        } elseif ($action === 'withdraw_funds') {
            // Withdraw funds from wallet
            $walletId = $_POST['wallet_id'] ?? 0;
            $amount = floatval($_POST['amount'] ?? 0);
            $description = $_POST['description'] ?? 'Admin withdrawal';
            
            if ($walletId <= 0) {
                throw new Exception('Invalid wallet selected');
            }
            
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero');
            }
            
            // Check if wallet exists
            $stmt = $conn->prepare("SELECT * FROM wallets WHERE id = ?");
            $stmt->execute([$walletId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$wallet) {
                throw new Exception('Wallet not found');
            }
            
            // Check if wallet has sufficient balance
            if ($wallet['balance'] < $amount) {
                throw new Exception('Insufficient wallet balance');
            }
            
            // Update wallet balance
            $newBalance = $wallet['balance'] - $amount;
            $stmt = $conn->prepare("UPDATE wallets SET balance = ?, last_withdrawal_date = NOW() WHERE id = ?");
            $stmt->execute([$newBalance, $walletId]);
            

            $stmt = $conn->prepare("INSERT INTO wallet_transactions (wallet_id, user_id, amount, type, status, payment_method, description, created_at) 
                                   VALUES (?, ?, ?, 'withdrawal', 'completed', 'manual', ?, NOW())");
            $stmt->execute([$walletId, $wallet['user_id'], $amount, $description]);
            

            $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                   VALUES (?, 'withdrawal', ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                "Withdrew KSh $amount from wallet ID $walletId",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $conn->commit();
            $response = ['success' => true, 'message' => "Successfully withdrew KSh $amount from wallet"];
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    // Return JSON response for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    

    $redirectUrl = 'wallet_management.php';
    if (isset($_POST['redirect_url'])) {
        $redirectUrl = $_POST['redirect_url'];
    }
    
    $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . 'message=' . urlencode($response['message']) . '&status=' . ($response['success'] ? 'success' : 'error');
    header('Location: ' . $redirectUrl);
    exit;
}


header('Location: wallet_management.php');
exit;