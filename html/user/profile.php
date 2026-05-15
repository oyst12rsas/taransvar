<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access your profile'));
    exit;
}

// Get user information from session
$userId = $_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';
$email = isset($_SESSION['email']) ? $_SESSION['email'] : '';
$phone = isset($_SESSION['phone']) ? $_SESSION['phone'] : '';

// Get user data from radcheck table
$stmt = $conn->prepare("SELECT * FROM radcheck WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If user data not found, use session data as fallback
if (!$user) {
    $user = [
        'id' => $userId,
        'name' => $username,
        'email' => $email,
        'phone' => $phone
    ];
}

$wallet = new Wallet($conn, $userId);
$walletDetails = $wallet->getWalletDetails();

// Handle profile update
$updateSuccess = false;
$updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($phone)) {
        $updateError = 'Please fill in all required fields';
    } else {
        // Check if email is already in use by another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $updateError = 'Email already in use by another account';
        } else {
            // Check if phone is already in use by another user
            $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1");
            $stmt->execute([$phone, $userId]);
            if ($stmt->fetch()) {
                $updateError = 'Phone number already in use by another account';
            } else {
                // Update profile
                $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $email, $phone, $userId])) {
                    // Update session variables
                    $_SESSION['username'] = $name;
                    $_SESSION['email'] = $email;
                    $_SESSION['phone'] = $phone;
                    
                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $updateSuccess = true;
                } else {
                    $updateError = 'Failed to update profile. Please try again.';
                }
            }
        }
    }
}


$passwordSuccess = false;
$passwordError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $passwordError = 'Please fill in all password fields';
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = 'New passwords do not match';
    } elseif (strlen($newPassword) < 8) {
        $passwordError = 'New password must be at least 8 characters long';
    } else {
        // Verify current password
        if (password_verify($currentPassword, $user['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$hashedPassword, $userId])) {
                $passwordSuccess = true;
            } else {
                $passwordError = 'Failed to update password. Please try again.';
            }
        } else {
            $passwordError = 'Current password is incorrect';
        }
    }
}


