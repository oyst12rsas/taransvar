<?php
session_start();
require_once '../db_connect.php';

// Check if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'admin';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $terms = isset($_POST['terms']) ? true : false;
    
    // Validate input
    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirmPassword)) {
        echo json_encode(['status' => 'error', 'message' => 'All fields are required']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address']);
        exit;
    }
    
    if ($password !== $confirmPassword) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
        exit;
    }
    
    if (strlen($password) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long']);
        exit;
    }
    
    if (!$terms) {
        echo json_encode(['status' => 'error', 'message' => 'You must agree to the terms and conditions']);
        exit;
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Email address already in use']);
        exit;
    }
    
    // Check if phone already exists
    $stmt = $conn->prepare("SELECT id FROM admins WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number already in use']);
        exit;
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Set role (only allow admin or support)
    if ($role !== 'admin' && $role !== 'support') {
        $role = 'admin';
    }
    
    try {

        $conn->beginTransaction();
        
        // Insert admin
        $stmt = $conn->prepare("INSERT INTO admins (name, email, phone, password, role, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, 'inactive', NOW())");
        $stmt->execute([$name, $email, $phone, $hashedPassword, $role]);
        $adminId = $conn->lastInsertId();
        
        // Log the registration
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'register', 'Admin account created', ?, NOW())");
        $stmt->execute([$adminId, $_SERVER['REMOTE_ADDR']]);
        
        // Create notification for super admin
        $stmt = $conn->prepare("INSERT INTO admin_notifications (admin_id, title, message, type, created_at) 
                               VALUES (NULL, 'New Admin Registration', ?, 'info', NOW())");
        $stmt->execute(["New admin account created: $name ($email)"]);
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Registration successful. Your account is pending approval by a super admin.'
        ]);
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Registration failed. Please try again.']);
    }
    
    exit;
}


header('Location: login.php');
exit;