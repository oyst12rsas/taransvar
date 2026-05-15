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


$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Set up filtering
$filterWalletId = isset($_GET['wallet_id']) ? (int)$_GET['wallet_id'] : 0;
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$query = "FROM wallet_transactions wt 
          JOIN wallets w ON wt.wallet_id = w.id 
          LEFT JOIN users u ON w.user_id = u.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT wt.*, w.user_id, u.name as user_name, u.email as user_email, u.phone as user_phone " . $query;
$params = [];

if ($filterWalletId > 0) {
    $query .= " AND wt.wallet_id = ?";
    $params[] = $filterWalletId;
}

if ($filterUserId > 0) {
    $query .= " AND w.user_id = ?";
    $params[] = $filterUserId;
}

if (!empty($filterType)) {
    $query .= " AND wt.type = ?";
    $params[] = $filterType;
}

if (!empty($filterStatus)) {
    $query .= " AND wt.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterDateFrom)) {
    $query .= " AND DATE(wt.created_at) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $query .= " AND DATE(wt.created_at) <= ?";
    $params[] = $filterDateTo;
}

// Get total count
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Get data with pagination
$dataQuery .= " ORDER BY wt.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as total_deposits,
                       SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as total_withdrawals,
                       SUM(CASE WHEN type = 'purchase' THEN amount ELSE 0 END) as total_purchases,
                       SUM(CASE WHEN type = 'refund' THEN amount ELSE 0 END) as total_refunds,
                       COUNT(CASE WHEN type = 'deposit' THEN 1 END) as deposit_count,
                       COUNT(CASE WHEN type = 'withdrawal' THEN 1 END) as withdrawal_count,
                       COUNT(CASE WHEN type = 'purchase' THEN 1 END) as purchase_count,
                       COUNT(CASE WHEN type = 'refund' THEN 1 END) as refund_count
                       FROM wallet_transactions");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$walletInfo = null;
