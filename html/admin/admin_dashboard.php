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

if (!$admin) {

    session_destroy();
    header('Location: login.php?error=' . urlencode('Admin not found'));
    exit;
}

// Check if admin is active
if ($admin['status'] !== 'active') {
    session_destroy();
    header('Location: login.php?error=' . urlencode('Your account is ' . $admin['status'] . '. Please contact a super admin.'));
    exit;
}


$stmt = $conn->prepare("UPDATE admin_sessions SET last_activity = NOW() WHERE admin_id = ? AND session_id = ?");
$stmt->execute([$adminId, $_SESSION['admin_session_id'] ?? '']);


$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Active users (with active sessions)
$stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as total FROM sessions WHERE expires_at > NOW()");
$stmt->execute();
$activeUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];


$stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(amount) as total_amount FROM transactions");
$stmt->execute();
$transactionStats = $stmt->fetch(PDO::FETCH_ASSOC);
$totalTransactions = $transactionStats['total'] ?? 0;
$totalRevenue = $transactionStats['total_amount'] ?? 0;

// Wallet statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(balance) as total_balance,
                       SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as positive_balance,
                       SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END) as negative_balance
                       FROM wallets");
$stmt->execute();
$walletStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent transactions
$stmt = $conn->prepare("SELECT t.*, u.name as user_name, p.name as plan_name 
                       FROM transactions t 
                       LEFT JOIN users u ON t.user_id = u.id 
                       JOIN plans p ON t.plan_id = p.id 
                       ORDER BY t.created_at DESC 
                       LIMIT 10");
$stmt->execute();
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $conn->prepare("SELECT s.*, u.name as user_name, p.name as plan_name 
                       FROM sessions s 
                       LEFT JOIN users u ON s.user_id = u.id 
                       JOIN plans p ON s.plan_id = p.id 
                       WHERE s.expires_at > NOW() 
                       ORDER BY s.created_at DESC 
                       LIMIT 10");
$stmt->execute();
$activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $conn->prepare("SELECT * FROM admin_notifications 
                       WHERE (admin_id IS NULL OR admin_id = ?) 
                       AND is_read = 0 
                       ORDER BY created_at DESC 
                       LIMIT 5");
$stmt->execute([$adminId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$notificationCount = count($notifications);

// Get recent admin activity
$stmt = $conn->prepare("SELECT al.*, a.name as admin_name 
                       FROM admin_activity_logs al 
                       JOIN admins a ON al.admin_id = a.id 
                       ORDER BY al.created_at DESC 
                       LIMIT 10");
$stmt->execute();
$recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);


$pendingAdminCount = 0;
if ($admin['role'] === 'super_admin') {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM admins WHERE status = 'inactive'");
    $stmt->execute();
    $pendingAdminCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
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


$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                       SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count
                       FROM users");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper function to format time remaining
function timeRemaining($datetime) {
    $now = new DateTime();
    $expiry = new DateTime($datetime);
    
    if ($expiry < $now) {
        return 'Expired';
    }
    
    $diff = $now->diff($expiry);
    
    if ($diff->days > 0) {
        return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' left';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' left';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' left';
    } else {
        return 'Less than a minute left';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Taransvar WiFi Hotspot</title>

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
                                <a class="dropdown-item" href="admin_approval.php">
                                    <i class="fas fa-user-shield"></i> Admin Approval
                                    <?php if ($pendingAdminCount > 0): ?>
                                    <span class="badge bg-danger ms-2"><?php echo $pendingAdminCount; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                </ul>
                
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="badge rounded-pill bg-danger"><?php echo $notificationCount; ?></span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <li>
                                <h6 class="dropdown-header">Notifications</h6>
                            </li>
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item notification-item" href="notifications.php?id=<?php echo $notification['id']; ?>">
                                        <div class="notification-icon bg-<?php echo $notification['type']; ?>">
                                            <i class="fas fa-info-circle"></i>
                                        </div>
                                        <div class="notification-content">
                                            <p class="mb-0"><?php echo htmlspecialchars($notification['title']); ?></p>
                                            <small class="text-muted"><?php echo timeAgo($notification['created_at']); ?></small>
                                        </div>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-center" href="notifications.php">
                                        View All Notifications
                                    </a>
                                </li>
                            <?php else: ?>
                                <li>
                                    <span class="dropdown-item">No new notifications</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    
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
                        <a class="nav-link active" href="admin_dashboard.php">
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
                        <a class="nav-link" href="wallet_management.php">
                            <i class="fas fa-wallet"></i> Wallet Management
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
                    <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
                    <div class="d-none d-sm-inline-block">
                        <span class="mr-2">
                            <i class="fas fa-calendar fa-sm text-gray-600"></i> 
                            <?php echo date('F d, Y'); ?>
                        </span>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="row stats-cards">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Users
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($totalUsers); ?></div>
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
                        <div class="card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Total Transactions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($totalTransactions); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
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
                                            Total Revenue
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($totalRevenue, 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Wallet Stats Cards -->
                <div class="row stats-cards mb-4">
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Wallets
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($walletStats['total_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-wallet fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Positive Balances
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($walletStats['positive_balance'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-arrow-up fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-danger shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                            Negative Balances
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format(abs($walletStats['negative_balance']), 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-arrow-down fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Content Row -->
                <div class="row">
                    <!-- Recent Transactions -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Recent Transactions</h6>
                                <a href="transactions.php" class="btn btn-sm btn-primary">
                                    View All
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>User</th>
                                                <th>Plan</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($recentTransactions) > 0): ?>
                                                <?php foreach ($recentTransactions as $transaction): ?>
                                                <tr>
                                                    <td><?php echo $transaction['id']; ?></td>
                                                    <td>
                                                        <?php if ($transaction['user_name']): ?>
                                                            <?php echo htmlspecialchars($transaction['user_name']); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted"><?php echo htmlspecialchars($transaction['phone']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($transaction['plan_name']); ?></td>
                                                    <td>KSh <?php echo number_format($transaction['amount'], 2); ?></td>
                                                    <td>
                                                        <?php if ($transaction['status'] == 'completed'): ?>
                                                            <span class="badge bg-success">Completed</span>
                                                        <?php elseif ($transaction['status'] == 'pending'): ?>
                                                            <span class="badge bg-warning text-dark">Pending</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Failed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="view_transaction.php?id=<?php echo $transaction['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No transactions found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Active Sessions -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Active Sessions</h6>
                                <a href="sessions.php" class="btn btn-sm btn-primary">
                                    View All
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>User</th>
                                                <th>Plan</th>
                                                <th>Expires</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($activeSessions) > 0): ?>
                                                <?php foreach ($activeSessions as $session): ?>
                                                <tr>
                                                    <td><?php echo $session['id']; ?></td>
                                                    <td>
                                                        <?php if ($session['user_name']): ?>
                                                            <?php echo htmlspecialchars($session['user_name']); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted"><?php echo htmlspecialchars($session['phone']); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($session['plan_name']); ?></td>
                                                    <td>
                                                        <span class="badge bg-success">
                                                            <?php echo timeRemaining($session['expires_at']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="view_session.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center">No active sessions found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Content Row -->
                <div class="row">
                    <!-- Recent Activity -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">Recent Admin Activity</h6>
                                <a href="activity_log.php" class="btn btn-sm btn-primary">
                                    View All
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="activity-feed">
                                    <?php if (count($recentActivity) > 0): ?>
                                        <?php foreach ($recentActivity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-content">
                                                <div class="activity-header">
                                                    <span class="activity-user"><?php echo htmlspecialchars($activity['admin_name']); ?></span>
                                                    <span class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></span>
                                                </div>
                                                <div class="activity-description">
                                                    <?php echo htmlspecialchars($activity['description']); ?>
                                                </div>
                                                <div class="activity-time">
                                                    <small class="text-muted"><?php echo timeAgo($activity['created_at']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-center">No recent activity found</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="col-lg-6 mb-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Quick Actions</h6>
                            </div>
                            <div class="card-body">
                                <div class="row quick-actions">
                                    <div class="col-md-6 mb-3">
                                        <a href="add_user.php" class="btn btn-primary w-100">
                                            <i class="fas fa-user-plus"></i> Add New User
                                        </a>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <a href="add_plan.php" class="btn btn-success w-100">
                                            <i class="fas fa-plus-circle"></i> Create New Plan
                                        </a>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <a href="wallet_management.php" class="btn btn-info w-100 text-white">
                                            <i class="fas fa-wallet"></i> Manage Wallets
                                        </a>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <a href="sessions.php" class="btn btn-warning w-100 text-dark">
                                            <i class="fas fa-wifi"></i> Manage Sessions
                                        </a>
                                    </div>
                                    <?php if ($admin['role'] === 'super_admin' && $pendingAdminCount > 0): ?>
                                    <div class="col-md-6 mb-3">
                                        <a href="admin_approval.php" class="btn btn-danger w-100">
                                            <i class="fas fa-user-shield"></i> Approve Admins
                                            <span class="badge bg-white text-danger ms-1"><?php echo $pendingAdminCount; ?></span>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-6 mb-3">
                                        <a href="../index.php" target="_blank" class="btn btn-secondary w-100">
                                            <i class="fas fa-external-link-alt"></i> Main Website
                                        </a>
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
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
	
    <script>
        // Toggle sidebar on mobile
        $(document).ready(function() {
            // Mark notifications as read when clicked
            $('.notification-item').on('click', function() {
                const notificationId = $(this).attr('href').split('=')[1];
                
                $.ajax({
                    url: 'mark_notification_read.php',
                    type: 'POST',
                    data: { id: notificationId },
                    dataType: 'json'
                });
            });
            
            // Add responsive behavior for tables
            $('.table-responsive').each(function() {
                $(this).css('max-height', $(window).height() * 0.5);
            });
            
            // Highlight active sidebar item
            const currentLocation = window.location.pathname;
            $('.sidebar .nav-link').each(function() {
                const link = $(this).attr('href');
                if (currentLocation.indexOf(link) !== -1) {
                    $(this).addClass('active');
                } else {
                    $(this).removeClass('active');
                }
            });
        });
    </script>
</body>
</html>