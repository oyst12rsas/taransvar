<?php
session_start();
require_once 'db_connect.php';
require_once 'auth_tara.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    
   
    if (empty($email)) {
        header('Location: index.php?error=' . urlencode('Please enter your email address'));
        exit;
    }
    
    
    $result = recoverPassword($email);
    
   
    header('Location: index.php?success=' . urlencode('If your email is registered, you will receive a password reset link shortly.'));
    exit;
} else {
    
    header('Location: index.php');
    exit;
}
?>
