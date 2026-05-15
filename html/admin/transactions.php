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


if (isset($_POST['update_transaction']) && isset($_POST['transaction_id'])) {
    $transactionId = $_POST['transaction_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $conn->beginTransaction();
        
        // Get transaction details
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Update transaction status
        $stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $transactionId]);
        

        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'update', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Updated transaction status: Transaction ID $transactionId set to $newStatus",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $conn->commit();
        $response = ['success' => true, 'message' => 'Transaction status updated successfully'];
    } catch (Exception $e) {
        $conn->rollBack();
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Set up pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Set up filtering
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterPhone = isset($_GET['phone']) ? $_GET['phone'] : '';
$filterPlan = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$query = "FROM transactions t 
          LEFT JOIN users u ON t.user_id = u.id 
          JOIN plans p ON t.plan_id = p.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT t.*, u.name as user_name, p.name as plan_name " . $query;
$params = [];

if (!empty($filterStatus)) {
    $query .= " AND t.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterPhone)) {
    $query .= " AND t.phone LIKE ?";
    $params[] = "%$filterPhone%";
}

if ($filterPlan > 0) {
    $query .= " AND t.plan_id = ?";
    $params[] = $filterPlan;
}

if (!empty($filterDateFrom)) {
    $query .= " AND DATE(t.created_at) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $query .= " AND DATE(t.created_at) <= ?";
    $params[] = $filterDateTo;
}

// Get total count
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Get data with pagination
$dataQuery .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(amount) as total_amount,
                       SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount,
                       SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                       SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) as failed_amount,
                       COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                       COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                       COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
                       FROM transactions");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get plans for filter
$stmt = $conn->prepare("SELECT id, name FROM plans ORDER BY name");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Transactions - Taransvar WiFi Hotspot</title>
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
                        <a class="nav-link active" href="transactions.php">
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
                        <a class="nav-link active" href="transactions.php">
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
                    <h1 class="h3 mb-0 text-gray-800">Transactions</h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm me-2" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                        <a href="export_transactions.php" class="btn btn-sm btn-success shadow-sm">
                            <i class="fas fa-download fa-sm text-white-50"></i> Export
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
                                            Total Transactions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
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
                                            Completed Transactions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['completed_count']); ?></div>
                                        <div class="text-xs text-muted">KSh <?php echo number_format($stats['completed_amount'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-check-circle fa-2x text-gray-300"></i>
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
                                            Pending Transactions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['pending_count']); ?></div>
                                        <div class="text-xs text-muted">KSh <?php echo number_format($stats['pending_amount'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x text-gray-300"></i>
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
                                            Failed Transactions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['failed_count']); ?></div>
                                        <div class="text-xs text-muted">KSh <?php echo number_format($stats['failed_amount'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-times-circle fa-2x text-gray-300"></i>
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
                            <form method="get" action="transactions.php">
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="completed" <?php echo $filterStatus == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="failed" <?php echo $filterStatus == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($filterPhone); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="plan" class="form-label">Plan</label>
                                        <select class="form-select" id="plan" name="plan">
                                            <option value="0">All Plans</option>
                                            <?php foreach ($plans as $planOption): ?>
                                                <option value="<?php echo $planOption['id']; ?>" <?php echo $filterPlan == $planOption['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($planOption['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
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
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="transactions.php" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">All Transactions</h6>
                        <span class="badge bg-primary"><?php echo number_format($totalRows); ?> Records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Phone</th>
                                        <th>Plan</th>
                                        <th>Amount</th>
                                        <th>M-Pesa Code</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
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
                                                <?php else: ?>
                                                    <span class="text-muted">Guest</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($transaction['phone']); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['plan_name']); ?></td>
                                            <td>KSh <?php echo number_format($transaction['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['mpesa_code']); ?></td>
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
                                                    <?php echo timeAgo($transaction['created_at']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="view_transaction.php?id=<?php echo $transaction['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($transaction['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-success approve-transaction" data-id="<?php echo $transaction['id']; ?>">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger reject-transaction" data-id="<?php echo $transaction['id']; ?>">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
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
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Next">
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
    
    <!-- Approve Transaction Modal -->
    <div class="modal fade" id="approveTransactionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this transaction?</p>
                    <p>This will mark the transaction as completed and allow the user to create a session.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="confirmApprove">Approve</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reject Transaction Modal -->
    <div class="modal fade" id="rejectTransactionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Transaction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject this transaction?</p>
                    <p class="text-danger">This will mark the transaction as failed and prevent the user from creating a session.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmReject">Reject</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Approve transaction
            $('.approve-transaction').on('click', function() {
                const transactionId = $(this).data('id');
                $('#confirmApprove').data('id', transactionId);
                $('#approveTransactionModal').modal('show');
            });
            
            // Confirm approve
            $('#confirmApprove').on('click', function() {
                const transactionId = $(this).data('id');
                
                $.ajax({
                    url: 'transactions.php',
                    type: 'POST',
                    data: {
                        update_transaction: true,
                        transaction_id: transactionId,
                        new_status: 'completed'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#approveTransactionModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#approveTransactionModal').modal('hide');
                        alert('An error occurred while updating the transaction');
                    }
                });
            });
            
            // Reject transaction
            $('.reject-transaction').on('click', function() {
                const transactionId = $(this).data('id');
                $('#confirmReject').data('id', transactionId);
                $('#rejectTransactionModal').modal('show');
            });
            
            // Confirm reject
            $('#confirmReject').on('click', function() {
                const transactionId = $(this).data('id');
                
                $.ajax({
                    url: 'transactions.php',
                    type: 'POST',
                    data: {
                        update_transaction: true,
                        transaction_id: transactionId,
                        new_status: 'failed'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#rejectTransactionModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#rejectTransactionModal').modal('hide');
                        alert('An error occurred while updating the transaction');
                    }
                });
            });
        });
    </script>
</body>
</html>