<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access the dashboard'));
    exit;
}

// Get user information from session
$userId = $_SESSION['user_id'];
$username = isset($_SESSION['username']) ? $_SESSION['username'] : 'User';
$email = isset($_SESSION['email']) ? $_SESSION['email'] : '';
$phone = isset($_SESSION['phone']) ? $_SESSION['phone'] : '';

// Get user data from radcheck table
$stmt = $conn->prepare("SELECT * FROM radcheck WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If user data not found, use session data as fallback
if (!$user) {
    $user = [
        'id' => $userId,
        'name' => $username,
        'email' => $email,
        'phone' => $phone
    ];
}

$wallet = new Wallet($conn, $userId);
$walletDetails = $wallet->getWalletDetails();

// Get active sessions
try {
    $stmt = $conn->prepare("SELECT s.*, p.name as plan_name, p.type as plan_type, p.data_limit, p.speed 
                           FROM sessions s 
                           JOIN plans p ON s.plan_id = p.id 
                           WHERE s.user_id = ? AND s.expires_at > NOW() 
                           ORDER BY s.created_at DESC");
    $stmt->execute([$userId]);
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching active sessions: " . $e->getMessage());
    $activeSessions = [];
}

// Get recent transactions
try {
    $stmt = $conn->prepare("SELECT t.*, p.name as plan_name, p.type as plan_type, p.price 
                           FROM transactions t 
                           JOIN plans p ON t.plan_id = p.id 
                           WHERE t.user_id = ? 
                           ORDER BY t.created_at DESC 
                           LIMIT 10");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
}

// Get usage statistics
try {
    $stmt = $conn->prepare("SELECT SUM(data_used) as total_data_used, 
                           COUNT(DISTINCT session_id) as total_sessions,
                           SUM(TIMESTAMPDIFF(SECOND, start_time, IFNULL(end_time, NOW()))) as total_time
                           FROM usage_logs 
                           WHERE user_id = ?");
    $stmt->execute([$userId]);
    $usageStats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching usage stats: " . $e->getMessage());
    $usageStats = [
        'total_data_used' => 0,
        'total_sessions' => 0,
        'total_time' => 0
    ];
}

// Format usage stats
$totalDataUsed = $usageStats['total_data_used'] ? formatDataSize($usageStats['total_data_used']) : '0 MB';
$totalSessions = $usageStats['total_sessions'] ?: 0;
$totalTime = $usageStats['total_time'] ? formatTimeSpent($usageStats['total_time']) : '0 minutes';

// Helper functions
function formatDataSize($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

function formatTimeSpent($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    } elseif ($seconds < 3600) {
        return floor($seconds / 60) . ' minutes';
    } elseif ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . ' hours ' . $minutes . ' minutes';
    } else {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        return $days . ' days ' . $hours . ' hours';
    }
}

// Get the current user's data from radcheck table
try {
    $stmt = $conn->prepare("SELECT mbquota, mbusage, expirytime FROM radcheck WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $userData = null;
}

// If data exists, use it; otherwise set default values
if ($userData) {
    $mbQuota = $userData['mbquota'] ? $userData['mbquota'] . ' MB' : '0 MB';
    $mbUsage = $userData['mbusage'] ? $userData['mbusage'] . ' MB' : '0 MB';
    
    // Format expiry time if it exists
    if ($userData['expirytime']) {
        $expiryTime = date('M d, Y H:i', strtotime($userData['expirytime']));
    } else {
        $expiryTime = 'No expiry set';
    }
} else {
    $mbQuota = '0 MB';
    $mbUsage = '0 MB';
    $expiryTime = 'No expiry set';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Taransvar WiFi Hotspot</title>
	
	
	
		
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../lib/animate/animate.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="wallet/css/wallet.css">
    
    <link rel="stylesheet" href="../online-detector/online-detector.css">
    
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <div class="col-lg-4 col-md-12 text-center text-lg-start">
                <a href="../index.php" class="navbar-brand m-0 p-0 logo-container">
                    <img src="../img/logo-w.png" alt="Logo" width="240px" height="60px" class="logo">
                </a>
            </div>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-globe"></i> Our Websites
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../websites/cybersecurity/index.html">
                                <i class="fas fa-shield-virus"></i> Cybersecurity & Mental Health 
                            </a></li>
                            
                        </ul>
                    </li>
                    
                </ul>
                
                <div class="d-flex align-items-center system-status">
                    <a href="user-wallet.php" style="text-decoration: none; color: inherit;">
                        <div class="mini-wallet-balance me-3">
                            <i class="fas fa-wallet"></i>
                            <span>KSh <?php echo number_format($walletDetails['balance'], 2); ?></span>
                        </div>
                    </a>
                    <span class="badge" id="connection-badge">
						<i class="fas fa-circle"></i>
						<span class="connection-status-text">Checking...</span>
					</span>
                    <div class="dropdown">
                        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['name']); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../index.php">Home</a></li>
                            <li><a class="dropdown-item active" href="dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php">Profile Settings</a></li>
                            <li><a class="dropdown-item" href="user-wallet.php">My Wallet</a></li>
                            <li><a class="dropdown-item" href="usage-history.php">Usage History</a></li>
                            <li><a class="dropdown-item" href="payment-history.php">Payment History</a></li>
                            <li><a class="dropdown-item" href="user-plans.php">Wifi Plans</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php">Logout</a></li>
                            <li><hr class="dropdown-divider"></li>
                            
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="dashboard-sidebar">
            <div class="user-profile text-center">
                <div class="profile-image">
                    <img src="img/user-avatar.png" alt="User Avatar" class="rounded-circle">
                    <a href="profile.php" class="edit-profile-btn">
                        <i class="fas fa-camera"></i>
                    </a>
                </div>
                <h4><?php echo htmlspecialchars($user['name']); ?></h4>
                <p class="text-muted"><?php echo htmlspecialchars($user['email']); ?></p>
            </div>
            
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="../index.php">
                            <i class="fas fa-home"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <i class="fas fa-user-cog"></i> Profile Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user-wallet.php">
                            <i class="fas fa-wallet"></i> My Wallet
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user-plans.php">
                            <i class="fas fa-list-alt"></i> WiFi Plans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="payment-history.php">
                            <i class="fas fa-history"></i> Payment History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="usage-history.php">
                            <i class="fas fa-chart-line"></i> Usage History
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="dashboard-main">
            <div class="container-fluid">
                <h1 class="dashboard-title">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </h1>
                
                <!-- Stats Cards -->
                <div class="row stats-cards">
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-primary">
                                    <i class="fas fa-wifi"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Data Quota</h5>
                                    <h3><?php echo $mbQuota; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-success">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Data Used</h5>
                                    <h3><?php echo $mbUsage; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-info">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Expiry Time</h5>
                                    <h3><?php echo $expiryTime; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Active Plans -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-wifi"></i> Active WiFi Plans</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($activeSessions) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Plan</th>
                                            <th>Data Limit</th>
                                            <th>Speed</th>
                                            <th>Started</th>
                                            <th>Expires</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activeSessions as $session): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($session['plan_name']); ?></span>
                                                    <span class="badge bg-primary ms-2"><?php echo ucfirst(htmlspecialchars($session['plan_type'])); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars($session['data_limit']); ?></td>
                                                <td><?php echo htmlspecialchars($session['speed']); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($session['created_at'])); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($session['expires_at'])); ?></td>
                                                <td>
                                                    <span class="badge bg-success">Active</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i> You don't have any active WiFi plans.
                                <a href="user-plans.php" class="alert-link">Browse available plans</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (count($activeSessions) > 0): ?>
                        <div class="card-footer text-end">
                            <a href="usage-history.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-chart-line me-1"></i> View Detailed Usage
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Transactions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Recent Transactions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($transactions) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Plan</th>
                                            <th>Amount</th>
                                            <th>M-Pesa Code</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                                <td>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($transaction['plan_name']); ?></span>
                                                    <span class="badge bg-primary ms-2"><?php echo ucfirst(htmlspecialchars($transaction['plan_type'])); ?></span>
                                                </td>
                                                <td>KSh <?php echo number_format($transaction['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['mpesa_code']); ?></td>
                                                <td>
                                                    <?php if ($transaction['status'] == 'completed'): ?>
                                                        <span class="badge bg-success">Completed</span>
                                                    <?php elseif ($transaction['status'] == 'pending'): ?>
                                                        <span class="badge bg-warning text-dark">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Failed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i> You don't have any transactions yet.
                                <a href="user-plans.php" class="alert-link">Browse available plans</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (count($transactions) > 0): ?>
                        <div class="card-footer text-end">
                            <a href="payment-history.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-history me-1"></i> View All Transactions
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <a href="user-plans.php" class="btn btn-primary w-100">
                                    <i class="fas fa-shopping-cart me-2"></i> Buy WiFi Plan
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="profile.php" class="btn btn-success w-100">
                                    <i class="fas fa-user-edit me-2"></i> Update Profile
                                </a>
                            </div>
                            <div class="col-md-4">
                                <a href="support.html" class="btn btn-info w-100 text-white">
                                    <i class="fas fa-headset me-2"></i> Get Support
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Footer -->
    <footer class="footer bg-dark text-white mt-auto">
        <div class="container py-4">
            <div class="row">
                <div class="col-lg-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-white">Home</a></li>
                        <li><a href="user-plans.php" class="text-white">WiFi Plans</a></li>
                        <li><a href="support.html" class="text-white">Support</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h5>Contact Us</h5>
                    <p><i class="fas fa-envelope me-2"></i> info@taransvar.no</p>
                    <p><i class="fas fa-phone me-2"></i> +254 712 345 678</p>
                </div>
                <div class="col-lg-4">
                    <h5>Connect With Us</h5>
                    <div class="social-links">
                        <a href="#" class="btn btn-outline-light me-2"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="btn btn-outline-light me-2"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="btn btn-outline-light"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-12 text-center">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> Taransvar. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/counterup/counterup.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="lib/lightbox/js/lightbox.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
<script src="../online-detector/online-detector.js"></script>
<script src="../online-detector/subscription-status.js"></script>
	
    <script>
        // Initialize WOW.js for animations
        new WOW().init();
        
        document.addEventListener('DOMContentLoaded', function() {
            const counterUp = window.counterUp.default;
            const callback = entries => {
                entries.forEach(entry => {
                    const el = entry.target;
                    if (entry.isIntersecting && !el.classList.contains('is-visible')) {
                        counterUp(el, {
                            duration: 2000,
                            delay: 16,
                        });
                        el.classList.add('is-visible');
                    }
                });
            };
            
            const IO = new IntersectionObserver(callback, { threshold: 1 });
            const elements = document.querySelectorAll('.counter');
            elements.forEach(el => IO.observe(el));
        });
    </script>
    
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Online Detector
    const detector = new OnlineDetector({
        checkInterval: 5000,
        timeout: 3000,
        popupDuration: 7000,
        popupCooldown: 60000
    });
    
    // Replace connection badge
    detector.replaceHeaderBadge('#connection-badge');
    
    // Initialize Subscription Status
    const subscription = new SubscriptionStatus({
        checkInterval: 10000,
        popupDuration: 7000,
        popupCooldown: 60000
    });
    
    // Add subscription badge next to connection badge
    subscription.addSubscriptionBadge('#connection-badge');
});
</script>
</body>
</html>


