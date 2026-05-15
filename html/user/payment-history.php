<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access your payment history'));
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


$status = isset($_GET['status']) ? $_GET['status'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$planType = isset($_GET['plan_type']) ? $_GET['plan_type'] : '';

// Build query
$query = "SELECT t.*, p.name as plan_name, p.type as plan_type, p.price, p.data_limit, p.speed 
          FROM transactions t 
          JOIN plans p ON t.plan_id = p.id 
          WHERE t.user_id = ?";
$params = [$userId];

// Add filters
if ($status) {
    $query .= " AND t.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $query .= " AND DATE(t.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $query .= " AND DATE(t.created_at) <= ?";
    $params[] = $dateTo;
}

if ($planType) {
    $query .= " AND p.type = ?";
    $params[] = $planType;
}

// Add order by
$query .= " ORDER BY t.created_at DESC";


$stmt = $conn->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $conn->prepare("SELECT 
                        COUNT(*) as total_transactions,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_spent
                        FROM transactions 
                        WHERE user_id = ?");
$stmt->execute([$userId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - Taransvar WiFi Hotspot</title>

		
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
                        <a class="nav-link active" href="payment-history.php">
                            <i class="fas fa-history"></i> Payment History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="usage-history.php">
                            <i class="fas fa-chart-line"></i> Usage History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="support.html">
                            <i class="fas fa-question-circle"></i> Support
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
                    <i class="fas fa-history"></i> Payment History
                </h1>
                
                <!-- Stats Cards -->
                <div class="row stats-cards">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-primary">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Total Transactions</h5>
                                    <h3><?php echo $stats['total_transactions']; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Completed</h5>
                                    <h3><?php echo $stats['completed_transactions']; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Pending</h5>
                                    <h3><?php echo $stats['pending_transactions']; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-icon bg-info">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stats-info">
                                    <h5>Total Spent</h5>
                                    <h3>KSh <?php echo number_format($stats['total_spent'], 2); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter"></i> Filter Transactions</h5>
                    </div>
                    <div class="card-body">
                        <form action="payment-history.php" method="get" class="row g-3">
                            <div class="col-md-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="failed" <?php echo $status == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="plan_type" class="form-label">Plan Type</label>
                                <select class="form-select" id="plan_type" name="plan_type">
                                    <option value="">All Types</option>
                                    <option value="hourly" <?php echo $planType == 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                    <option value="daily" <?php echo $planType == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="monthly" <?php echo $planType == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="date_from" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label for="date_to" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <a href="payment-history.php" class="btn btn-secondary ms-2">
                                    <i class="fas fa-redo me-2"></i> Reset Filters
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Transactions Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Transaction List</h5>
                        <button class="btn btn-sm btn-outline-primary" id="exportBtn">
                            <i class="fas fa-download me-1"></i> Export CSV
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (count($transactions) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="transactionsTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>M-Pesa Code</th>
                                            <th>Plan</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($transaction['mpesa_code']); ?></td>
                                                <td>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($transaction['plan_name']); ?></span>
                                                    <span class="badge bg-primary ms-2"><?php echo ucfirst(htmlspecialchars($transaction['plan_type'])); ?></span>
                                                </td>
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
                                                    <button class="btn btn-sm btn-info text-white view-details-btn" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#transactionDetailsModal"
                                                            data-id="<?php echo $transaction['id']; ?>"
                                                            data-date="<?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?>"
                                                            data-mpesa-code="<?php echo htmlspecialchars($transaction['mpesa_code']); ?>"
                                                            data-plan="<?php echo htmlspecialchars($transaction['plan_name']); ?>"
                                                            data-plan-type="<?php echo ucfirst(htmlspecialchars($transaction['plan_type'])); ?>"
                                                            data-amount="<?php echo number_format($transaction['amount'], 2); ?>"
                                                            data-status="<?php echo htmlspecialchars($transaction['status']); ?>"
                                                            data-phone="<?php echo htmlspecialchars($transaction['phone']); ?>"
                                                            data-data-limit="<?php echo htmlspecialchars($transaction['data_limit']); ?>"
                                                            data-speed="<?php echo htmlspecialchars($transaction['speed']); ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <?php if ($transaction['status'] == 'pending'): ?>
                                                        <button class="btn btn-sm btn-warning check-status-btn" 
                                                                data-id="<?php echo $transaction['id']; ?>"
                                                                data-mpesa-code="<?php echo htmlspecialchars($transaction['mpesa_code']); ?>">
                                                            <i class="fas fa-sync-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <a href="receipt.php?id=<?php echo $transaction['id']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                        <i class="fas fa-file-invoice"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i> No transactions found matching your filters.
                                <?php if ($status || $dateFrom || $dateTo || $planType): ?>
                                    <a href="payment-history.php" class="alert-link">Clear filters</a> to see all transactions.
                                <?php else: ?>
                                    <a href="user-plans.php" class="alert-link">Browse available plans</a> to make your first purchase.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Transaction Details Modal -->
    <div class="modal fade" id="transactionDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Transaction Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="transaction-status mb-4 text-center">
                        <div class="status-badge mb-2">
                            <span class="badge bg-success px-4 py-2" id="statusBadge">Completed</span>
                        </div>
                        <h4 class="mb-1" id="amountDisplay">KSh 0.00</h4>
                        <p class="text-muted" id="dateDisplay">Jan 01, 2023 00:00</p>
                    </div>
                    
                    <div class="transaction-details">
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">Transaction ID:</div>
                            <div class="col-7" id="transactionId">-</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">M-Pesa Code:</div>
                            <div class="col-7" id="mpesaCode">-</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">Phone Number:</div>
                            <div class="col-7" id="phoneNumber">-</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">Plan:</div>
                            <div class="col-7">
                                <span id="planName">-</span>
                                <span class="badge bg-primary ms-1" id="planType">-</span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">Data Limit:</div>
                            <div class="col-7" id="dataLimit">-</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-5 fw-bold">Speed:</div>
                            <div class="col-7" id="speed">-</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" class="btn btn-primary" id="receiptBtn" target="_blank">
                        <i class="fas fa-file-invoice me-2"></i> View Receipt
                    </a>
                </div>
            </div>
        </div>
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
        
        // Transaction Details Modal
        document.addEventListener('DOMContentLoaded', function() {
            const viewDetailsBtns = document.querySelectorAll('.view-details-btn');
            
            viewDetailsBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const date = this.getAttribute('data-date');
                    const mpesaCode = this.getAttribute('data-mpesa-code');
                    const plan = this.getAttribute('data-plan');
                    const planType = this.getAttribute('data-plan-type');
                    const amount = this.getAttribute('data-amount');
                    const status = this.getAttribute('data-status');
                    const phone = this.getAttribute('data-phone');
                    const dataLimit = this.getAttribute('data-data-limit');
                    const speed = this.getAttribute('data-speed');
                    
                  
                    document.getElementById('transactionId').textContent = id;
                    document.getElementById('dateDisplay').textContent = date;
                    document.getElementById('mpesaCode').textContent = mpesaCode;
                    document.getElementById('planName').textContent = plan;
                    document.getElementById('planType').textContent = planType;
                    document.getElementById('amountDisplay').textContent = `KSh ${amount}`;
                    document.getElementById('phoneNumber').textContent = phone;
                    document.getElementById('dataLimit').textContent = dataLimit;
                    document.getElementById('speed').textContent = speed;
                    
                    // Update status badge
                    const statusBadge = document.getElementById('statusBadge');
                    statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                    statusBadge.className = `badge px-4 py-2 ${status === 'completed' ? 'bg-success' : status === 'pending' ? 'bg-warning text-dark' : 'bg-danger'}`;
                    
             
                    document.getElementById('receiptBtn').href = `receipt.php?id=${id}`;
                });
            });
            
     
            const checkStatusBtns = document.querySelectorAll('.check-status-btn');
            
            checkStatusBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const mpesaCode = this.getAttribute('data-mpesa-code');
                    
                    // Add spinning animation
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    this.disabled = true;
                    
                    // Make AJAX request to check status
                    fetch('mpesa/check-transaction.php?id=' + id)
                        .then(response => response.json())
                        .then(data => {
                            if (data.status) {
                                // Reload page to show updated status
                                window.location.reload();
                            } else {
                                // Show error message
                                alert('Failed to check transaction status: ' + data.message);
                                // Reset button
                                this.innerHTML = '<i class="fas fa-sync-alt"></i>';
                                this.disabled = false;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            // Reset button
                            this.innerHTML = '<i class="fas fa-sync-alt"></i>';
                            this.disabled = false;
                        });
                });
            });
        
            document.getElementById('exportBtn').addEventListener('click', function() {
                const table = document.getElementById('transactionsTable');
                const rows = table.querySelectorAll('tbody tr');
                
                // Create CSV content
                let csv = 'Date,M-Pesa Code,Plan,Amount,Status\n';
                
                rows.forEach(row => {
                    const date = row.cells[0].textContent.trim();
                    const mpesaCode = row.cells[1].textContent.trim();
                    const planCell = row.cells[2];
                    const plan = planCell.querySelector('.fw-bold').textContent.trim();
                    const planType = planCell.querySelector('.badge').textContent.trim();
                    const amount = row.cells[3].textContent.trim();
                    const status = row.cells[4].querySelector('.badge').textContent.trim();
                    
                    csv += `"${date}","${mpesaCode}","${plan} (${planType})","${amount}","${status}"\n`;
                });
                
 
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('hidden', '');
                a.setAttribute('href', url);
                a.setAttribute('download', 'payment_history.csv');
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
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

