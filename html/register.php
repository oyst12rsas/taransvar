<?php
session_start();
require_once 'db_connect.php';
require_once 'auth_tara.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? $_POST['name'] : '';
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $terms = isset($_POST['terms']) ? true : false;
    

    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirmPassword)) {
        header('Location: index.php?error=' . urlencode('Please fill in all fields') . '&register=1');
        exit;
    }

    if (!$terms) {
        header('Location: index.php?error=' . urlencode('You must accept the Terms of Service') . '&register=1');
        exit;
    }

    $result = registerUser($name, $email, $phone, $password, $confirmPassword);
    
    if ($result['status']) {
        
        header('Location: index.php?success=' . urlencode($result['message']) . '&login=1');
        exit;
    } else {
     
        header('Location: index.php?error=' . urlencode($result['message']) . '&register=1');
        exit;
    }
} else {
    
    header('Location: index.php');
    exit;
}
?>
