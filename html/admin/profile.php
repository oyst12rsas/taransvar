<?php
session_start();
require_once '../db_connect.php';


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


$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Get form data
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Validate input
        $errors = [];
        
        if (empty($name)) {
            $errors[] = 'Name is required';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } else {
            // Check if email already exists for another admin
            $stmt = $conn->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
            $stmt->execute([$email, $adminId]);
            if ($stmt->rowCount() > 0) {
                $errors[] = 'Email address already in use by another admin';
            }
        }
        
        // If no errors, update profile
        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("UPDATE admins SET name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->execute([$name, $email, $phone, $adminId]);
                
                // Log the activity
                $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                       VALUES (?, 'update', ?, ?, NOW())");
                $stmt->execute([
                    $adminId,
                    "Updated profile information",
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                // Refresh admin data
                $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
                $stmt->execute([$adminId]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $message = "Profile updated successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error updating profile: " . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'danger';
        }
    } elseif (isset($_POST['change_password'])) {
        // Get form data
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate input
        $errors = [];
        
        if (empty($currentPassword)) {
            $errors[] = 'Current password is required';
        } elseif (!password_verify($currentPassword, $admin['password'])) {
            $errors[] = 'Current password is incorrect';
        }
        
        if (empty($newPassword)) {
            $errors[] = 'New password is required';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long';
        }
        
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match';
        }
        
        // If no errors, update password
        if (empty($errors)) {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $adminId]);
                
                // Log the activity
                $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                       VALUES (?, 'update', ?, ?, NOW())");
                $stmt->execute([
                    $adminId,
                    "Changed password",
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                $message = "Password changed successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error changing password: " . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'danger';
        }
    } elseif (isset($_POST['upload_photo'])) {
        // Handle photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $maxSize = 2 * 1024 * 1024; // 2MB
            
            $file = $_FILES['photo'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = $file['type'];
            
            // Validate file type and size
            $errors = [];
            
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = 'Invalid file type. Only JPG, PNG, and GIF files are allowed.';
            }
            
            if ($fileSize > $maxSize) {
                $errors[] = 'File size exceeds the maximum limit of 2MB.';
            }
            
            // If no errors, process the upload
            if (empty($errors)) {
                try {
                    // Create upload directory if it doesn't exist
                    $uploadDir = '../uploads/admin_photos/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    // Generate a unique filename
                    $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                    $newFileName = 'admin_' . $adminId . '_' . time() . '.' . $fileExt;
                    $targetFilePath = $uploadDir . $newFileName;
                    
  
                    if (move_uploaded_file($fileTmpName, $targetFilePath)) {
                        // Delete old photo if exists
                        if (!empty($admin['photo']) && $admin['photo'] !== 'default.jpg' && file_exists($uploadDir . $admin['photo'])) {
                            unlink($uploadDir . $admin['photo']);
                        }
                        

                        $stmt = $conn->prepare("UPDATE admins SET photo = ? WHERE id = ?");
                        $stmt->execute([$newFileName, $adminId]);
                        
                        // Log the activity
                        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                               VALUES (?, 'update', ?, ?, NOW())");
                        $stmt->execute([
                            $adminId,
                            "Updated profile photo",
                            $_SERVER['REMOTE_ADDR']
                        ]);
                        
                        // Refresh admin data
                        $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
                        $stmt->execute([$adminId]);
                        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $message = "Profile photo updated successfully!";
                        $messageType = 'success';
                    } else {
                        $message = "Error uploading file.";
                        $messageType = 'danger';
                    }
                } catch (Exception $e) {
                    $message = "Error uploading photo: " . $e->getMessage();
                    $messageType = 'danger';
                }
            } else {
                $message = implode('<br>', $errors);
                $messageType = 'danger';
            }
        } else {
            $message = "Please select a file to upload.";
            $messageType = 'warning';
        }
    }
}


