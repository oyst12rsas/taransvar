<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access your wallet'));
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

// Handle deposit form submission
$depositMessage = '';
$depositSuccess = false;

if (isset($_POST['deposit_submit'])) {
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $mpesaCode = filter_input(INPUT_POST, 'mpesa_code', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    
    if ($amount && $mpesaCode && $phone) {
        // In a real implementation, this would verify with M-Pesa API
        // For now, we'll simulate a pending deposit that would be confirmed later
        $result = $wallet->addPendingDeposit($amount, $mpesaCode, "M-Pesa deposit from $phone");
        
        if ($result['success']) {

            $confirmResult = $wallet->confirmPendingDeposit($result['transaction_id']);
            
            if ($confirmResult['success']) {
                $depositMessage = 'Deposit successful! Your wallet has been credited.';
                $depositSuccess = true;
                
                // Refresh wallet details
                $walletDetails = $wallet->getWalletDetails();
            } else {
                $depositMessage = 'Error confirming deposit: ' . $confirmResult['message'];
            }
        } else {
            $depositMessage = 'Error processing deposit: ' . $result['message'];
        }
    } else {
        $depositMessage = 'Please fill all required fields with valid values.';
    }
}


$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Get filter parameters
$type = isset($_GET['type']) ? $_GET['type'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Get transactions
$transactions = $wallet->getTransactions($perPage, $offset, $type, $status);


$stmt = $conn->prepare("
    SELECT COUNT(*) as total FROM wallet_transactions 
    WHERE wallet_id = ? " . 
    ($type ? "AND type = ?" : "") . 
    ($status ? "AND status = ?" : "")
);

$params = [$wallet->getWalletId()];
if ($type) $params[] = $type;
if ($status) $params[] = $status;

$stmt->execute($params);
$totalTransactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalTransactions / $perPage);

// Helper function to format transaction amount
function formatTransactionAmount($transaction) {
    $amount = number_format($transaction['amount'], 2);
    
    if ($transaction['type'] == 'deposit' || $transaction['type'] == 'ref  2');
    
    if ($transaction['type'] == 'deposit' || $transaction['type'] == 'refund') {
        return '+KSh ' . $amount;
    } else {
        return '-KSh ' . $amount;
    }	
}


function getTransactionIconClass($type) {
    switch ($type) {
        case 'deposit':
            return 'fa-arrow-down';
        case 'withdrawal':
            return 'fa-arrow-up';
        case 'purchase':
            return 'fa-shopping-cart';
        case 'refund':
            return 'fa-undo';
        default:
            return 'fa-exchange-alt';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - Taransvar WiFi Hotspot</title>
		
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
                    <div class="mini-wallet-balance me-3">
                        <i class="fas fa-wallet"></i>
                        <span>KSh <?php echo number_format($walletDetails['balance'], 2); ?></span>
                    </div>
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
                        <a class="nav-link active" href="user-wallet.php">
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
                    <i class="fas fa-wallet"></i> My Wallet
                </h1>
                
                <?php if ($depositMessage): ?>
                <div class="alert alert-<?php echo $depositSuccess ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                    <?php echo $depositMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <!-- Wallet Balance Card -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="wallet-widget wallet-widget-lg">
                            <div class="wallet-balance">
                                <div class="balance-icon">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div class="balance-info">
                                    <span class="balance-label">Current Balance</span>
                                    <span class="balance-amount">KSh <?php echo number_format($walletDetails['balance'], 2); ?></span>
                                </div>
                            </div>
                            <div class="wallet-actions">
                                <a href="?action=deposit" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-plus"></i> Deposit Funds
                                </a>
                                <a href="user-plans.php" class="btn btn-sm btn-outline-light">
                                    <i class="fas fa-shopping-cart"></i> Buy WiFi Plan
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="row h-100">
                            <div class="col-6">
                                <div class="wallet-stats-card">
                                    <div class="card-body">
                                        <div class="wallet-stats-icon bg-success">
                                            <i class="fas fa-arrow-down"></i>
                                        </div>
                                        <div class="wallet-stats-info">
                                            <h5>Total Deposits</h5>
                                            <h3>KSh <?php echo number_format($walletDetails['total_deposits'] ?? 0, 2); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="wallet-stats-card">
                                    <div class="card-body">
                                        <div class="wallet-stats-icon bg-danger">
                                            <i class="fas fa-shopping-cart"></i>
                                        </div>
                                        <div class="wallet-stats-info">
                                            <h5>Total Spent</h5>
                                            <h3>KSh <?php echo number_format($walletDetails['total_purchases'] ?? 0, 2); ?></h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($_GET['action']) && $_GET['action'] == 'deposit'): ?>
                <!-- Deposit Form -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Deposit Funds</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="deposit-instructions">
                                    <h5><i class="fas fa-info-circle"></i> How to Deposit</h5>
                                    <ol>
                                        <li>Send money to our M-Pesa Till Number: <strong>123456</strong></li>
                                        <li>Enter the M-Pesa confirmation code you receive</li>
                                        <li>Enter the amount you sent</li>
                                        <li>Click "Deposit" to complete the transaction</li>
                                    </ol>
                                    <p class="mb-0"><small>Note: Your wallet will be credited once the payment is verified.</small></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <form class="deposit-form" method="post" action="user-wallet.php">
                                    <div class="mb-3">
                                        <label for="amount" class="form-label">Amount (KSh)</label>
                                        <input type="number" class="form-control" id="amount" name="amount" min="10" step="0.01" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="mpesa_code" class="form-label">M-Pesa Confirmation Code</label>
                                        <input type="text" class="form-control" id="mpesa_code" name="mpesa_code" required>
                                        <div class="form-text">Enter the confirmation code you received from M-Pesa</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" name="deposit_submit" class="btn btn-primary">
                                            <i class="fas fa-check-circle me-2"></i> Deposit
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Transaction History -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Transaction History</h5>
                        <button class="btn btn-sm btn-outline-primary" id="exportBtn">
                            <i class="fas fa-download me-1"></i> Export CSV
                        </button>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <form action="user-wallet.php" method="get" class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label for="type" class="form-label">Transaction Type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">All Types</option>
                                    <option value="deposit" <?php echo $type == 'deposit' ? 'selected' : ''; ?>>Deposits</option>
                                    <option value="withdrawal" <?php echo $type == 'withdrawal' ? 'selected' : ''; ?>>Withdrawals</option>
                                    <option value="purchase" <?php echo $type == 'purchase' ? 'selected' : ''; ?>>Purchases</option>
                                    <option value="refund" <?php echo $type == 'refund' ? 'selected' : ''; ?>>Refunds</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="pending" <?php echo $status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="failed" <?php echo $status == 'failed' ? 'selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <a href="user-wallet.php" class="btn btn-secondary">
                                    <i class="fas fa-redo me-2"></i> Reset
                                </a>
                            </div>
                        </form>
                        
                        <?php if (count($transactions) > 0): ?>
                            <!-- Transactions List -->
                            <div class="transactions-list">
                                <?php foreach ($transactions as $transaction): ?>
                                    <div class="transaction-card">
                                        <div class="card-body d-flex">
                                            <div class="transaction-icon <?php echo $transaction['type']; ?>">
                                                <i class="fas <?php echo getTransactionIconClass($transaction['type']); ?>"></i>
                                            </div>
                                            <div class="transaction-details">
                                                <div class="d-flex justify-content-between">
                                                    <h5 class="mb-1"><?php echo ucfirst($transaction['type']); ?></h5>
                                                    <span class="transaction-amount <?php echo $transaction['type']; ?>">
                                                        <?php echo formatTransactionAmount($transaction); ?>
                                                    </span>
                                                </div>
                                                <p class="transaction-date mb-1">
                                                    <?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?>
                                                </p>
                                                <p class="transaction-description mb-0">
                                                    <?php echo htmlspecialchars($transaction['description'] ?: 'No description'); ?>
                                                    <?php if ($transaction['mpesa_code']): ?>
                                                        <br><small>M-Pesa Code: <?php echo htmlspecialchars($transaction['mpesa_code']); ?></small>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="transaction-status">
                                                <?php if ($transaction['status'] == 'completed'): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php elseif ($transaction['status'] == 'pending'): ?>
                                                    <span class="badge bg-warning text-dark">Pending</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Failed</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Transaction history pagination" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&type=<?php echo $type; ?>&status=<?php echo $status; ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i> No transactions found matching your filters.
                                <?php if ($type || $status): ?>
                                    <a href="user-wallet.php" class="alert-link">Clear filters</a> to see all transactions.
                                <?php else: ?>
                                    <a href="?action=deposit" class="alert-link">Make your first deposit</a> to get started.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
        
        // Export to CSV
        document.getElementById('exportBtn').addEventListener('click', function() {
            // Get current filter parameters
            const type = document.getElementById('type').value;
            const status = document.getElementById('status').value;
            

            window.location.href = `wallet/wallet-export.php?type=${type}&status=${status}`;
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
