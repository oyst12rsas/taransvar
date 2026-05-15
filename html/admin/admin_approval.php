<?php
session_start();
require_once '../db_connect.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}


$adminId = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || $admin['status'] !== 'active') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Check if admin is super_admin
if ($admin['role'] !== 'super_admin') {
    header('Location: admin_dashboard.php?error=' . urlencode('You do not have permission to access this page'));
    exit;
}


if (isset($_POST['update_admin']) && isset($_POST['admin_id'])) {
    $targetAdminId = $_POST['admin_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $conn->beginTransaction();
        
        // Get admin details
        $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$targetAdminId]);
        $targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$targetAdmin) {
            throw new Exception('Admin not found');
        }
        
        // Update admin status
        $stmt = $conn->prepare("UPDATE admins SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $targetAdminId]);
        
        // Log the activity
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'update', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Updated admin status: Admin ID $targetAdminId ({$targetAdmin['name']}) set to $newStatus",
            $_SERVER['REMOTE_ADDR']
        ]);
        

        $title = "Account Status Updated";
        $message = "Your account status has been updated to " . ucfirst($newStatus) . " by " . $admin['name'];
        $type = $newStatus === 'active' ? 'success' : ($newStatus === 'inactive' ? 'warning' : 'error');
        
        $stmt = $conn->prepare("INSERT INTO admin_notifications (admin_id, title, message, type, created_at) 
                               VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$targetAdminId, $title, $message, $type]);
        
        $conn->commit();
        $response = ['success' => true, 'message' => 'Admin status updated successfully'];
    } catch (Exception $e) {
        $conn->rollBack();
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get pending admins
$stmt = $conn->prepare("SELECT * FROM admins WHERE status = 'inactive' ORDER BY created_at DESC");
$stmt->execute();
$pendingAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all admins
$stmt = $conn->prepare("SELECT * FROM admins ORDER BY created_at DESC");
$stmt->execute();
$allAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Admin Approval - Taransvar WiFi Hotspot</title>
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
                                <a class="dropdown-item active" href="admin_approval.php">
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
                    <h1 class="h3 mb-0 text-gray-800">Admin Approval</h1>
                </div>
                
                <!-- Pending Admins -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Pending Admin Approvals</h6>
                        <span class="badge bg-warning"><?php echo count($pendingAdmins); ?> Pending</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($pendingAdmins) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Role</th>
                                        <th>Registered</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingAdmins as $pendingAdmin): ?>
                                    <tr>
                                        <td><?php echo $pendingAdmin['id']; ?></td>
                                        <td><?php echo htmlspecialchars($pendingAdmin['name']); ?></td>
                                        <td><?php echo htmlspecialchars($pendingAdmin['email']); ?></td>
                                        <td><?php echo htmlspecialchars($pendingAdmin['phone']); ?></td>
                                        <td><?php echo ucfirst($pendingAdmin['role']); ?></td>
                                        <td>
                                            <span title="<?php echo date('M d, Y H:i:s', strtotime($pendingAdmin['created_at'])); ?>">
                                                <?php echo timeAgo($pendingAdmin['created_at']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-success approve-admin" data-id="<?php echo $pendingAdmin['id']; ?>" data-name="<?php echo htmlspecialchars($pendingAdmin['name']); ?>">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger reject-admin" data-id="<?php echo $pendingAdmin['id']; ?>" data-name="<?php echo htmlspecialchars($pendingAdmin['name']); ?>">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> No pending admin approvals at this time.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- All Admins -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">All Administrators</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allAdmins as $adminUser): ?>
                                    <tr>
                                        <td><?php echo $adminUser['id']; ?></td>
                                        <td><?php echo htmlspecialchars($adminUser['name']); ?></td>
                                        <td><?php echo htmlspecialchars($adminUser['email']); ?></td>
                                        <td><?php echo htmlspecialchars($adminUser['phone']); ?></td>
                                        <td><?php echo ucfirst($adminUser['role']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                switch ($adminUser['status']) {
                                                    case 'active': echo 'success'; break;
                                                    case 'inactive': echo 'warning'; break;
                                                    case 'suspended': echo 'danger'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo ucfirst($adminUser['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($adminUser['last_login']): ?>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($adminUser['last_login'])); ?>">
                                                    <?php echo timeAgo($adminUser['last_login']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($adminUser['id'] != $adminId): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    Actions
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php if ($adminUser['status'] !== 'active'): ?>
                                                    <li>
                                                        <button class="dropdown-item text-success approve-admin" data-id="<?php echo $adminUser['id']; ?>" data-name="<?php echo htmlspecialchars($adminUser['name']); ?>">
                                                            <i class="fas fa-check me-2"></i> Activate
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($adminUser['status'] !== 'inactive'): ?>
                                                    <li>
                                                        <button class="dropdown-item text-warning suspend-admin" data-id="<?php echo $adminUser['id']; ?>" data-name="<?php echo htmlspecialchars($adminUser['name']); ?>">
                                                            <i class="fas fa-pause me-2"></i> Deactivate
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($adminUser['status'] !== 'suspended'): ?>
                                                    <li>
                                                        <button class="dropdown-item text-danger reject-admin" data-id="<?php echo $adminUser['id']; ?>" data-name="<?php echo htmlspecialchars($adminUser['name']); ?>">
                                                            <i class="fas fa-ban me-2"></i> Suspend
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
    
    <!-- Approve Admin Modal -->
    <div class="modal fade" id="approveAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Administrator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve <strong id="approveAdminName"></strong>?</p>
                    <p>This will grant them access to the admin panel with their assigned role.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmApprove">Approve</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reject Admin Modal -->
    <div class="modal fade" id="rejectAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Suspend Administrator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to suspend <strong id="rejectAdminName"></strong>?</p>
                    <p class="text-danger">This will prevent them from accessing the admin panel.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmReject">Suspend</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Deactivate Admin Modal -->
    <div class="modal fade" id="deactivateAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deactivate Administrator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to deactivate <strong id="deactivateAdminName"></strong>?</p>
                    <p>This will temporarily prevent them from accessing the admin panel until they are reactivated.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmDeactivate">Deactivate</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Approve admin
            $('.approve-admin').on('click', function() {
                const adminId = $(this).data('id');
                const adminName = $(this).data('name');
                
                $('#approveAdminName').text(adminName);
                $('#confirmApprove').data('id', adminId);
                $('#approveAdminModal').modal('show');
            });
            
            // Confirm approve
            $('#confirmApprove').on('click', function() {
                const adminId = $(this).data('id');
                
                $.ajax({
                    url: 'admin_approval.php',
                    type: 'POST',
                    data: {
                        update_admin: true,
                        admin_id: adminId,
                        new_status: 'active'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#approveAdminModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#approveAdminModal').modal('hide');
                        alert('An error occurred while updating the admin status');
                    }
                });
            });
            
            // Reject admin
            $('.reject-admin').on('click', function() {
                const adminId = $(this).data('id');
                const adminName = $(this).data('name');
                
                $('#rejectAdminName').text(adminName);
                $('#confirmReject').data('id', adminId);
                $('#rejectAdminModal').modal('show');
            });
            
            // Confirm reject
            $('#confirmReject').on('click', function() {
                const adminId = $(this).data('id');
                
                $.ajax({
                    url: 'admin_approval.php',
                    type: 'POST',
                    data: {
                        update_admin: true,
                        admin_id: adminId,
                        new_status: 'suspended'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#rejectAdminModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#rejectAdminModal').modal('hide');
                        alert('An error occurred while updating the admin status');
                    }
                });
            });
            
            // Suspend admin
            $('.suspend-admin').on('click', function() {
                const adminId = $(this).data('id');
                const adminName = $(this).data('name');
                
                $('#deactivateAdminName').text(adminName);
                $('#confirmDeactivate').data('id', adminId);
                $('#deactivateAdminModal').modal('show');
            });
            
            // Confirm suspend
            $('#confirmDeactivate').on('click', function() {
                const adminId = $(this).data('id');
                
                $.ajax({
                    url: 'admin_approval.php',
                    type: 'POST',
                    data: {
                        update_admin: true,
                        admin_id: adminId,
                        new_status: 'inactive'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#deactivateAdminModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#deactivateAdminModal').modal('hide');
                        alert('An error occurred while updating the admin status');
                    }
                });
            });
        });
    </script>
</body>
</html>