$stmt = $conn->prepare("SELECT * FROM admin_activity_logs WHERE admin_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$adminId]);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to format time ago
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) {
        return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    } elseif ($diff->m > 0) {
        return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    } elseif ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'Just now';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Taransvar WiFi Hotspot</title>
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">

 
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_dashboard.css">
    <style>
        .profile-header {
            position: relative;
            background-color: #4e73df;
            color: white;
            padding: 2rem 0;
            border-radius: 0.5rem;
            margin-bottom: 2rem;
        }
        
        .profile-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .profile-img-container {
            position: relative;
            display: inline-block;
        }
        
        .profile-img-edit {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .profile-info {
            margin-top: 1rem;
        }
        
        .nav-pills .nav-link.active {
            background-color: #4e73df;
        }
        
        .nav-pills .nav-link {
            color: #5a5c69;
        }
        
        .nav-pills .nav-link.active {
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
        <div class="container-fluid">
            <a href="admin_dashboard.php" class="navbar-brand">
                <img src="../img/logo-w.png" alt="Logo" class="d-inline-block align-text-top">
                <span class="ms-2 d-none d-sm-inline-block">Admin</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdmin">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarAdmin">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="plans.php">
                            <i class="fas fa-list-alt"></i> Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transactions.php">
                            <i class="fas fa-money-bill-wave"></i> Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sessions.php">
                            <i class="fas fa-wifi"></i> Sessions
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog"></i> More
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="activity_log.php">
                                    <i class="fas fa-history"></i> Activity Log
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="notifications.php">
                                    <i class="fas fa-bell"></i> Notifications
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="wallet_management.php">
                                    <i class="fas fa-wallet"></i> Wallet Management
                                </a>
                            </li>
							<?php if ($admin['role'] === 'super_admin'): ?>
                            <li>
                                <a class="dropdown-item " href="admin_approval.php">
                                    <i class="fas fa-user-shield"></i> Admin Approval
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                </ul>
                
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <?php if (!empty($admin['photo']) && file_exists("../uploads/admin_photos/{$admin['photo']}")): ?>
                                <img src="../uploads/admin_photos/<?php echo $admin['photo']; ?>" alt="Profile" class="rounded-circle me-1" width="32" height="32">
                            <?php else: ?>
                                <img src="../uploads/admin_photos/default.jpg" alt="Profile" class="rounded-circle me-1" width="32" height="32">
                            <?php endif; ?>
                            <?php echo htmlspecialchars($admin['name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item active" href="profile.php">
                                    <i class="fas fa-user me-2"></i> My Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="activity_log.php">
                                    <i class="fas fa-history me-2"></i> Activity Log
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar d-none d-lg-block">
            <div class="position-sticky">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="plans.php">
                            <i class="fas fa-list-alt"></i> Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="transactions.php">
                            <i class="fas fa-money-bill-wave"></i> Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sessions.php">
                            <i class="fas fa-wifi"></i> Sessions
                        </a>
                    </li>
                    
                    <li class="nav-item">
                        <hr class="sidebar-divider">
                    </li>
                    
                    <li class="nav-item">
                        <div class="sidebar-heading">Quick Actions</div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_user.php">
                            <i class="fas fa-user-plus"></i> Add User
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="add_plan.php">
                            <i class="fas fa-plus-circle"></i> Add Plan
                        </a>
                    </li>
                    <li class="nav-item">
                        <hr class="sidebar-divider">
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php" target="_blank">
                            <i class="fas fa-external-link-alt"></i> Main Website
                        </a>
                    </li>
					<li class="nav-item">
                        <a class="nav-link" href="../../hotspot/index.php" target="_blank">
                            <i class="fas fa-cogs"></i>  Hostspot System
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="container-fluid px-4">
                <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="profile-header text-center">
                    <div class="profile-img-container">
                        <?php if (!empty($admin['photo']) && file_exists("../uploads/admin_photos/{$admin['photo']}")): ?>
                            <img src="../uploads/admin_photos/<?php echo $admin['photo']; ?>" alt="Profile" class="rounded-circle profile-img">
                        <?php else: ?>
                            <img src="../uploads/admin_photos/default.jpg" alt="Profile" class="rounded-circle profile-img">
                        <?php endif; ?>
                        <div class="profile-img-edit" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($admin['name']); ?></h3>
                        <p class="mb-0"><?php echo ucfirst($admin['role']); ?></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <ul class="nav nav-pills card-header-pills" id="profileTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">Profile Information</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab" aria-controls="password" aria-selected="false">Change Password</button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab" aria-controls="activity" aria-selected="false">Recent Activity</button>
                                    </li>
                                </ul>
                            </div>
                            <div class="card-body">
                                <div class="tab-content" id="profileTabsContent">
                                    <!-- Profile Information Tab -->
                                    <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                                        <form method="post" action="profile.php">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="name" class="form-label">Full Name</label>
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="email" class="form-label">Email Address</label>
                                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="phone" class="form-label">Phone Number</label>
                                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($admin['phone']); ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="role" class="form-label">Role</label>
                                                    <input type="text" class="form-control" id="role" value="<?php echo ucfirst($admin['role']); ?>" readonly>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="status" class="form-label">Account Status</label>
                                                    <input type="text" class="form-control" id="status" value="<?php echo ucfirst($admin['status']); ?>" readonly>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="last_login" class="form-label">Last Login</label>
                                                    <input type="text" class="form-control" id="last_login" value="<?php echo $admin['last_login'] ? date('M d, Y H:i:s', strtotime($admin['last_login'])) : 'Never'; ?>" readonly>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Change Password Tab -->
                                    <div class="tab-pane fade" id="password" role="tabpanel" aria-labelledby="password-tab">
                                        <form method="post" action="profile.php">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="current_password" class="form-label">Current Password</label>
                                                    <div class="input-group">
                                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="current_password">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label for="new_password" class="form-label">New Password</label>
                                                    <div class="input-group">
                                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                    <div class="form-text">Password must be at least 8 characters long.</div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                                    <div class="input-group">
                                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Recent Activity Tab -->
                                    <div class="tab-pane fade" id="activity" role="tabpanel" aria-labelledby="activity-tab">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Action</th>
                                                        <th>Description</th>
                                                        <th>IP Address</th>
                                                        <th>Date/Time</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($activities) > 0): ?>
                                                        <?php foreach ($activities as $activity): ?>
                                                        <tr>
                                                            <td>
                                                                <span class="badge bg-<?php 
                                                                    switch ($activity['action']) {
                                                                        case 'login': echo 'success'; break;
                                                                        case 'logout': echo 'secondary'; break;
                                                                        case 'create': echo 'primary'; break;
                                                                        case 'update': echo 'info'; break;
                                                                        case 'delete': echo 'danger'; break;
                                                                        default: echo 'secondary';
                                                                    }
                                                                ?>">
                                                                    <?php echo ucfirst($activity['action']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($activity['description'] ?? 'N/A'); ?></td>
                                                            <td><?php echo htmlspecialchars($activity['ip_address']); ?></td>
                                                            <td>
                                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($activity['created_at'])); ?>">
                                                                    <?php echo timeAgo($activity['created_at']); ?>
                                                                </span>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr>
                                                            <td colspan="4" class="text-center">No activity logs found</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="text-end">
                                            <a href="activity_log.php" class="btn btn-sm btn-primary">View All Activity</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6">
                    <span class="text-muted">&copy; <?php echo date('Y'); ?> Taransvar WiFi Hotspot. All rights reserved.</span>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="text-muted">Admin Panel v1.0</span>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Upload Photo Modal -->
    <div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Profile Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="profile.php" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="photo" class="form-label">Select Photo</label>
                            <input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg, image/png, image/gif" required>
                            <div class="form-text">Allowed file types: JPG, PNG, GIF. Maximum size: 2MB.</div>
                        </div>
                        <div class="text-center mt-3">
                            <div id="imagePreview" class="d-none">
                                <img src="#" alt="Preview" class="img-thumbnail mb-2" style="max-height: 200px;">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="upload_photo" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle password visibility
            $('.toggle-password').click(function() {
                const targetId = $(this).data('target');
                const passwordField = $('#' + targetId);
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
            
            // Image preview
            $('#photo').change(function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#imagePreview').removeClass('d-none');
                        $('#imagePreview img').attr('src', e.target.result);
                    }
                    reader.readAsDataURL(file);
                } else {
                    $('#imagePreview').addClass('d-none');
                }
            });
        });
    </script>
</body>
</html>