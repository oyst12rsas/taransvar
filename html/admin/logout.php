<?php
session_start();
require_once '../db_connect.php';

// Check if admin is logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_session_id'])) {
    $adminId = $_SESSION['admin_id'];
    $sessionId = $_SESSION['admin_session_id'];
    
    // Log the logout activity
    $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                           VALUES (?, 'logout', 'Admin logged out', ?, NOW())");
    $stmt->execute([$adminId, $_SERVER['REMOTE_ADDR']]);
    
    // Delete the session from database
    $stmt = $conn->prepare("DELETE FROM admin_sessions WHERE admin_id = ? AND session_id = ?");
    $stmt->execute([$adminId, $sessionId]);
    
    // Clear remember me cookie if it exists
    if (isset($_COOKIE['admin_remember'])) {
        $cookieData = explode(':', $_COOKIE['admin_remember']);
        if (count($cookieData) === 2) {
            $rememberToken = $cookieData[1];
            

            $stmt = $conn->prepare("DELETE FROM admin_sessions WHERE admin_id = ? AND session_id = ?");
            $stmt->execute([$adminId, $rememberToken]);
        }
        
        // Expire the cookie
        setcookie('admin_remember', '', time() - 3600, '/', '', false, true);
    }
}


$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php?message=' . urlencode('You have been successfully logged out.'));
exit;