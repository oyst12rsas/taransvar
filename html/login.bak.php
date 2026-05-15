<?php
session_start();
require_once 'db_connect.php';
require_once 'auth.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = isset($_POST['login_type']) ? $_POST['login_type'] : '';
    
    // Quick login (phone + M-Pesa)
    if ($loginType === 'quick') {
        $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
        $mpesaCode = isset($_POST['mpesa_code']) ? $_POST['mpesa_code'] : '';
        
        // Validate inputs
        if (empty($phone) || empty($mpesaCode)) {
            header('Location: index.php?error=' . urlencode('Please fill in all fields') . '&login=1');
            exit;
        }
        
        // Format phone number if needed
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        
        // Verify login
        $result = verifyQuickLogin($phone, $mpesaCode);
        
        if ($result['status']) {
            // Successful login
            header('Location: connected.php?session=' . $result['session_id']);
            exit;
        } else {
            // Failed login
            header('Location: index.php?error=' . urlencode($result['message']) . '&login=1');
            exit;
        }
    }
    

    elseif ($loginType === 'account') {
        $email = isset($_POST['email']) ? $_POST['email'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = isset($_POST['remember']) ? true : false;
        
        // Validate inputs
        if (empty($email) || empty($password)) {
            header('Location: index.php?error=' . urlencode('Please fill in all fields') . '&login=1');
            exit;
        }
        

        error_log("Login attempt for email: " . $email);
        

        $result = verifyAccountLogin($email, $password, $remember);
        
        if ($result['status']) {
            // Successful login
            // Check if user directory and dashboard exist
            if (is_dir('user') && file_exists('user/dashboard.php')) {
                header('Location: user/dashboard.php');
            } else {
                // Fallback to index.php if dashboard doesn't exist
                error_log("Dashboard not found, redirecting to index.php");
                header('Location: index.php?success=' . urlencode('Login successful!'));
            }
            exit;
        } else {
            // Failed login
            error_log("Login failed: " . $result['message']);
            header('Location: index.php?error=' . urlencode($result['message']) . '&login=1');
            exit;
        }
    }
    

    else {
        header('Location: index.php?error=' . urlencode('Invalid login type') . '&login=1');
        exit;
    }
} else {

    header('Location: index.php');
    exit;
}
?>


