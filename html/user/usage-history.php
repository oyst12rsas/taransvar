<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access your usage history'));
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


$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$sessionId = isset($_GET['session_id']) ? $_GET['session_id'] : '';


$query = "SELECT u.*, s.session_id, s.expires_at, p.name as plan_name, p.type as plan_type, p.data_limit 
          FROM usage_logs u 
          JOIN sessions s ON u.session_id = s.session_id 
          JOIN plans p ON s.plan_id = p.id 
          WHERE u.user_id = ?";
$params = [$userId];

// Add filters
if ($dateFrom) {
    $query .= " AND DATE(u.start_time) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $query .= " AND DATE(u.start_time) <= ?";
    $params[] = $dateTo;
}

if ($sessionId) {
    $query .= " AND u.session_id = ?";
    $params[] = $sessionId;
}


$query .= " ORDER BY u.start_time DESC";


$stmt = $conn->prepare($query);
$stmt->execute($params);
$usageLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $conn->prepare("SELECT 
                        COUNT(DISTINCT session_id) as total_sessions,
                        SUM(data_used) as total_data_used,
                        SUM(TIMESTAMPDIFF(SECOND, start_time, IFNULL(end_time, NOW()))) as total_time
                        FROM usage_logs 
                        WHERE user_id = ?");
$stmt->execute([$userId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);


$totalSessions = $stats['total_sessions'] ?: 0;
$totalDataUsed = $stats['total_data_used'] ? formatDataSize($stats['total_data_used']) : '0 MB';
$totalTime = $stats['total_time'] ? formatTimeSpent($stats['total_time']) : '0 minutes';

// Get active sessions
$stmt = $conn->prepare("SELECT s.*, p.name as plan_name, p.type as plan_type, p.data_limit, p.speed 
                       FROM sessions s 
                       JOIN plans p ON s.plan_id = p.id 
                       WHERE s.user_id = ? AND s.expires_at > NOW() 
                       ORDER BY s.created_at DESC");
$stmt->execute([$userId]);
$activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);


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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage History - Taransvar WiFi Hotspot</title>

		
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
                    <?php if (isset($user['profile_picture']) && !empty($user['profile_picture'])): ?>
                        <img src="uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="User Avatar" class="rounded-circle">
                    <?php else: ?>
                        <img src="img/user-avatar.png" alt="User Avatar" class="rounded-circle">
                    <?php endif; ?>
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
                        <a class="nav-link" href="dashboard.php">
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
                        <a class="nav-link active" href="usage-history.php">
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
                    <i class="fas fa-chart-line"></i> Usage History
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
                                    <h5>Total Sessions</h5>
                                    <h3><?php echo $totalSessions; ?></h3>
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
                                    <h5>Total Data Used</h5>
                                    <h3><?php echo $totalDataUsed; ?></h3>
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
                                    <h5>Total Time</h5>
                                    <h3><?php echo $totalTime; ?></h3>
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
                </div>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter"></i> Filter Usage History</h5>
                    </div>
                    <div class="card-body">
                        <form action="usage-history.php" method="get" class="row g-3">
                            <div class="col-md-4">
                                <label for="date_from" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="date_to" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label for="session_id" class="form-label">Session ID</label>
                                <input type="text" class="form-control" id="session_id" name="session_id" value="<?php echo htmlspecialchars($sessionId); ?>" placeholder="Optional">
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <a href="usage-history.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-redo me-2"></i> Reset Filters
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Usage History Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Usage History</h5>
                        <button class="btn btn-sm btn-outline-primary" id="exportBtn">
                            <i class="fas fa-download me-1"></i> Export CSV
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($usageLogs) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="usageTable">
                                    <thead>
                                        <tr>
                                            <th>Start Time</th>
                                            <th>End Time</th>
                                            <th>Plan</th>
                                            <th>Data Used</th>
                                            <th>Duration</th>
                                            <th>Session ID</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usageLogs as $log): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($log['start_time'])); ?></td>
                                                <td>
                                                    <?php if ($log['end_time']): ?>
                                                        <?php echo date('M d, Y H:i', strtotime($log['end_time'])); ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">Active</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($log['plan_name']); ?></span>
                                                    <span class="badge bg-primary ms-2"><?php echo ucfirst(htmlspecialchars($log['plan_type'])); ?></span>
                                                </td>
                                                <td><?php echo formatDataSize($log['data_used']); ?></td>
                                                <td>
                                                    <?php
                                                    $startTime = strtotime($log['start_time']);
                                                    $endTime = $log['end_time'] ? strtotime($log['end_time']) : time();
                                                    $duration = $endTime - $startTime;
                                                    echo formatTimeSpent($duration);
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="text-monospace small"><?php echo substr($log['session_id'], 0, 8) . '...'; ?></span>
                                                    <button class="btn btn-sm btn-link copy-btn" data-clipboard-text="<?php echo htmlspecialchars($log['session_id']); ?>">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i> No usage history found matching your filters.
                                <?php if ($dateFrom || $dateTo || $sessionId): ?>
                                    <a href="usage-history.php" class="alert-link">Clear filters</a> to see all usage history.
                                <?php else: ?>
                                    <a href="user-plans.php" class="alert-link">Browse available plans</a> to get started.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Data Usage Chart -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-area"></i> Data Usage Trend</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="dataUsageChart" height="300"></canvas>
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
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../lib/wow/wow.min.js"></script>
    <script src="../lib/easing/easing.min.js"></script>
    <script src="../lib/waypoints/waypoints.min.js"></script>
    <script src="../lib/counterup/counterup.min.js"></script>
    <script src="../lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="../lib/lightbox/js/lightbox.min.js"></script>

    