if ($filterWalletId > 0) {
    $stmt = $conn->prepare("SELECT w.*, u.name as user_name, u.email as user_email, u.phone as user_phone 
                           FROM wallets w 
                           LEFT JOIN users u ON w.user_id = u.id 
                           WHERE w.id = ?");
    $stmt->execute([$filterWalletId]);
    $walletInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get user info if filtering by user
$userInfo = null;
if ($filterUserId > 0) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$filterUserId]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <title>Wallet Transactions - Taransvar WiFi Hotspot</title>
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
                                <a class="dropdown-item active" href="wallet_management.php">
                                    <i class="fas fa-wallet"></i> Wallet Management
                                </a>
                            </li>
							<?php if ($admin['role'] === 'super_admin'): ?>
                            <li>
                                <a class="dropdown-item" href="admin_approval.php">
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
                    <h1 class="h3 mb-0 text-gray-800">
                        Wallet Transactions
                        <?php if ($walletInfo): ?>
                            for <?php echo htmlspecialchars($walletInfo['user_name'] ?? 'System Wallet'); ?>
                        <?php elseif ($userInfo): ?>
                            for <?php echo htmlspecialchars($userInfo['name']); ?>
                        <?php endif; ?>
                    </h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm me-2" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                        <a href="wallet_management.php" class="btn btn-sm btn-secondary shadow-sm">
                            <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back to Wallets
                        </a>
                    </div>
                </div>
                
                <?php if ($walletInfo): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Wallet Information</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Wallet ID:</strong> <?php echo $walletInfo['id']; ?></p>
                                <p><strong>User:</strong> 
                                    <?php if ($walletInfo['user_id']): ?>
                                        <a href="view_user.php?id=<?php echo $walletInfo['user_id']; ?>">
                                            <?php echo htmlspecialchars($walletInfo['user_name']); ?>
                                        </a>
                                        (<?php echo htmlspecialchars($walletInfo['user_email']); ?>)
                                    <?php else: ?>
                                        System Wallet
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Current Balance:</strong> 
                                    <span class="fw-bold text-<?php echo $walletInfo['balance'] > 0 ? 'success' : ($walletInfo['balance'] < 0 ? 'danger' : 'muted'); ?>">
                                        KSh <?php echo number_format($walletInfo['balance'], 2); ?>
                                    </span>
                                </p>
                                <p><strong>Created:</strong> <?php echo date('M d, Y H:i:s', strtotime($walletInfo['created_at'])); ?></p>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                                <i class="fas fa-plus"></i> Add Funds
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#withdrawFundsModal">
                                <i class="fas fa-minus"></i> Withdraw Funds
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="row stats-cards mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Total Deposits
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_deposits'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['deposit_count']); ?> transactions</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-arrow-up fa-2x text-gray-300"></i>
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
                                            Total Withdrawals
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_withdrawals'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['withdrawal_count']); ?> transactions</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-arrow-down fa-2x text-gray-300"></i>
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
                                            Total Purchases
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_purchases'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['purchase_count']); ?> transactions</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Refunds
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_refunds'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['refund_count']); ?> transactions</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-undo fa-2x text-gray-300"></i>
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
                            <form method="get" action="wallet_transactions.php">
                                <div class="row mb-3">
                                    <?php if (!$walletInfo && !$userInfo): ?>
                                    <div class="col-md-6">
                                        <label for="user_id" class="form-label">User</label>
                                        <select class="form-select" id="user_id" name="user_id">
                                            <option value="">All Users</option>
                                            <?php
                                            $stmt = $conn->prepare("SELECT id, name, email FROM users ORDER BY name");
                                            $stmt->execute();
                                            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            foreach ($users as $user) {
                                                $selected = $filterUserId == $user['id'] ? 'selected' : '';
                                                echo '<option value="' . $user['id'] . '" ' . $selected . '>' . htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['email']) . ')</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="col-md-6">
                                        <label for="type" class="form-label">Transaction Type</label>
                                        <select class="form-select" id="type" name="type">
                                            <option value="">All Types</option>
                                            <option value="deposit" <?php echo $filterType == 'deposit' ? 'selected' : ''; ?>>Deposit</option>
                                            <option value="withdrawal" <?php echo $filterType == 'withdrawal' ? 'selected' : ''; ?>>Withdrawal</option>
                                            <option value="purchase" <?php echo $filterType == 'purchase' ? 'selected' : ''; ?>>Purchase</option>
                                            <option value="refund" <?php echo $filterType == 'refund' ? 'selected' : ''; ?>>Refund</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="completed" <?php echo $filterStatus == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="failed" <?php echo $filterStatus == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="date_from" class="form-label">Date From</label>
                                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $filterDateFrom; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="date_to" class="form-label">Date To</label>
                                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $filterDateTo; ?>">
                                    </div>
                                </div>
                                
                                <?php if ($walletInfo): ?>
                                <input type="hidden" name="wallet_id" value="<?php echo $walletInfo['id']; ?>">
                                <?php endif; ?>
                                
                                <?php if ($userInfo): ?>
                                <input type="hidden" name="user_id" value="<?php echo $userInfo['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="wallet_transactions.php<?php echo $walletInfo ? '?wallet_id=' . $walletInfo['id'] : ($userInfo ? '?user_id=' . $userInfo['id'] : ''); ?>" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Transaction History</h6>
                        <span class="badge bg-primary"><?php echo number_format($totalRows); ?> Records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($transactions) > 0): ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo $transaction['id']; ?></td>
                                            <td>
                                                <?php if ($transaction['user_id']): ?>
                                                    <a href="view_user.php?id=<?php echo $transaction['user_id']; ?>">
                                                        <?php echo htmlspecialchars($transaction['user_name']); ?>
                                                    </a>
                                                    <div class="small text-muted">
                                                        <?php echo htmlspecialchars($transaction['user_email']); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch ($transaction['type']) {
                                                        case 'deposit': echo 'success'; break;
                                                        case 'withdrawal': echo 'warning'; break;
                                                        case 'purchase': echo 'info'; break;
                                                        case 'refund': echo 'primary'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($transaction['type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $transaction['type'] === 'deposit' || $transaction['type'] === 'refund' ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $transaction['type'] === 'deposit' || $transaction['type'] === 'refund' ? '+' : '-'; ?>
                                                    KSh <?php echo number_format($transaction['amount'], 2); ?>
                                                </span>
                                            </td>
                                            <td><?php echo ucfirst($transaction['payment_method'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if (!empty($transaction['mpesa_code'])): ?>
                                                    <?php echo htmlspecialchars($transaction['mpesa_code']); ?>
                                                <?php elseif (!empty($transaction['reference_id'])): ?>
                                                    <?php echo htmlspecialchars($transaction['reference_id']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch ($transaction['status']) {
                                                        case 'completed': echo 'success'; break;
                                                        case 'pending': echo 'warning'; break;
                                                        case 'failed': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($transaction['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($transaction['created_at'])); ?>">
                                                    <?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['description'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No transactions found</td>
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
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&wallet_id=<?php echo $filterWalletId; ?>&user_id=<?php echo $filterUserId; ?>&type=<?php echo $filterType; ?>&status=<?php echo $filterStatus; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&wallet_id=<?php echo $filterWalletId; ?>&user_id=<?php echo $filterUserId; ?>&type=<?php echo $filterType; ?>&status=<?php echo $filterStatus; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&wallet_id=<?php echo $filterWalletId; ?>&user_id=<?php echo $filterUserId; ?>&type=<?php echo $filterType; ?>&status=<?php echo $filterStatus; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Next">
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
    
    <!-- Add Funds Modal -->
    <div class="modal fade" id="addFundsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Funds to Wallet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="process_wallet.php" method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount (KSh)</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="mpesa">M-Pesa</option>
                                <option value="manual">Manual Payment</option>
                                <option value="system">System Credit</option>
                            </select>
                        </div>
                        <div class="mb-3" id="mpesaCodeField">
                            <label for="mpesa_code" class="form-label">M-Pesa Transaction Code</label>
                            <input type="text" class="form-control" id="mpesa_code" name="mpesa_code">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="2"></textarea>
                        </div>
                        <input type="hidden" name="wallet_id" value="<?php echo $walletInfo['id']; ?>">
                        <input type="hidden" name="action" value="add_funds_to_wallet">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Funds</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Withdraw Funds Modal -->
    <div class="modal fade" id="withdrawFundsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Withdraw Funds from Wallet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="process_wallet.php" method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="withdraw_amount" class="form-label">Amount (KSh)</label>
                            <input type="number" class="form-control" id="withdraw_amount" name="amount" min="1" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="withdraw_description" class="form-label">Description</label>
                            <textarea class="form-control" id="withdraw_description" name="description" rows="2"></textarea>
                        </div>
                        <input type="hidden" name="wallet_id" value="<?php echo $walletInfo['id']; ?>">
                        <input type="hidden" name="action" value="withdraw_funds">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Withdraw Funds</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle M-Pesa code field based on payment method
            $('#payment_method').on('change', function() {
                if ($(this).val() === 'mpesa') {
                    $('#mpesaCodeField').show();
                    $('#mpesa_code').prop('required', true);
                } else {
                    $('#mpesaCodeField').hide();
                    $('#mpesa_code').prop('required', false);
                }
            });
        });
    </script>
</body>
</html>