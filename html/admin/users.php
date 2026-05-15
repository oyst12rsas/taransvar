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


if (isset($_POST['toggle_status']) && isset($_POST['user_id'])) {
    $userId = $_POST['user_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        
        // Log the activity
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'update', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Updated user status: User ID $userId set to $newStatus",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $response = ['success' => true, 'message' => 'User status updated successfully'];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => 'Error updating user status: ' . $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Set up pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterSearch = isset($_GET['search']) ? $_GET['search'] : '';

// Build query
$query = "FROM users WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT * " . $query;
$params = [];

if (!empty($filterStatus)) {
    $query .= " AND status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterSearch)) {
    $query .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $searchTerm = "%$filterSearch%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Get total count
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Get data with pagination
$dataQuery .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                       SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count
                       FROM users");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get wallet information for each user
$userIds = array_column($users, 'id');
$walletData = [];

if (!empty($userIds)) {
    $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
    $stmt = $conn->prepare("SELECT id, user_id, balance FROM wallets WHERE user_id IN ($placeholders)");
    $stmt->execute($userIds);
    $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($wallets as $wallet) {
    $walletData[$wallet['user_id']] = [
        'wallet_id' => $wallet['id'],
        'user_id' => $wallet['user_id'],
        'balance' => $wallet['balance']
    ];
}
}

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
    <title>Users - Taransvar WiFi Hotspot</title>
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
                        <a class="nav-link active" href="users.php">
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
                        <a class="nav-link active" href="users.php">
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
                    <h1 class="h3 mb-0 text-gray-800">Users Management</h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm me-2" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                        <a href="add_user.php" class="btn btn-sm btn-success shadow-sm">
                            <i class="fas fa-user-plus fa-sm text-white-50"></i> Add New User
                        </a>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="row stats-cards mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Users
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-users fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Active Users
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['active_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-check fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Inactive Users
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['inactive_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-clock fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-danger shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                            Suspended Users
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['suspended_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-user-slash fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="collapse mb-4" id="filterCollapse">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Options</h6>
                        </div>
                        <div class="card-body">
                            <form method="get" action="users.php">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="search" class="form-label">Search</label>
                                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Name, Email or Phone">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="active" <?php echo $filterStatus == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $filterStatus == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            <option value="suspended" <?php echo $filterStatus == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="users.php" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">All Users</h6>
                        <span class="badge bg-primary"><?php echo number_format($totalRows); ?> Records</span>
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
                                        <th>Wallet Balance</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($users) > 0): ?>
                                        <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                            <td>
                                                <?php if (isset($walletData[$user['id']])): ?>
                                                    KSh <?php echo number_format($walletData[$user['id']]['balance'], 2); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No wallet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input status-toggle" type="checkbox" 
                                                           data-user-id="<?php echo $user['id']; ?>" 
                                                           <?php echo $user['status'] === 'active' ? 'checked' : ''; ?>>
                                                    <label class="form-check-label">
                                                        <span class="badge bg-<?php 
                                                            switch ($user['status']) {
                                                                case 'active': echo 'success'; break;
                                                                case 'inactive': echo 'warning'; break;
                                                                case 'suspended': echo 'danger'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo ucfirst($user['status']); ?>
                                                        </span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($user['last_login']): ?>
                                                    <span title="<?php echo date('M d, Y H:i:s', strtotime($user['last_login'])); ?>">
                                                        <?php echo timeAgo($user['last_login']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($user['created_at'])); ?>">
                                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="view_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="user_wallet.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-success">
                                                        <i class="fas fa-wallet"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-danger delete-user" data-user-id="<?php echo $user['id']; ?>" data-user-name="<?php echo htmlspecialchars($user['name']); ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No users found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center mt-4">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $filterStatus; ?>&search=<?php echo urlencode($filterSearch); ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
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
    
    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the user <strong id="deleteUserName"></strong>?</p>
                    <p class="text-danger">Warning: This action cannot be undone and will delete all associated data including transactions, sessions, and wallet information.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle user status
            $('.status-toggle').on('change', function() {
                const userId = $(this).data('user-id');
                const isChecked = $(this).prop('checked');
                const newStatus = isChecked ? 'active' : 'inactive';
                const badge = $(this).siblings('label').find('.badge');
                
                $.ajax({
                    url: 'users.php',
                    type: 'POST',
                    data: {
                        toggle_status: true,
                        user_id: userId,
                        new_status: newStatus
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            badge.removeClass('bg-success bg-warning bg-danger')
                                 .addClass(isChecked ? 'bg-success' : 'bg-warning')
                                 .text(isChecked ? 'Active' : 'Inactive');
                            
                            // Show success message
                            alert('User status updated successfully');
                        } else {
                            // Revert the toggle if there was an error
                            $(this).prop('checked', !isChecked);
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        // Revert the toggle if there was an error
                        $(this).prop('checked', !isChecked);
                        alert('An error occurred while updating the user status');
                    }
                });
            });
            
            // Delete user
            $('.delete-user').on('click', function() {
                const userId = $(this).data('user-id');
                const userName = $(this).data('user-name');
                
                $('#deleteUserName').text(userName);
                $('#confirmDelete').data('user-id', userId);
                $('#deleteUserModal').modal('show');
            });
            
            // Confirm delete
            $('#confirmDelete').on('click', function() {
                const userId = $(this).data('user-id');
                
                $.ajax({
                    url: 'delete_user.php',
                    type: 'POST',
                    data: {
                        user_id: userId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#deleteUserModal').modal('hide');
                        
                        if (response.success) {
                            // Remove the row from the table
                            $('tr').filter(function() {
                                return $(this).find('td:first').text() == userId;
                            }).remove();
                            
                            alert('User deleted successfully');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#deleteUserModal').modal('hide');
                        alert('An error occurred while deleting the user');
                    }
                });
            });
        });
    </script>
</body>
</html>