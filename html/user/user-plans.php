<?php
session_start();
require_once '../db_connect.php';
require_once 'wallet/wallet.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php?error=' . urlencode('Please login to access the plans'));
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


$stmt = $conn->prepare("SELECT * FROM plans WHERE status = 'active' ORDER BY price ASC");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group plans by type
$hourlyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'hourly';
});

$dailyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'daily';
});

$monthlyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'monthly';
});

// Handle plan purchase
$purchaseSuccess = false;
$purchaseError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_plan'])) {
    $planId = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : 0;
    

    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ? AND status = 'active'");
    $stmt->execute([$planId]);
    $selectedPlan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$selectedPlan) {
        $purchaseError = 'Invalid plan selected';
    } else {
        // Check wallet balance
        if ($walletDetails['balance'] < $selectedPlan['price']) {
            $purchaseError = 'Insufficient wallet balance. Please top up your wallet.';
        } else {
            // Process payment from wallet
            $result = $wallet->deductAmount($selectedPlan['price'], 'purchase', 'Purchase of ' . $selectedPlan['name'] . ' plan');
            
            if ($result['success']) {
                // Create transaction record
                $stmt = $conn->prepare("INSERT INTO transactions (user_id, plan_id, phone, amount, mpesa_code, status, created_at) 
                                       VALUES (?, ?, ?, ?, ?, 'completed', NOW())");
                $mpesaCode = 'WALLET-' . strtoupper(substr(md5(uniqid()), 0, 8));
                $stmt->execute([$userId, $planId, $user['phone'], $selectedPlan['price'], $mpesaCode]);
                $transactionId = $conn->lastInsertId();
                

                $sessionId = bin2hex(random_bytes(32));
                $expiryTime = new DateTime();
                
                // Set expiry based on plan type
                if ($selectedPlan['type'] == 'hourly') {
                    $expiryTime->modify('+1 hour');
                } elseif ($selectedPlan['type'] == 'daily') {
                    $expiryTime->modify('+1 day');
                } elseif ($selectedPlan['type'] == 'monthly') {
                    $expiryTime->modify('+30 days');
                }
                
                $stmt = $conn->prepare("INSERT INTO sessions (session_id, user_id, phone, transaction_id, plan_id, ip_address, expires_at, created_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$sessionId, $userId, $user['phone'], $transactionId, $planId, $_SERVER['REMOTE_ADDR'], $expiryTime->format('Y-m-d H:i:s')]);
                
                // Mark transaction as used
                $stmt = $conn->prepare("UPDATE transactions SET used = 1, used_at = NOW() WHERE id = ?");
                $stmt->execute([$transactionId]);
                
                $purchaseSuccess = true;
                
                // Refresh wallet details
                $walletDetails = $wallet->getWalletDetails();
            } else {
                $purchaseError = 'Failed to process payment: ' . $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WiFi Plans - Taransvar WiFi Hotspot</title>
		
	<link rel="stylesheet" href="../lib/fontawesome/css/all.min.css">
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../lib/animate/animate.min.css" rel="stylesheet">

    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="wallet/css/wallet.css">
    
    <link rel="stylesheet" href="../online-detector/online-detector.css">
    <style>
        .plan-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }
        
        .plan-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            padding: 15px 20px;
        }
        
        .plan-card .card-body {
            padding: 20px;
        }
        
        .plan-card .card-footer {
            background-color: #fff;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            padding: 15px 20px;
        }
        
        .plan-price {
            font-size: 1.8rem;
            font-weight: 700;
            color: #007bff;
        }
        
        .plan-period {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .plan-feature {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .plan-feature i {
            color: #28a745;
            margin-right: 10px;
            font-size: 1rem;
        }
        
        .nav-pills .nav-link {
            color: #495057;
            background-color: #f8f9fa;
            border-radius: 5px;
            margin-right: 5px;
            font-weight: 600;
            padding: 10px 20px;
        }
        
        .nav-pills .nav-link.active {
            color: #fff;
            background-color: #007bff;
        }
    </style>
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
                            <li><a class="dropdown-item" href="dashboard.php">Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php">Profile Settings</a></li>
                            <li><a class="dropdown-item" href="user-wallet.php">My Wallet</a></li>
                            <li><a class="dropdown-item" href="payment-history.php">Payment History</a></li>
                            <li><a class="dropdown-item" href="usage-history.php">Usage History</a></li>
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
                        <a class="nav-link active" href="user_plans.php">
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
                    <i class="fas fa-list-alt"></i> WiFi Plans
                </h1>
                
                <?php if ($purchaseSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> Plan purchased successfully! You can now connect to the WiFi network.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($purchaseError): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($purchaseError); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Wallet Balance Card -->
                <div class="card mb-4">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Your Wallet Balance</h5>
                            <h3 class="text-primary mb-0">KSh <?php echo number_format($walletDetails['balance'], 2); ?></h3>
                        </div>
                        <a href="user-wallet.php" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i> Top Up Wallet
                        </a>
                    </div>
                </div>
                
                <!-- Plan Tabs -->
                <ul class="nav nav-pills mb-4" id="planTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="hourly-tab" data-bs-toggle="tab" data-bs-target="#hourly" type="button" role="tab">Hourly Plans</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily" type="button" role="tab">Daily Plans</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab">Monthly Plans</button>
                    </li>
                </ul>
                
                <!-- Plan Content -->
                <div class="tab-content" id="planTabsContent">
                    <!-- Hourly Plans -->
                    <div class="tab-pane fade show active" id="hourly" role="tabpanel">
                        <div class="row g-4">
                            <?php foreach ($hourlyPlans as $plan): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card plan-card">
                                        <div class="card-header">
                                            <h3 class="card-title h5 mb-0"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                            <p class="card-subtitle mb-0 text-muted small"><?php echo htmlspecialchars($plan['description']); ?></p>
                                        </div>
                                        <div class="card-body">
                                            <div class="text-center mb-4">
                                                <span class="plan-price">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                                <span class="plan-period">/ hour</span>
                                            </div>
                                            <div class="plan-features">
                                                <div class="plan-feature">
                                                    <i class="fas fa-database"></i>
                                                    <span><?php echo htmlspecialchars($plan['data_limit']); ?> data limit</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-tachometer-alt"></i>
                                                    <span><?php echo htmlspecialchars($plan['speed']); ?> speed</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-laptop"></i>
                                                    <span><?php echo htmlspecialchars($plan['devices']); ?> device<?php echo $plan['devices'] > 1 ? 's' : ''; ?> allowed</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <form action="user_plans.php" method="post">
                                                <input type="hidden" name="purchase_plan" value="1">
                                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="fas fa-shopping-cart me-2"></i> Purchase Plan
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($hourlyPlans)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i> No hourly plans available at the moment. Please check back later.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Daily Plans -->
                    <div class="tab-pane fade" id="daily" role="tabpanel">
                        <div class="row g-4">
                            <?php foreach ($dailyPlans as $plan): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card plan-card">
                                        <div class="card-header">
                                            <h3 class="card-title h5 mb-0"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                            <p class="card-subtitle mb-0 text-muted small"><?php echo htmlspecialchars($plan['description']); ?></p>
                                        </div>
                                        <div class="card-body">
                                            <div class="text-center mb-4">
                                                <span class="plan-price">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                                <span class="plan-period">/ day</span>
                                            </div>
                                            <div class="plan-features">
                                                <div class="plan-feature">
                                                    <i class="fas fa-database"></i>
                                                    <span><?php echo htmlspecialchars($plan['data_limit']); ?> data limit</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-tachometer-alt"></i>
                                                    <span><?php echo htmlspecialchars($plan['speed']); ?> speed</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-laptop"></i>
                                                    <span><?php echo htmlspecialchars($plan['devices']); ?> device<?php echo $plan['devices'] > 1 ? 's' : ''; ?> allowed</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <form action="user_plans.php" method="post">
                                                <input type="hidden" name="purchase_plan" value="1">
                                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="fas fa-shopping-cart me-2"></i> Purchase Plan
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($dailyPlans)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i> No daily plans available at the moment. Please check back later.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Monthly Plans -->
                    <div class="tab-pane fade" id="monthly" role="tabpanel">
                        <div class="row g-4">
                            <?php foreach ($monthlyPlans as $plan): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card plan-card">
                                        <div class="card-header">
                                            <h3 class="card-title h5 mb-0"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                            <p class="card-subtitle mb-0 text-muted small"><?php echo htmlspecialchars($plan['description']); ?></p>
                                        </div>
                                        <div class="card-body">
                                            <div class="text-center mb-4">
                                                <span class="plan-price">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                                <span class="plan-period">/ month</span>
                                            </div>
                                            <div class="plan-features">
                                                <div class="plan-feature">
                                                    <i class="fas fa-database"></i>
                                                    <span><?php echo htmlspecialchars($plan['data_limit']); ?> data limit</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-tachometer-alt"></i>
                                                    <span><?php echo htmlspecialchars($plan['speed']); ?> speed</span>
                                                </div>
                                                <div class="plan-feature">
                                                    <i class="fas fa-laptop"></i>
                                                    <span><?php echo htmlspecialchars($plan['devices']); ?> device<?php echo $plan['devices'] > 1 ? 's' : ''; ?> allowed</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-footer">
                                            <form action="user_plans.php" method="post">
                                                <input type="hidden" name="purchase_plan" value="1">
                                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="fas fa-shopping-cart me-2"></i> Purchase Plan
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($monthlyPlans)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i> No monthly plans available at the moment. Please check back later.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- How It Works Section -->
                <div class="card mt-5">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> How It Works</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <span class="fw-bold">1</span>
                                    </div>
                                    <div>
                                        <h3 class="h5 fw-bold">Purchase a Plan</h3>
                                        <p class="text-muted">Select and purchase a plan that suits your needs using your wallet balance.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <span class="fw-bold">2</span>
                                    </div>
                                    <div>
                                        <h3 class="h5 fw-bold">Connect to WiFi</h3>
                                        <p class="text-muted">Connect to the "Taransvar WiFi" network on your device.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="d-flex">
                                    <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <span class="fw-bold">3</span>
                                    </div>
                                    <div>
                                        <h3 class="h5 fw-bold">Start Browsing</h3>
                                        <p class="text-muted">You're automatically connected! Start browsing the internet immediately.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- FAQ Section -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-question-circle"></i> Frequently Asked Questions</h5>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="faqAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                        How long does it take to activate my plan?
                                    </button>
                                </h2>
                                <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="faqOne" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Your plan is activated immediately after purchase. You can start browsing right away once you connect to the WiFi network.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                        Can I use my plan on multiple devices?
                                    </button>
                                </h2>
                                <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="faqTwo" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Yes, you can use your plan on multiple devices simultaneously depending on the plan you purchase. Each plan specifies the number of devices allowed.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                        What happens if I exceed my data limit?
                                    </button>
                                </h2>
                                <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="faqThree" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        If you exceed your data limit, your connection speed will be reduced. You can purchase a new plan at any time to restore full speed.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour" aria-expanded="false" aria-controls="collapseFour">
                                        How do I top up my wallet?
                                    </button>
                                </h2>
                                <div id="collapseFour" class="accordion-collapse collapse" aria-labelledby="faqFour" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        You can top up your wallet by visiting the Wallet page and following the M-Pesa payment instructions. Once your payment is confirmed, your wallet balance will be updated automatically.
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
    <footer class="footer bg-dark text-white mt-auto">
        <div class="container py-4">
            <div class="row">
                <div class="col-lg-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="dashboard.php" class="text-white">Dashboard</a></li>
                        <li><a href="user-wallet.php" class="text-white">My Wallet</a></li>
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
        

        document.addEventListener('DOMContentLoaded', function() {
     
            const detector = new OnlineDetector({
                checkInterval: 5000,
                timeout: 3000
            });
            

            detector.replaceHeaderBadge('#connection-badge');
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
