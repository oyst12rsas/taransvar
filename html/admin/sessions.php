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


if (isset($_POST['terminate_session']) && isset($_POST['session_id'])) {
    $sessionId = $_POST['session_id'];
    
    try {
        // Get session details for logging
        $stmt = $conn->prepare("SELECT s.*, u.name as user_name, p.name as plan_name 
                               FROM sessions s 
                               LEFT JOIN users u ON s.user_id = u.id 
                               JOIN plans p ON s.plan_id = p.id 
                               WHERE s.id = ?");
        $stmt->execute([$sessionId]);
        $sessionData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sessionData) {
            throw new Exception('Session not found');
        }
        
        // Update session expiry to now (terminate)
        $stmt = $conn->prepare("UPDATE sessions SET expires_at = NOW() WHERE id = ?");
        $stmt->execute([$sessionId]);
        
        // Log the activity
        $userInfo = $sessionData['user_name'] ? $sessionData['user_name'] : $sessionData['phone'];
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'terminate', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Terminated session: ID $sessionId for $userInfo (Plan: {$sessionData['plan_name']})",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $response = ['success' => true, 'message' => 'Session terminated successfully'];
    } catch (Exception $e) {
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
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'active';
$filterPhone = isset($_GET['phone']) ? $_GET['phone'] : '';
$filterPlan = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;

// Build query
$query = "FROM sessions s 
          LEFT JOIN users u ON s.user_id = u.id 
          JOIN plans p ON s.plan_id = p.id 
          WHERE 1=1";
$countQuery = "SELECT COUNT(*) as total " . $query;
$dataQuery = "SELECT s.*, u.name as user_name, p.name as plan_name " . $query;
$params = [];

if ($filterStatus === 'active') {
    $query .= " AND s.expires_at > NOW()";
} elseif ($filterStatus === 'expired') {
    $query .= " AND s.expires_at <= NOW()";
}

if (!empty($filterPhone)) {
    $query .= " AND s.phone LIKE ?";
    $params[] = "%$filterPhone%";
}

if ($filterPlan > 0) {
    $query .= " AND s.plan_id = ?";
    $params[] = $filterPlan;
}

// Get total count
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRows = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRows / $limit);

// Get data with pagination
$dataQuery .= " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($dataQuery);
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get plans for filter
$stmt = $conn->prepare("SELECT id, name FROM plans ORDER BY name");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $conn->prepare("SELECT 
                       COUNT(*) as total_count,
                       SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active_count,
                       SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired_count
                       FROM sessions");
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

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
&lt;!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions - Taransvar WiFi Hotspot</title>
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">

 
    <link href="../css/bootstrap.min.css" rel="stylesheet">
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
                        <a class="nav-link" href="transactions.php">
                            <i class="fas fa-money-bill-wave"></i> Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sessions.php">
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
                        <a class="nav-link" href="transactions.php">
                            <i class="fas fa-money-bill-wave"></i> Transactions
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="sessions.php">
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
        
        &lt;!-- Main Content -->
        <main class="main-content">
            <div class="container-fluid px-4">
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Sessions</h1>
                    <div>
                        <button type="button" class="btn btn-sm btn-primary shadow-sm me-2" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter fa-sm text-white-50"></i> Filter
                        </button>
                        <a href="export_sessions.php" class="btn btn-sm btn-success shadow-sm">
                            <i class="fas fa-download fa-sm text-white-50"></i> Export
                        </a>
                    </div>
                </div>
                
                &lt;!-- Stats Cards -->
                <div class="row stats-cards mb-4">
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Sessions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-wifi fa-2x text-gray-300"></i>
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
                                            Active Sessions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['active_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-signal fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6 mb-4">
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Expired Sessions
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['expired_count']); ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-clock fa-2x text-gray-300"></i>
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
                            <form method="get" action="sessions.php">
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo $filterStatus == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="expired" <?php echo $filterStatus == 'expired' ? 'selected' : ''; ?>>Expired</option>
                                            <option value="all" <?php echo $filterStatus == 'all' ? 'selected' : ''; ?>>All</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($filterPhone); ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="plan" class="form-label">Plan</label>
                                        <select class="form-select" id="plan" name="plan">
                                            <option value="0">All Plans  id="plan" name="plan">
                                            <option value="0">All Plans</option>
                                            <?php foreach ($plans as $planOption): ?>
                                                <option value="<?php echo $planOption['id']; ?>" <?php echo $filterPlan == $planOption['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($planOption['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="sessions.php" class="btn btn-secondary me-md-2">Reset</a>
                                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">All Sessions</h6>
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
                                        <th>IP Address</th>
                                        <th>MAC Address</th>
                                        <th>Created</th>
                                        <th>Expires</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($sessions) > 0): ?>
                                        <?php foreach ($sessions as $session): ?>
                                        <tr>
                                            <td><?php echo $session['id']; ?></td>
                                            <td>
                                                <?php if ($session['user_name']): ?>
                                                    <a href="view_user.php?id=<?php echo $session['user_id']; ?>">
                                                        <?php echo htmlspecialchars($session['user_name']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Guest</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($session['phone']); ?></td>
                                            <td><?php echo htmlspecialchars($session['plan_name']); ?></td>
                                            <td><?php echo htmlspecialchars($session['ip_address'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($session['mac_address'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($session['created_at'])); ?>">
                                                    <?php echo timeAgo($session['created_at']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span title="<?php echo date('M d, Y H:i:s', strtotime($session['expires_at'])); ?>">
                                                    <?php echo date('M d, Y H:i', strtotime($session['expires_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (strtotime($session['expires_at']) > time()): ?>
                                                    <span class="badge bg-success">
                                                        Active (<?php echo timeRemaining($session['expires_at']); ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Expired</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="view_session.php?id=<?php echo $session['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (strtotime($session['expires_at']) > time()): ?>
                                                    <button type="button" class="btn btn-sm btn-danger terminate-session" data-id="<?php echo $session['id']; ?>">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center">No sessions found</td>
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
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $filterStatus; ?>&phone=<?php echo $filterPhone; ?>&plan=<?php echo $filterPlan; ?>" aria-label="Next">
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
    
    &lt;!-- Terminate Session Modal -->
    <div class="modal fade" id="terminateSessionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terminate Session</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to terminate this session? This will immediately disconnect the user from the WiFi network.</p>
                    <p class="text-danger">Warning: This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmTerminate">Terminate</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Terminate session
            $('.terminate-session').on('click', function() {
                const sessionId = $(this).data('id');
                $('#confirmTerminate').data('id', sessionId);
                $('#terminateSessionModal').modal('show');
            });
            
            // Confirm terminate
            $('#confirmTerminate').on('click', function() {
                const sessionId = $(this).data('id');
                
                $.ajax({
                    url: 'sessions.php',
                    type: 'POST',
                    data: {
                        terminate_session: true,
                        session_id: sessionId
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#terminateSessionModal').modal('hide');
                        
                        if (response.success) {
                            // Reload the page to show updated status
                            location.reload();
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#terminateSessionModal').modal('hide');
                        alert('An error occurred while terminating the session');
                    }
                });
            });
        });
    </script>
</body>
</html>