<script src="../online-detector/online-detector.js"></script>
<script src="../online-detector/subscription-status.js"></script>
	
    <script>
        // Initialize WOW.js for animations
        new WOW().init();
        
        // Initialize clipboard.js
        new ClipboardJS('.copy-btn');
        
        // Copy button functionality
        document.addEventListener('DOMContentLoaded', function() {
            const copyBtns = document.querySelectorAll('.copy-btn');
            
            copyBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    const originalClass = icon.className;
                    
                    // Change icon to check
                    icon.className = 'fas fa-check text-success';
                    
                    // Reset icon after 2 seconds
                    setTimeout(() => {
                        icon.className = originalClass;
                    }, 2000);
                });
            });
            
            // Export to CSV
            document.getElementById('exportBtn').addEventListener('click', function() {
                const table = document.getElementById('usageTable');
                const rows = table.querySelectorAll('tbody tr');
                
                // Create CSV content
                let csv = 'Start Time,End Time,Plan,Data Used,Duration,Session ID\n';
                
                rows.forEach(row => {
                    const startTime = row.cells[0].textContent.trim();
                    const endTime = row.cells[1].textContent.trim();
                    const planCell = row.cells[2];
                    const plan = planCell.querySelector('.fw-bold').textContent.trim();
                    const planType = planCell.querySelector('.badge').textContent.trim();
                    const dataUsed = row.cells[3].textContent.trim();
                    const duration = row.cells[4].textContent.trim();
                    const sessionId = row.cells[5].querySelector('.text-monospace').textContent.trim();
                    
                    csv += `"${startTime}","${endTime}","${plan} (${planType})","${dataUsed}","${duration}","${sessionId}"\n`;
                });
                
                // Create download link
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('hidden', '');
                a.setAttribute('href', url);
                a.setAttribute('download', 'usage_history.csv');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            });
            
            // Data Usage Chart
            const ctx = document.getElementById('dataUsageChart').getContext('2d');
            
            // Sample data - in a real app, this would come from the server
            const usageData = {
                labels: [
                    <?php
                    // Get last 7 days of usage
                    $stmt = $conn->prepare("SELECT DATE(start_time) as date, SUM(data_used) as total_data 
                                          FROM usage_logs 
                                          WHERE user_id = ? 
                                          GROUP BY DATE(start_time) 
                                          ORDER BY date DESC 
                                          LIMIT 7");
                    $stmt->execute([$userId]);
                    $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $chartData = array_reverse($chartData);
                    
                    $labels = [];
                    $data = [];
                    
                    foreach ($chartData as $item) {
                        $labels[] = "'" . date('M d', strtotime($item['date'])) . "'";
                        $data[] = round($item['total_data'] / 1048576, 2); // Convert to MB
                    }
                    
                    echo implode(', ', $labels);
                    ?>
                ],
                datasets: [{
                    label: 'Data Usage (MB)',
                    data: [<?php echo implode(', ', $data); ?>],
                    backgroundColor: 'rgba(0, 123, 255, 0.2)',
                    borderColor: 'rgba(0, 123, 255, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true
                }]
            };
            
            const dataUsageChart = new Chart(ctx, {
                type: 'line',
                data: usageData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Data Usage (MB)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: ${context.raw} MB`;
                                }
                            }
                        }
                    }
                }
            });
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

