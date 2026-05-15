<?php
session_start();
require_once 'db_connect.php';
require_once 'auth.php';


$token = isset($_GET['token']) ? $_GET['token'] : '';
$error = '';
$success = '';

if (empty($token)) {
    header('Location: index.php');
    exit;
}


$stmt = $conn->prepare("SELECT pr.*, u.email, u.name FROM password_resets pr 
                        JOIN users u ON pr.user_id = u.id 
                        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0 
                        LIMIT 1");
$stmt->execute([$token]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = 'Invalid or expired token. Please request a new password reset link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Validate inputs
    if (empty($password) || empty($confirmPassword)) {
        $error = 'Please fill in all fields.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $result = $stmt->execute([$hashedPassword, $reset['user_id']]);
        
        if ($result) {

            $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
            $stmt->execute([$reset['id']]);
            
            // Send confirmation email
            $subject = "Password Reset Confirmation - Taransvar WiFi";
            $message = "Hello " . $reset['name'] . ",\n\n";
            $message .= "Your password has been successfully reset.\n\n";
            $message .= "If you did not make this change, please contact our support team immediately.\n\n";
            $message .= "Regards,\nTaransvar WiFi Team";
            

            error_log("Password reset confirmation email sent to: " . $reset['email']);
            error_log("Email content: " . $message);
            

            mail($reset['email'], $subject, $message);
            
            $success = 'Your password has been reset successfully. You can now login with your new password.';
        } else {
            $error = 'Failed to reset password. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Taransvar WiFi</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/form-styles.css">
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Reset Your Password</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                            </div>
                            <div class="text-center mt-4">
                                <a href="index.php" class="btn btn-primary">Go to Login</a>
                            </div>
                        <?php elseif (!$error): ?>
                            <p class="mb-4">Please enter your new password below.</p>
                            <form method="post" id="resetPasswordForm">
                                <div class="mb-3">
                                    <label for="password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">Reset Password</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-2">
                                <a href="index.php" class="btn btn-primary">Go to Login</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/form-handlers.js"></script>
</body>
</html>

