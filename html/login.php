<?php
session_start();
require_once 'db_connect.php';
require_once 'auth_tara.php';	#OT 250318


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginType = isset($_POST['login_type']) ? $_POST['login_type'] : '';
    

    if ($loginType === 'quick') {
        $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
        $mpesaCode = isset($_POST['mpesa_code']) ? $_POST['mpesa_code'] : '';

        if (empty($phone) || empty($mpesaCode)) {
            header('Location: index.php?error=' . urlencode('Please fill in all fields'));
            exit;
        }

        $result = 0;//verifyQuickLogin($phone, $mpesaCode); OT 250318
        
        if ($result['status']) {
            
            header('Location: connected.php?session=' . $result['session_id']);
            exit;
        } else {
            
            header('Location: index.php?error=' . urlencode($result['message']));
            exit;
        }
    }
 
    elseif ($loginType === 'account') {
        $email = isset($_POST['email']) ? $_POST['email'] : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = isset($_POST['remember']) ? true : false;
  
        if (empty($email) || empty($password)) {
            header('Location: index.php?error=' . urlencode('Please fill in all fields'));
            exit;
        }
        
  
        $result = verifyAccountLogin($email, $password, $remember);	
        
        if ($result['status']) {
           
            header('Location: dashboard.php');
            exit;
        } else {
           
            header('Location: index.php?error=' . urlencode($result['message']) . '&login=1');
            exit;
        }
    }
    
  
    else {
        header('Location: index.php?error=' . urlencode('Invalid login type'));
        exit;
    }
} else {
    
    header('Location: index.php');
    exit;
}
?>
