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

// Set up pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Set up filtering
$filterSearch = isset($_GET['search']) ? $_GET['search'] : '';
$filterBalance = isset($_GET['balance']) ? $_GET['balance'] : '';

// Build query
$query = "FROM wallets w LEFT JOIN users u ON w.user_id = u.id WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT w.*, u.name as user_name, u.email as user_email, u.phone as user_phone " . $query;
$params = [];

if (!empty($filterSearch)) {
    $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchTerm = "%$filterSearch%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($filterBalance === 'positive') {
    $query .= " AND w.balance > 0";
} elseif ($filterBalance === 'negative') {
    $query .= " AND w.balance < 0";
} elseif ($filterBalance === 'zero') {
    $query .= " AND w.balance = 0";
}

// Get total count
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Get data with pagination
$dataQuery .= " ORDER BY w.updated_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get wallet statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(balance) as total_balance,
                       SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as positive_balance,
                       SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END) as negative_balance,
                       COUNT(CASE WHEN balance > 0 THEN 1 END) as positive_count,
                       COUNT(CASE WHEN balance < 0 THEN 1 END) as negative_count,
                       COUNT(CASE WHEN balance = 0 THEN 1 END) as zero_count
                       FROM wallets");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent transactions
$stmt = $conn->prepare("SELECT wt.*, w.user_id, u.name as user_name, u.email as user_email 
                       FROM wallet_transactions wt 
                       JOIN wallets w ON wt.wallet_id = w.id 
                       LEFT JOIN users u ON w.user_id = u.id 
                       ORDER BY wt.created_at DESC LIMIT 10");
$stmt->execute();
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Wallet Management - Taransvar WiFi Hotspot</title>
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
                    <h1 class="h3 mb-0 text-gray-800">Wallet Management</h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm me-2" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                        <a href="wallet_transactions.php" class="btn btn-sm btn-info shadow-sm me-2">
                            <i class="fas fa-list fa-sm text-white-50"></i> All Transactions
                        </a>
                        <button type="button" class="btn btn-sm btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                            <i class="fas fa-plus fa-sm text-white-50"></i> Add Funds
                        </button>
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
                                            Total Wallets
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-wallet fa-2x text-gray-300"></i>
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
                                            Total Balance
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_balance'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
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
                                            Positive Balances
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['positive_balance'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['positive_count']); ?> wallets</div>
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
                                            Negative Balances
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['negative_balance'], 2); ?></div>
                                        <div class="text-xs text-muted"><?php echo number_format($stats['negative_count']); ?> wallets</div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-arrow-down fa-2x text-gray-300"></i>
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
                            <form method="get" action="wallet_management.php">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="search" class="form-label">Search User</label>
                                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Name, Email or Phone">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="balance" class="form-label">Balance Type</label>
                                        <select class="form-select" id="balance" name="balance">
                                            <option value="">All Balances</option>
                                            <option value="positive" <?php echo $filterBalance == 'positive' ? 'selected' : ''; ?>>Positive Balance</option>
                                            <option value="negative" <?php echo $filterBalance == 'negative' ? 'selected' : ''; ?>>Negative Balance</option>
                                            <option value="zero" <?php echo $filterBalance == 'zero' ? 'selected' : ''; ?>>Zero Balance</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="wallet_management.php" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                                <h6 class="m-0 font-weight-bold text-primary">All Wallets</h6>
                                <span class="badge bg-primary"><?php echo number_format($totalRows); ?> Records</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>User</th>
                                                <th>Balance</th>
                                                <th>Last Deposit</th>
                                                <th>Last Withdrawal</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($wallets) > 0): ?>
                                                <?php foreach ($wallets as $wallet): ?>
                                                <tr>
                                                    <td><?php echo $wallet['id']; ?></td>
                                                    <td>
                                                        <?php if ($wallet['user_id']): ?>
                                                            <a href="view_user.php?id=<?php echo $wallet['user_id']; ?>">
                                                                <?php echo htmlspecialchars($wallet['user_name']); ?>
                                                            </a>
                                                            <div class="small text-muted">
                                                                <?php echo htmlspecialchars($wallet['user_email']); ?><br>
                                                                <?php echo htmlspecialchars($wallet['user_phone']); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">System Wallet</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold text-<?php echo $wallet['balance'] > 0 ? 'success' : ($wallet['balance'] < 0 ? 'danger' : 'muted'); ?>">
                                                            KSh <?php echo number_format($wallet['balance'], 2); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($wallet['last_deposit_date']): ?>
                                                            <span title="<?php echo date('M d, Y H:i:s', strtotime($wallet['last_deposit_date'])); ?>">
                                                                <?php echo timeAgo($wallet['last_deposit_date']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($wallet['last_withdrawal_date']): ?>
                                                            <span title="<?php echo date('M d, Y H:i:s', strtotime($wallet['last_withdrawal_date'])); ?>">
                                                                <?php echo timeAgo($wallet['last_withdrawal_date']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">Never</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group">
                                                            <a href="wallet_transactions.php?wallet_id=<?php echo $wallet['id']; ?>" class="btn btn-sm btn-primary">
                                                                <i class="fas fa-history"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-success add-funds" data-wallet-id="<?php echo $wallet['id']; ?>" data-user-name="<?php echo htmlspecialchars($wallet['user_name'] ?? 'System Wallet'); ?>">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-warning withdraw-funds" data-wallet-id="<?php echo $wallet['id']; ?>" data-user-name="<?php echo htmlspecialchars($wallet['user_name'] ?? 'System Wallet'); ?>">
                                                                <i class="fas fa-minus"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center">No wallets found</td>
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
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($filterSearch); ?>&balance=<?php echo $filterBalance; ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($filterSearch); ?>&balance=<?php echo $filterBalance; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($filterSearch); ?>&balance=<?php echo $filterBalance; ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">Recent Transactions</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>User</th>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($recentTransactions) > 0): ?>
                                                <?php foreach ($recentTransactions as $transaction): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($transaction['user_id']): ?>
                                                            <a href="view_user.php?id=<?php echo $transaction['user_id']; ?>">
                                                                <?php echo htmlspecialchars($transaction['user_name']); ?>
                                                            </a>
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
                                                    <td>
                                                        <span title="<?php echo date('M d, Y H:i:s', strtotime($transaction['created_at'])); ?>">
                                                            <?php echo timeAgo($transaction['created_at']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center">No transactions found</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="wallet_transactions.php" class="btn btn-sm btn-primary">View All Transactions</a>
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
    
    <!-- Add Funds Modal -->
    <div class="modal fade" id="addFundsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Funds to Wallet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addFundsForm" action="process_wallet.php" method="post">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="user_id" class="form-label">Select User</label>
                            <select class="form-select" id="user_id" name="user_id" required>
                                <option value="">Select User</option>
                                <?php
                                $stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.phone FROM users u ORDER BY u.name");
                                $stmt->execute();
                                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($users as $user) {
                                    echo '<option value="' . $user['id'] . '">' . htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['email']) . ')</option>';
                                }
                                ?>
                            </select>
                        </div>
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
                        <input type="hidden" name="action" value="add_funds">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Funds</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Wallet Action Modal -->
    <div class="modal fade" id="walletActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="walletActionTitle">Wallet Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="walletActionForm" action="process_wallet.php" method="post">
                    <div class="modal-body">
                        <p id="walletActionDescription"></p>
                        <div class="mb-3">
                            <label for="wallet_amount" class="form-label">Amount (KSh)</label>
                            <input type="number" class="form-control" id="wallet_amount" name="amount" min="1" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="wallet_description" class="form-label">Description</label>
                            <textarea class="form-control" id="wallet_description" name="description" rows="2"></textarea>
                        </div>
                        <input type="hidden" id="wallet_id" name="wallet_id">
                        <input type="hidden" id="wallet_action" name="action">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="walletActionButton">Submit</button>
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
            
            // Add funds to specific wallet
            $('.add-funds').on('click', function() {
                const walletId = $(this).data('wallet-id');
                const userName = $(this).data('user-name');
                
                $('#walletActionTitle').text('Add Funds to Wallet');
                $('#walletActionDescription').text(`You are adding funds to ${userName}'s wallet.`);
                $('#wallet_id').val(walletId);
                $('#wallet_action').val('add_funds_to_wallet');
                $('#walletActionButton').removeClass('btn-warning').addClass('btn-success').text('Add Funds');
                
                $('#walletActionModal').modal('show');
            });
            
            // Withdraw funds from specific wallet
            $('.withdraw-funds').on('click', function() {
                const walletId = $(this).data('wallet-id');
                const userName = $(this).data('user-name');
                
                $('#walletActionTitle').text('Withdraw Funds from Wallet');
                $('#walletActionDescription').text(`You are withdrawing funds from ${userName}'s wallet.`);
                $('#wallet_id').val(walletId);
                $('#wallet_action').val('withdraw_funds');
                $('#walletActionButton').removeClass('btn-success').addClass('btn-warning').text('Withdraw Funds');
                
                $('#walletActionModal').modal('show');
            });
        });
    </script>
</body>
</html>