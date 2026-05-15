<?php
session_start();
require_once '../db_connect.php';

// Check if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}

// Initialize variables
$error = '';
$email = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    // Get form data
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;
    
    // Validate input
    if (empty($email) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter both email and password']);
        exit;
    }
    

    $stmt = $conn->prepare("SELECT * FROM admins WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($password, $admin['password'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
        exit;
    }
    
    // Set session variables
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_role'] = $admin['role'];
    
    // Update last login time
    $stmt = $conn->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$admin['id']]);
    
    // Log the login activity
    $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                           VALUES (?, 'login', 'Admin logged in', ?, NOW())");
    $stmt->execute([$admin['id'], $_SERVER['REMOTE_ADDR']]);
    
    // Create admin session record
    $sessionId = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT INTO admin_sessions (admin_id, session_id, ip_address, user_agent, last_activity, created_at) 
                           VALUES (?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$admin['id'], $sessionId, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
    

    $_SESSION['admin_session_id'] = $sessionId;
    
    // Set remember me cookie if requested
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        
        setcookie('admin_remember', $admin['id'] . ':' . $token, $expires, '/', '', false, true);
        
        // Store token in database
        $stmt = $conn->prepare("INSERT INTO admin_sessions (admin_id, session_id, ip_address, user_agent, last_activity, created_at) 
                               VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$admin['id'], $token, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
    }
    
    echo json_encode(['status' => 'success', 'redirect' => 'admin_dashboard.php']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Taransvar WiFi Hotspot</title>

	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">

 
    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/admin_dashboard.css">
    <style>
        body {
            font-family: 'Open Sans', sans-serif;
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            padding: 20px;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .card-header {
            background-color: #343a40;
            color: white;
            text-align: center;
            padding: 20px;
            border-bottom: none;
        }
        .logo {
            max-width: 200px;
            margin-bottom: 15px;
        }
        .form-control {
            border-radius: 5px;
            padding: 12px;
            margin-bottom: 15px;
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
            border-radius: 5px;
            padding: 12px;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: #0069d9;
        }
        .form-check-label {
            font-size: 14px;
        }
        .forgot-password {
            font-size: 14px;
        }
        .register-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .alert {
            border-radius: 5px;
            padding: 12px;
            margin-bottom: 15px;
        }
        .modal-header {
            background-color: #343a40;
            color: white;
        }
        .modal-footer {
            border-top: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header">
                <img src="../img/logo-w.png" alt="Logo" class="logo">
                <h4>Admin Login</h4>
            </div>
            <div class="card-body">
                <div id="login-alert" class="alert alert-danger d-none" role="alert"></div>
                
                <form id="login-form">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggle-password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember Me</label>
                        </div>
                        <a href="#" class="forgot-password" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100" id="login-btn">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" id="login-spinner"></span>
                        <span id="login-btn-text">Login</span>
                    </button>
                </form>
            </div>
        </div>
        
        <div class="register-link">
            Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a>
        </div>
    </div>
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-labelledby="forgotPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="forgotPasswordModalLabel">Reset Password</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="forgot-alert" class="alert d-none" role="alert"></div>
                    
                    <form id="forgot-form">
                        <div class="mb-3">
                            <label for="forgot-email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="forgot-email" name="email" required>
                            <div class="form-text">We'll send a password reset link to this email.</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="forgot-btn">
                                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" id="forgot-spinner"></span>
                                <span id="forgot-btn-text">Send Reset Link</span>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Register Modal -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="registerModalLabel">Admin Registration</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="register-alert" class="alert d-none" role="alert"></div>
                    
                    <form id="register-form">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="register-name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="register-name" name="name" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="register-email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="register-email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="register-phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="register-phone" name="phone" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="register-role" class="form-label">Role</label>
                                <select class="form-select" id="register-role" name="role" required>
                                    <option value="admin">Admin</option>
                                    <option value="support">Support</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="register-password" class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="register-password" name="password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggle-register-password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength mt-2 d-none" id="password-strength">
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">Password strength: <span id="strength-text">Weak</span></small>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="register-confirm-password" class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="register-confirm-password" name="confirm_password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggle-confirm-password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="register-terms" name="terms" required>
                            <label class="form-check-label" for="register-terms">I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a></label>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="register-btn">
                                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" id="register-spinner"></span>
                                <span id="register-btn-text">Register</span>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Terms and Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="termsModalLabel">Terms and Conditions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h5>1. Introduction</h5>
                    <p>These terms and conditions govern your use of the Taransvar WiFi Hotspot admin system. By using this system, you accept these terms and conditions in full.</p>
                    
                    <h5>2. Responsibilities</h5>
                    <p>As an administrator, you are responsible for:</p>
                    <ul>
                        <li>Maintaining the confidentiality of your login credentials</li>
                        <li>All activities that occur under your account</li>
                        <li>Ensuring that all actions taken comply with relevant laws and regulations</li>
                        <li>Protecting user data and privacy</li>
                    </ul>
                    
                    <h5>3. Data Protection</h5>
                    <p>You must comply with all applicable data protection laws when handling user data. This includes:</p>
                    <ul>
                        <li>Only accessing user data for legitimate business purposes</li>
                        <li>Not sharing user data with unauthorized third parties</li>
                        <li>Implementing appropriate security measures to protect user data</li>
                    </ul>
                    
                    <h5>4. System Security</h5>
                    <p>You must take reasonable steps to ensure the security of the system, including:</p>
                    <ul>
                        <li>Using strong passwords</li>
                        <li>Not sharing your login credentials with others</li>
                        <li>Logging out when not using the system</li>
                        <li>Reporting any security vulnerabilities or breaches immediately</li>
                    </ul>
                    
                    <h5>5. Termination</h5>
                    <p>Your access to the admin system may be terminated if you violate these terms and conditions or for any other reason at the discretion of the system owner.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle password visibility
            $('#toggle-password').click(function() {
                const passwordField = $('#password');
                const passwordFieldType = passwordField.attr('type');
                const icon = $(this).find('i');
                
                if (passwordFieldType === 'password') {
                    passwordField.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordField.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Toggle register password visibility
            $('#toggle-register-password').click(function() {
                const passwordField = $('#register-password');
                const passwordFieldType = passwordField.attr('type');
                const icon = $(this).find('i');
                
                if (passwordFieldType === 'password') {
                    passwordField.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordField.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Toggle confirm password visibility
            $('#toggle-confirm-password').click(function() {
                const passwordField = $('#register-confirm-password');
                const passwordFieldType = passwordField.attr('type');
                const icon = $(this).find('i');
                
                if (passwordFieldType === 'password') {
                    passwordField.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordField.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Password strength meter
            $('#register-password').on('input', function() {
                const password = $(this).val();
                let strength = 0;
                let strengthText = '';
                let progressColor = '';
                
                if (password.length === 0) {
                    $('#password-strength').addClass('d-none');
                    return;
                }
                
                $('#password-strength').removeClass('d-none');
                
                // Check password length
                if (password.length >= 8) {
                    strength += 25;
                }
                
                // Check for mixed case
                if (password.match(/[a-z]/) && password.match(/[A-Z]/)) {
                    strength += 25;
                }
                

                if (password.match(/\d/)) {
                    strength += 25;
                }
                

                if (password.match(/[^a-zA-Z\d]/)) {
                    strength += 25;
                }
                

                if (strength <= 25) {
                    strengthText = 'Weak';
                    progressColor = 'bg-danger';
                } else if (strength <= 50) {
                    strengthText = 'Fair';
                    progressColor = 'bg-warning';
                } else if (strength <= 75) {
                    strengthText = 'Good';
                    progressColor = 'bg-info';
                } else {
                    strengthText = 'Strong';
                    progressColor = 'bg-success';
                }
                
                // Update UI
                $('#strength-text').text(strengthText);
                const progressBar = $('.progress-bar');
                progressBar.removeClass('bg-danger bg-warning bg-info bg-success').addClass(progressColor);
                progressBar.css('width', strength + '%').attr('aria-valuenow', strength);
            });
            
            // Login form submission
            $('#login-form').submit(function(e) {
                e.preventDefault();
                
                // Show spinner
                $('#login-btn-text').text('Logging in...');
                $('#login-spinner').removeClass('d-none');
                $('#login-btn').prop('disabled', true);
                $('#login-alert').addClass('d-none');
                
                // Get form data
                const formData = {
                    email: $('#email').val(),
                    password: $('#password').val(),
                    remember: $('#remember').is(':checked') ? 1 : 0
                };
                
                // Send AJAX request
                $.ajax({
                    type: 'POST',
                    url: 'login.php',
                    data: formData,
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            window.location.href = response.redirect;
                        } else {
                            $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text(response.message);
                        }
                    },
                    error: function() {
                        $('#login-alert').removeClass('d-none alert-success').addClass('alert-danger').text('An error occurred. Please try again.');
                    },
                    complete: function() {
                        // Hide spinner
                        $('#login-btn-text').text('Login');
                        $('#login-spinner').addClass('d-none');
                        $('#login-btn').prop('disabled', false);
                    }
                });
            });
            

            $('#register-form').submit(function(e) {
                e.preventDefault();
                
                // Show spinner
                $('#register-btn-text').text('Registering...');
                $('#register-spinner').removeClass('d-none');
                $('#register-btn').prop('disabled', true);
                $('#register-alert').addClass('d-none');
                

                const password = $('#register-password').val();
                const confirmPassword = $('#register-confirm-password').val();
                
                if (password !== confirmPassword) {
                    $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text('Passwords do not match.');
                    $('#register-btn-text').text('Register');
                    $('#register-spinner').addClass('d-none');
                    $('#register-btn').prop('disabled', false);
                    return;
                }
                
                // Get form data
                const formData = {
                    name: $('#register-name').val(),
                    email: $('#register-email').val(),
                    phone: $('#register-phone').val(),
                    role: $('#register-role').val(),
                    password: password,
                    confirm_password: confirmPassword,
                    terms: $('#register-terms').is(':checked') ? 1 : 0
                };
                
                // Send AJAX request
                $.ajax({
                    type: 'POST',
                    url: 'register.php',
                    data: formData,
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#register-alert').removeClass('d-none alert-danger').addClass('alert-success').text(response.message);
                            $('#register-form')[0].reset();
                            
 
                            setTimeout(function() {
                                $('#registerModal').modal('hide');
                                $('#login-alert').removeClass('d-none alert-danger').addClass('alert-success').text('Registration successful. Please login with your credentials.');
                            }, 3000);
                        } else {
                            $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text(response.message);
                        }
                    },
                    error: function() {
                        $('#register-alert').removeClass('d-none alert-success').addClass('alert-danger').text('An error occurred. Please try again.');
                    },
                    complete: function() {
                        // Hide spinner
                        $('#register-btn-text').text('Register');
                        $('#register-spinner').addClass('d-none');
                        $('#register-btn').prop('disabled', false);
                    }
                });
            });
            
 
            $('#forgot-form').submit(function(e) {
                e.preventDefault();
                
                // Show spinner
                $('#forgot-btn-text').text('Sending...');
                $('#forgot-spinner').removeClass('d-none');
                $('#forgot-btn').prop('disabled', true);
                $('#forgot-alert').addClass('d-none');
                
                // Get form data
                const formData = {
                    email: $('#forgot-email').val()
                };
                

                $.ajax({
                    type: 'POST',
                    url: 'forgot_password.php',
                    data: formData,
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#forgot-alert').removeClass('d-none alert-danger').addClass('alert-success').text(response.message);
                            $('#forgot-form')[0].reset();
                        } else {
                            $('#forgot-alert').removeClass('d-none alert-success').addClass('alert-danger').text(response.message);
                        }
                    },
                    error: function() {
                        $('#forgot-alert').removeClass('d-none alert-success').addClass('alert-danger').text('An error occurred. Please try again.');
                    },
                    complete: function() {
                        // Hide spinner
                        $('#forgot-btn-text').text('Send Reset Link');
                        $('#forgot-spinner').addClass('d-none');
                        $('#forgot-btn').prop('disabled', false);
                    }
                });
            });
        });
    </script>
</body>
</html>