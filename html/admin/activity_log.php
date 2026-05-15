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


$stmt = $conn->prepare("SELECT id, name FROM admins ORDER BY name");
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set up pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Set up filtering
$filterAdmin = isset($_GET['admin']) ? (int)$_GET['admin'] : 0;
$filterAction = isset($_GET['action']) ? $_GET['action'] : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query
$query = "FROM admin_activity_logs al JOIN admins a ON al.admin_id = a.id WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT al.*, a.name as admin_name " . $query;
$params = [];

if ($filterAdmin > 0) {
    $query .= " AND al.admin_id = ?";
    $params[] = $filterAdmin;
}

if (!empty($filterAction)) {
    $query .= " AND al.action = ?";
    $params[] = $filterAction;
}

if (!empty($filterDateFrom)) {
    $query .= " AND DATE(al.created_at) >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $query .= " AND DATE(al.created_at) <= ?";
    $params[] = $filterDateTo;
}


$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);


$dataQuery .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for filter
$stmt = $conn->prepare("SELECT DISTINCT action FROM admin_activity_logs ORDER BY action");
$stmt->execute();
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);


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
    <title>Activity Log - Taransvar WiFi Hotspot</title>
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
                                <a class="dropdown-item active" href="activity_log.php">
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
                </ul>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="container-fluid px-4">
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Activity Log</h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="collapse mb-4" id="filterCollapse">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Options</h6>
                        </div>
                        <div class="card-body">
                            <form method="get" action="activity_log.php">
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label for="admin" class="form-label">Admin</label>
                                        <select class="form-select" id="admin" name="admin">
                                            <option value="0">All Admins</option>
                                            <?php foreach ($admins as $adminOption): ?>
                                                <option value="<?php echo $adminOption['id']; ?>" <?php echo $filterAdmin == $adminOption['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($adminOption['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="action" class="form-label">Action</label>
                                        <select class="form-select" id="action" name="action">
                                            <option value="">All Actions</option>
                                            <?php foreach ($actions as $actionOption): ?>
                                                <option value="<?php echo $actionOption; ?>" <?php echo $filterAction == $actionOption ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst($actionOption); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="date_from" class="form-label">Date From</label>
                                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $filterDateFrom; ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="date_to" class="form-label">Date To</label>
                                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $filterDateTo; ?>">
                                    </div>
                                </div>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="activity_log.php" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Activity History</h6>
                        <span class="badge bg-primary"><?php echo number_format($totalRows); ?> Records</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Admin</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>IP Address</th>
                                        <th>Date/Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($logs) > 0): ?>
                                        <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><?php echo $log['id']; ?></td>
                                            <td><?php echo htmlspecialchars($log['admin_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch ($log['action']) {
                                                        case 'login': echo 'success'; break;
                                                        case 'logout': echo 'secondary'; break;
                                                        case 'create': echo 'primary'; break;
                                                        case 'update': echo 'info'; break;
                                                        case 'delete': echo 'danger'; break;
                                                        case 'approve': echo 'success'; break;
                                                        case 'reject': echo 'warning'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($log['action']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                            <td>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?>">
                                                    <?php echo timeAgo($log['created_at']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No activity logs found</td>
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
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&admin=<?php echo $filterAdmin; ?>&action=<?php echo $filterAction; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&admin=<?php echo $filterAdmin; ?>&action=<?php echo $filterAction; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&admin=<?php echo $filterAdmin; ?>&action=<?php echo $filterAction; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?>" aria-label="Next">
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</body>
</html>


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

// Set up pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;


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

// Get plans for filter
$stmt = $conn->prepare("SELECT id, name FROM plans ORDER BY name");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get transaction statistics
$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                       SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue
                       FROM transactions");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper function to format time ago
if (!function_exists('timeAgo')) {
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
}

?>
&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Taransvar WiFi Hotspot</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin_dashboard.css">
</head>
<body>
    &lt;!-- Navbar -->
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
        &lt;!-- Sidebar -->
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
                </ul>
            </div>
        </nav>
        
        &lt;!-- Main Content -->
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
                
                &lt;!-- Stats Cards -->
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
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x text-gray-300"></i>
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
                                            Total Revenue
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">KSh <?php echo number_format($stats['total_revenue'], 2); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                &lt;!-- Filter Section -->
                <div class="collapse mb-4" id="filterCollapse">
                    <div class="card shadow">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Filter Options</h6>
                        </div>
                        <div class="card-body">
                            <form method="get" action="transactions.php">
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="completed" <?php echo $filterStatus == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="failed" <?php echo $filterStatus == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($filterPhone); ?>">
                                    </div>
                                    <div class="col-md-2">
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
                                    <div class="col-md-2">
                                        <label for="date_from" class="form-label">Date From</label>
                                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $filterDateFrom; ?>">
                                    </div>
                                    <div class="col-md-2">
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
                                        <th>Used</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($transactions) > 0): ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo $transaction['id']; ?></td>
                                            <td>
                                                <?php if ($transaction['user_name']): ?>
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
                                                <?php if ($transaction['used']): ?>
                                                    <span class="badge bg-success">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No</span>
                                                <?php endif; ?>
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
                                            <td colspan="10" class="text-center">No transactions found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        &lt;!-- Pagination -->
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
    
    &lt;!-- Footer -->
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
    
    &lt;!-- Transaction Action Modal -->
    <div class="modal fade" id="transactionActionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="actionModalBody">
                    Are you sure you want to process this transaction?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAction">Confirm</button>
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
                $('#actionModalTitle').text('Approve Transaction');
                $('#actionModalBody').text('Are you sure you want to approve this transaction?');
                $('#confirmAction').removeClass('btn-danger').addClass('btn-success').data('action', 'approve').data('id', transactionId);
                $('#transactionActionModal').modal('show');
            });
            
            // Reject transaction
            $('.reject-transaction').on('click', function() {
                const transactionId = $(this).data('id');
                $('#actionModalTitle').text('Reject Transaction');
                $('#actionModalBody').text('Are you sure you want to reject this transaction?');
                $('#confirmAction').removeClass('btn-success').addClass('btn-danger').data('action', 'reject').data('id', transactionId);
                $('#transactionActionModal').modal('show');
            });
            
            // Confirm action
            $('#confirmAction').on('click', function() {
                const action = $(this).data('action');
                const transactionId = $(this).data('id');
                
                $.ajax({
                    url: 'process_transaction.php',
                    type: 'POST',
                    data: {
                        action: action,
                        transaction_id: transactionId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#transactionActionModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#transactionActionModal').modal('hide');
                        alert('An error occurred while processing the transaction');
                    }
                });
            });
        });
    </script>
</body>
</html>