$pictureSuccess = false;
$pictureError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_picture'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            $pictureError = 'Invalid file type. Please upload a JPEG, PNG, or GIF image.';
        } else {
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $userId . '_' . time() . '.' . $extension;
            $uploadPath = 'uploads/profile_pictures/' . $filename;
            
            // Create directory if it doesn't exist
            if (!file_exists('uploads/profile_pictures/')) {
                mkdir('uploads/profile_pictures/', 0777, true);
            }
            
           
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Update user profile picture in database
                $stmt = $conn->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$filename, $userId])) {
               
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $pictureSuccess = true;
                } else {
                    $pictureError = 'Failed to update profile picture in database';
                }
            } else {
                $pictureError = 'Failed to upload profile picture. Please try again.';
            }
        }
    } else {
        $pictureError = 'Please select a profile picture to upload';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - Taransvar WiFi Hotspot</title>

		
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../lib/animate/animate.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="wallet/css/wallet.css">
    
    <link rel="stylesheet" href="../online-detector/online-detector.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <div class="col-lg-4 col-md-12 text-center text-lg-start">
                <a href="../index.php" class="navbar-brand m-0 p-0 logo-container">
                    <img src="../img/logo-w.png" alt="Logo" width="240px" height="60px" class="logo">
                </a>
            </div>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-globe"></i> Our Websites
                        </a>
                        <ul class="dropdown-menu">
                             <li><a class="dropdown-item" href="../websites/cybersecurity/index.html">
                                <i class="fas fa-shield-virus"></i> Cybersecurity & Mental Health 
                            </a></li>
                            
                        </ul>
                    </li>
                   
                </ul>
                
                <div class="d-flex align-items-center system-status">
							<a href="user-wallet.php" style="text-decoration: none; color: inherit;">
								<div class="mini-wallet-balance me-3">
									<i class="fas fa-wallet"></i>
									<span>KSh <?php echo number_format($walletDetails['balance'], 2); ?></span>
								</div>
							</a>
                    <span class="badge" id="connection-badge">
						<i class="fas fa-circle"></i>
						<span class="connection-status-text">Checking...</span>
					</span>
                    <div class="dropdown">
                        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['name']); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
							<li><a class="dropdown-item" href="../index.php">Home</a></li>
                            <li><a class="dropdown-item active" href="dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php">Profile Settings</a></li>
							<li><a class="dropdown-item" href="user-wallet.php">My Wallet</a></li>
                            <li><a class="dropdown-item" href="usage-history.php">Usage History</a></li>
							<li><a class="dropdown-item" href="payment-history.php">Payment History</a></li>
							<li><a class="dropdown-item" href="user-plans.php">Wifi Plans</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            <li><hr class="dropdown-divider"></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="dashboard-sidebar">
            <div class="user-profile text-center">
                <div class="profile-image">
                    <?php if (isset($user['profile_picture']) && !empty($user['profile_picture'])): ?>
                        <img src="uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="User Avatar" class="rounded-circle">
                    <?php else: ?>
                        <img src="img/user-avatar.png" alt="User Avatar" class="rounded-circle">
                    <?php endif; ?>
                    <a href="#" class="edit-profile-btn" data-bs-toggle="modal" data-bs-target="#profilePictureModal">
                        <i class="fas fa-camera"></i>
                    </a>
                </div>
                <h4><?php echo htmlspecialchars($user['name']); ?></h4>
                <p class="text-muted"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
					<li class="nav-item">
                        <a class="nav-link" href="../index.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php">
                            <i class="fas fa-user-cog"></i> Profile Settings
                        </a>
                    </li>
					<li class="nav-item">
                        <a class="nav-link" href="user-wallet.php">
                            <i class="fas fa-wallet"></i> My Wallet
                        </a>
                    </li>					
                    <li class="nav-item">
                        <a class="nav-link" href="user-plans.php">
                            <i class="fas fa-list-alt"></i> WiFi Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="payment-history.php">
                            <i class="fas fa-history"></i> Payment History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="usage-history.php">
                            <i class="fas fa-chart-line"></i> Usage History
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="dashboard-main">
            <div class="container-fluid">
                <h1 class="dashboard-title">
                    <i class="fas fa-user-cog"></i> Profile Settings
                </h1>
                
                <!-- Profile Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Personal Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($updateSuccess): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i> Your profile has been updated successfully.
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($updateError): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($updateError); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form action="profile.php" method="post">
                            <input type="hidden" name="update_profile" value="1">
                            
                            <div class="row mb-3">
                                <label for="name" class="col-sm-3 col-form-label">Full Name</label>
                                <div class="col-sm-9">
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="email" class="col-sm-3 col-form-label">Email Address</label>
                                <div class="col-sm-9">
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="phone" class="col-sm-3 col-form-label">Phone Number</label>
                                <div class="col-sm-9">
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label class="col-sm-3 col-form-label">Account Status</label>
                                <div class="col-sm-9">
                                    <p class="form-control-plaintext">
                                        <?php if ($user['status'] == 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif ($user['status'] == 'inactive'): ?>
                                            <span class="badge bg-warning text-dark">Inactive</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Suspended</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label class="col-sm-3 col-form-label">Last Login</label>
                                <div class="col-sm-9">
                                    <p class="form-control-plaintext">
                                        <?php echo $user['last_login'] ? date('F d, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-sm-9 offset-sm-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Update Profile
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
      
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-lock"></i> Change Password</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($passwordSuccess): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i> Your password has been changed successfully.
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($passwordError): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($passwordError); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form action="profile.php" method="post">
                            <input type="hidden" name="change_password" value="1">
                            
                            <div class="row mb-3">
                                <label for="current_password" class="col-sm-3 col-form-label">Current Password</label>
                                <div class="col-sm-9">
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="new_password" class="col-sm-3 col-form-label">New Password</label>
                                <div class="col-sm-9">
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">Password must be at least 8 characters long</div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="confirm_password" class="col-sm-3 col-form-label">Confirm New Password</label>
                                <div class="col-sm-9">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-sm-9 offset-sm-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i> Change Password
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cog"></i> Account Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <a href="export-data.php" class="btn btn-outline-primary">
                                        <i class="fas fa-download me-2"></i> Export My Data
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-grid">
                                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                        <i class="fas fa-trash-alt me-2"></i> Delete My Account
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Profile Picture Modal -->
    <div class="modal fade" id="profilePictureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($pictureSuccess): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i> Your profile picture has been updated successfully.
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($pictureError): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($pictureError); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="profile.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="upload_picture" value="1">
                        
                        <div class="text-center mb-4">
                            <div class="profile-preview">
                                <?php if (isset($user['profile_picture']) && !empty($user['profile_picture'])): ?>
                                    <img src="uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Preview" class="img-thumbnail rounded-circle">
                                <?php else: ?>
                                    <img src="img/user-avatar.png" alt="Profile Preview" class="img-thumbnail rounded-circle">
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="profile_picture" class="form-label">Select New Profile Picture</label>
                            <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif" required>
                            <div class="form-text">Supported formats: JPEG, PNG, GIF. Max size: 2MB.</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i> Upload Picture
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> <strong>Warning:</strong> This action cannot be undone.
                    </div>
                    <p>Are you sure you want to delete your account? This will permanently remove all your data, including:</p>
                    <ul>
                        <li>Personal information</li>
                        <li>Payment history</li>
                        <li>Usage history</li>
                        <li>Active WiFi sessions</li>
                    </ul>
                    <p>To confirm, please enter your password:</p>
                    <form id="deleteAccountForm" action="delete-account.php" method="post">
                        <div class="mb-3">
                            <input type="password" class="form-control" id="delete_password" name="password" placeholder="Enter your password" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteAccountForm" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i> Delete Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer bg-dark text-white mt-auto">
        <div class="container py-4">
            <div class="row">
                <div class="col-lg-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-white">Home</a></li>
                        <li><a href="user-plans.php" class="text-white">WiFi Plans</a></li>
                        <li><a href="support.html" class="text-white">Support</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h5>Contact Us</h5>
                    <p><i class="fas fa-envelope me-2"></i> info@taransvar.no</p>
                    <p><i class="fas fa-phone me-2"></i> +254 712 345 678</p>
                </div>
                <div class="col-lg-4">
                    <h5>Connect With Us</h5>
                    <div class="social-links">
                        <a href="#" class="btn btn-outline-light me-2"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="btn btn-outline-light me-2"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="btn btn-outline-light"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12 text-center">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> Taransvar. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../lib/wow/wow.min.js"></script>
    <script src="../lib/easing/easing.min.js"></script>
    <script src="../lib/waypoints/waypoints.min.js"></script>
    <script src="../lib/counterup/counterup.min.js"></script>
    <script src="../lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="../lib/lightbox/js/lightbox.min.js"></script>


<script src="../online-detector/online-detector.js"></script>
<script src="../online-detector/subscription-status.js"></script>
	
    <script>
        // Initialize WOW.js for animations
        new WOW().init();
        

        document.addEventListener('DOMContentLoaded', function() {
            const profilePictureInput = document.getElementById('profile_picture');
            const profilePreview = document.querySelector('.profile-preview img');
            
            if (profilePictureInput && profilePreview) {
                profilePictureInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        
                        reader.onload = function(e) {
                            profilePreview.src = e.target.result;
                        }
                        
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }
        });
    </script>
	
	
	
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Online Detector
    const detector = new OnlineDetector({
        checkInterval: 5000,
        timeout: 3000,
        popupDuration: 7000,
        popupCooldown: 60000
    });
    
    // Replace connection badge
    detector.replaceHeaderBadge('#connection-badge');
    
    // Initialize Subscription Status
    const subscription = new SubscriptionStatus({
        checkInterval: 10000,
        popupDuration: 7000,
        popupCooldown: 60000
    });
    
    // Add subscription badge next to connection badge
    subscription.addSubscriptionBadge('#connection-badge');
});
</script>
	
	
</body>
</html>
