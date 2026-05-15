<?php
session_start();
require_once '../db_connect.php';

// Check if admin is logged in
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


if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notificationId = $_POST['notification_id'];
    
    try {
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ? AND (admin_id IS NULL OR admin_id = ?)");
        $stmt->execute([$notificationId, $adminId]);
        
        $response = ['success' => true];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Mark all notifications as read
if (isset($_POST['mark_all_read'])) {
    try {
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE (admin_id IS NULL OR admin_id = ?) AND is_read = 0");
        $stmt->execute([$adminId]);
        
        $response = ['success' => true];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}


if (isset($_POST['delete_notification']) && isset($_POST['notification_id'])) {
    $notificationId = $_POST['notification_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM admin_notifications WHERE id = ? AND (admin_id IS NULL OR admin_id = ?)");
        $stmt->execute([$notificationId, $adminId]);
        
        $response = ['success' => true];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}


if (isset($_POST['approve_admin']) && isset($_POST['admin_id'])) {
    $targetAdminId = $_POST['admin_id'];
    
    try {
        // Check if current admin is a super_admin
        if ($admin['role'] !== 'super_admin') {
            throw new Exception('Only super admins can approve admin registrations');
        }
        
        // Update admin status
        $stmt = $conn->prepare("UPDATE admins SET status = 'active' WHERE id = ?");
        $stmt->execute([$targetAdminId]);
        
        // Log the activity
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'approve', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Approved admin registration: Admin ID $targetAdminId",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        // Mark the notification as read
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE related_id = ? AND type = 'admin_registration'");
        $stmt->execute([$targetAdminId]);
        
        $response = ['success' => true, 'message' => 'Admin approved successfully'];
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}


if (isset($_POST['reject_admin']) && isset($_POST['admin_id'])) {
    $targetAdminId = $_POST['admin_id'];
    
    try {
        // Check if current admin is a super_admin
        if ($admin['role'] !== 'super_admin') {
            throw new Exception('Only super admins can reject admin registrations');
        }
        
        // Update admin status
        $stmt = $conn->prepare("UPDATE admins SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$targetAdminId]);
        
        // Log the activity
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'reject', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Rejected admin registration: Admin ID $targetAdminId",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        // Mark the notification as read
        $stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE related_id = ? AND type = 'admin_registration'");
        $stmt->execute([$targetAdminId]);
        
        $response = ['success' => true, 'message' => 'Admin rejected successfully'];
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get notifications
$stmt = $conn->prepare("SELECT n.*, a.name as admin_name, a.email as admin_email 
                       FROM admin_notifications n 
                       LEFT JOIN admins a ON a.id = n.admin_id AND n.type = 'admin_registration'
                       WHERE (n.admin_id IS NULL OR n.admin_id = ?) 
                       ORDER BY n.created_at DESC");
$stmt->execute([$adminId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications
$unreadCount = 0;
foreach ($notifications as $notification) {
    if ($notification['is_read'] == 0) {
        $unreadCount++;
    }
}


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
    <title>Notifications - Taransvar WiFi Hotspot</title>
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">

 
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_dashboard.css">
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
                                <a class="dropdown-item active" href="notifications.php">
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
                                <i class="fas fa-user-circle me-1"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($admin['name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="profile.php">
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
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Notifications</h1>
                    <div>
                        <button id="markAllRead" class="btn btn-sm btn-primary shadow-sm me-2">
                            <i class="fas fa-check-double fa-sm text-white-50"></i> Mark All as Read
                        </button>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">All Notifications</h6>
                        <span class="badge bg-primary"><?php echo $unreadCount; ?> Unread</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($notifications) > 0): ?>
                            <div class="list-group notification-list">
                                <?php foreach ($notifications as $notification): ?>
                                    <div class="list-group-item list-group-item-action notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $notification['id']; ?>">
                                        <div class="d-flex w-100 justify-content-between align-items-center">
                                            <div class="notification-content">
                                                <div class="d-flex align-items-center">
                                                    <div class="notification-icon bg-<?php echo $notification['type']; ?> me-3">
                                                        <?php if ($notification['type'] === 'admin_registration'): ?>
                                                            <i class="fas fa-user-shield"></i>
                                                        <?php elseif ($notification['type'] === 'transaction'): ?>
                                                            <i class="fas fa-money-bill-wave"></i>
                                                        <?php elseif ($notification['type'] === 'session'): ?>
                                                            <i class="fas fa-wifi"></i>
                                                        <?php elseif ($notification['type'] === 'system'): ?>
                                                            <i class="fas fa-cog"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-bell"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                        <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                        <small class="text-muted"><?php echo timeAgo($notification['created_at']); ?></small>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($notification['type'] === 'admin_registration' && !$notification['is_read'] && $admin['role'] === 'super_admin'): ?>
                                                    <div class="mt-3 admin-approval-actions">
                                                        <p><strong>Admin Details:</strong> <?php echo htmlspecialchars($notification['admin_name']); ?> (<?php echo htmlspecialchars($notification['admin_email']); ?>)</p>
                                                        <div class="btn-group">
                                                            <button type="button" class="btn btn-success btn-sm approve-admin" data-admin-id="<?php echo $notification['related_id']; ?>">
                                                                <i class="fas fa-check"></i> Approve
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-sm reject-admin" data-admin-id="<?php echo $notification['related_id']; ?>">
                                                                <i class="fas fa-times"></i> Reject
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notification-actions">
                                                <?php if (!$notification['is_read']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary mark-read" data-id="<?php echo $notification['id']; ?>">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-notification" data-id="<?php echo $notification['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-bell fa-4x text-muted mb-3"></i>
                                <p class="lead">No notifications found</p>
                            </div>
                        <?php endif; ?>
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
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {


            $('.mark-read').on('click', function() {
                const notificationId = $(this).data('id');
                const notificationItem = $(this).closest('.notification-item');
                
                $.ajax({
                    url: 'notifications.php',
                    type: 'POST',
                    data: {
                        mark_read: true,
                        notification_id: notificationId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notificationItem.removeClass('unread');
                            $(this).remove();
                            
                            // Update unread count
                            const unreadCount = parseInt($('.badge').text()) - 1;
                            $('.badge').text(unreadCount + ' Unread');
                        }
                    }.bind(this)
                });
            });
            
            // Mark all notifications as read
            $('#markAllRead').on('click', function() {
                $.ajax({
                    url: 'notifications.php',
                    type: 'POST',
                    data: {
                        mark_all_read: true
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('.notification-item').removeClass('unread');
                            $('.mark-read').remove();
                            $('.badge').text('0 Unread');
                        }
                    }
                });
            });
            

            $('.delete-notification').on('click', function() {
                const notificationId = $(this).data('id');
                const notificationItem = $(this).closest('.notification-item');
                
                if (confirm('Are you sure you want to delete this notification?')) {
                    $.ajax({
                        url: 'notifications.php',
                        type: 'POST',
                        data: {
                            delete_notification: true,
                            notification_id: notificationId
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                notificationItem.fadeOut(300, function() {
                                    $(this).remove();
                                    
                                    // If no notifications left, show empty message
                                    if ($('.notification-item').length === 0) {
                                        $('.notification-list').html(`
                                            <div class="text-center py-5">
                                                <i class="fas fa-bell fa-4x text-muted mb-3"></i>
                                                <p class="lead">No notifications found</p>
                                            </div>
                                        `);
                                    }
                                });
                            }
                        }
                    });
                }
            });
            
            // Approve admin registration
            $('.approve-admin').on('click', function() {
                const adminId = $(this).data('admin-id');
                const notificationItem = $(this).closest('.notification-item');
                
                $.ajax({
                    url: 'notifications.php',
                    type: 'POST',
                    data: {
                        approve_admin: true,
                        admin_id: adminId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notificationItem.removeClass('unread');
                            notificationItem.find('.admin-approval-actions').html('<div class="alert alert-success mt-2">Admin approved successfully</div>');
                            
                            // Update unread count
                            const unreadCount = parseInt($('.badge').text()) - 1;
                            $('.badge').text(unreadCount + ' Unread');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    }
                });
            });
            
            // Reject admin registration
            $('.reject-admin').on('click', function() {
                const adminId = $(this).data('admin-id');
                const notificationItem = $(this).closest('.notification-item');
                
                $.ajax({
                    url: 'notifications.php',
                    type: 'POST',
                    data: {
                        reject_admin: true,
                        admin_id: adminId
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            notificationItem.removeClass('unread');
                            notificationItem.find('.admin-approval-actions').html('<div class="alert alert-danger mt-2">Admin rejected</div>');
                            
                            // Update unread count
                            const unreadCount = parseInt($('.badge').text()) - 1;
                            $('.badge').text(unreadCount + ' Unread');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>