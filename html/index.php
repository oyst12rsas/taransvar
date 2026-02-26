<?php

session_start();
print "";
require_once "db_connect.php";
require_once "auth_tara.php";
print "";


$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? $_SESSION['username'] : '';


$stmt = $conn->prepare("SELECT * FROM plans ORDER BY price ASC");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);


$hourlyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'hourly';
});

$dailyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'daily';
});

$monthlyPlans = array_filter($plans, function($plan) {
    return $plan['type'] == 'monthly';
});


?>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taransvar - WiFi Hotspot</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <div class="col-lg-4 col-md-12 text-center text-lg-start">
                <a href="index.php" class="navbar-brand m-0 p-0 logo-container">
                    <img src="img/logo-w.png" alt="Logo" width="240px" height="60px" class="logo">
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
                            <li><a class="dropdown-item" href="websites/cybersecurity/index.html">
                                <i class="fas fa-shield-virus"></i> Cybersecurity Portal
                            </a></li>
                            <li><a class="dropdown-item" href="mental-health.php">
                                <i class="fas fa-heartbeat"></i> Mental Health Hub
                            </a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-cog"></i> Admin Systems
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="wifi-admin.html">
                                <i class="fas fa-wifi"></i> WiFi Hotspot Admin
                            </a></li>
                            <li><a class="dropdown-item" href="cyber-admin.html">
                                <i class="fas fa-lock"></i> Cybersecurity Admin
                            </a></li>
                        </ul>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center system-status">
                    <span class="badge bg-success me-3">
                        <i class="fas fa-circle"></i> Secure Connection
                    </span>
                    <div class="dropdown">
                        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo $loggedIn ? htmlspecialchars($username) : 'Account'; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($loggedIn): ?>
                                <li><a class="dropdown-item" href="dashboard.php">Dashboard</a></li>
                                <li><a class="dropdown-item" href="usage-history.php">Usage History</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="support.html">Support</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

   
    <section class="hero-section bg-primary text-white">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 mb-4">Unified Security & Connectivity Platform</h1>
                    <p class="lead mb-4">Managed by Taransvar - Integrating cybersecurity, mental health, and seamless connectivity</p>
                    <div class="d-grid gap-3 d-md-block">
                        <?php if ($loggedIn): ?>
                            <a href="dashboard.php" class="btn btn-light btn-lg me-md-2">
                                <i class="fas fa-tachometer-alt"></i> My Dashboard
                            </a>
                        <?php else: ?>
                            <a href="#" class="btn btn-light btn-lg me-md-2" data-bs-toggle="modal" data-bs-target="#loginModal">
                                <i class="fas fa-sign-in-alt"></i> Login to WiFi
                            </a>
                        <?php endif; ?>
                        <a href="plans.php" class="btn btn-outline-light btn-lg">
                            <i class="fas fa-list-alt"></i> View Plans
                        </a>
                    </div>
                </div>
                <div class="col-lg-6 mt-5 mt-lg-0">
                    <div class="system-overview-card">
                        <div class="row g-4">
                            <div class="col-6">
                                <div class="status-card bg-dark text-center p-4">
                                    <h3><i class="fas fa-shield-alt"></i> Security</h3>
                                    <div class="status-indicator active"></div>
                                    <span>Protected</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="status-card bg-dark text-center p-4">
                                    <h3><i class="fas fa-wifi"></i> Connectivity</h3>
                                    <div class="status-indicator active"></div>
                                    <span>Available</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    
    <section class="services-section py-5">
        <div class="container">
            <h2 class="section-title text-center mb-5">Our Services</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card h-100 service-card">
                        <div class="card-header bg-primary text-white text-center">
                            <i class="fas fa-wifi fa-3x mb-2"></i>
                            <h3>WiFi Hotspot</h3>
                        </div>
                        <div class="card-body">
                            <p>Connect to our high-speed WiFi network with flexible plans for all your needs.</p>
                            <?php if ($loggedIn): ?>
                                <a href="dashboard.php" class="btn btn-link text-primary">My Dashboard <i class="fas fa-arrow-right"></i></a>
                            <?php else: ?>
                                <a href="#" class="btn btn-link text-primary" data-bs-toggle="modal" data-bs-target="#loginModal">Login Now <i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card h-100 service-card">
                        <div class="card-header bg-success text-white text-center">
                            <i class="fas fa-shield-alt fa-3x mb-2"></i>
                            <h3>Cybersecurity</h3>
                        </div>
                        <div class="card-body">
                            <p>Browse securely with our integrated security features protecting your data.</p>
                            <a href="websites/cybersecurity/index.html" class="btn btn-link text-success">Learn More <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card h-100 service-card">
                        <div class="card-header bg-purple text-white text-center">
                            <i class="fas fa-brain fa-3x mb-2"></i>
                            <h3>Mental Health</h3>
                        </div>
                        <div class="card-body">
                            <p>Access resources for digital wellness and maintaining mental health online.</p>
                            <a href="mental-health.php" class="btn btn-link text-purple">Explore Resources <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
	
	

    <section class="py-5 mt-5">
        <div class="container">
            <div class="text-center mb-5">
                <h1 class="display-5 fw-bold">WiFi Plans & Pricing</h1>
                <p class="lead">Choose the perfect plan for your connectivity needs</p>
            </div>

      
            <ul class="nav nav-pills nav-justified mb-5" id="planTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="hourly-tab" data-bs-toggle="tab" data-bs-target="#hourly" type="button" role="tab">Hourly</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="daily-tab" data-bs-toggle="tab" data-bs-target="#daily" type="button" role="tab">Daily</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab">Monthly</button>
                </li>
            </ul>
            
      
            <div class="tab-content" id="planTabsContent">
           
                <div class="tab-pane fade show active" id="hourly" role="tabpanel">
                    <div class="row g-4">
                        <?php foreach ($hourlyPlans as $plan): ?>
                        <div class="col-md-6">
                            <div class="card h-100 plan-card">
                                <div class="card-header bg-light">
                                    <h3 class="card-title"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="card-subtitle mb-0 text-muted"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-4">
                                        <span class="display-6 fw-bold">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                        <span class="text-muted ms-2">/ hour</span>
                                    </div>
                                    <ul class="list-unstyled mb-4">
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['data_limit']); ?> data limit</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['speed']); ?> speed</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['devices']); ?> device connection<?php echo $plan['devices'] > 1 ? 's' : ''; ?></li>
                                    </ul>
                                </div>
                                <div class="card-footer bg-white">
                                    <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#paymentModal" data-plan="<?php echo htmlspecialchars($plan['name']); ?>" data-price="<?php echo htmlspecialchars($plan['price']); ?>">
                                        <i class="fas fa-shopping-cart me-2"></i> Buy Now
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($hourlyPlans)): ?>
                        <div class="col-12 text-center">
                            <p>No hourly plans available at the moment. Please check back later.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
             
                <div class="tab-pane fade" id="daily" role="tabpanel">
                    <div class="row g-4">
                        <?php foreach ($dailyPlans as $plan): ?>
                        <div class="col-md-6">
                            <div class="card h-100 plan-card">
                                <div class="card-header bg-light">
                                    <h3 class="card-title"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="card-subtitle mb-0 text-muted"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-4">
                                        <span class="display-6 fw-bold">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                        <span class="text-muted ms-2">/ day</span>
                                    </div>
                                    <ul class="list-unstyled mb-4">
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['data_limit']); ?> data limit</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['speed']); ?> speed</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['devices']); ?> device connection<?php echo $plan['devices'] > 1 ? 's' : ''; ?></li>
                                    </ul>
                                </div>
                                <div class="card-footer bg-white">
                                    <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#paymentModal" data-plan="<?php echo htmlspecialchars($plan['name']); ?>" data-price="<?php echo htmlspecialchars($plan['price']); ?>">
                                        <i class="fas fa-shopping-cart me-2"></i> Buy Now
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($dailyPlans)): ?>
                        <div class="col-12 text-center">
                            <p>No daily plans available at the moment. Please check back later.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
           
                <div class="tab-pane fade" id="monthly" role="tabpanel">
                    <div class="row g-4">
                        <?php foreach ($monthlyPlans as $plan): ?>
                        <div class="col-md-6">
                            <div class="card h-100 plan-card">
                                <div class="card-header bg-light">
                                    <h3 class="card-title"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="card-subtitle mb-0 text-muted"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-4">
                                        <span class="display-6 fw-bold">KSh <?php echo htmlspecialchars($plan['price']); ?></span>
                                        <span class="text-muted ms-2">/ month</span>
                                    </div>
                                    <ul class="list-unstyled mb-4">
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['data_limit']); ?> data limit</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['speed']); ?> speed</li>
                                        <li class="mb-2"><i class="fas fa-check text-success me-2"></i> <?php echo htmlspecialchars($plan['devices']); ?> device connection<?php echo $plan['devices'] > 1 ? 's' : ''; ?></li>
                                    </ul>
                                </div>
                                <div class="card-footer bg-white">
                                    <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#paymentModal" data-plan="<?php echo htmlspecialchars($plan['name']); ?>" data-price="<?php echo htmlspecialchars($plan['price']); ?>">
                                        <i class="fas fa-shopping-cart me-2"></i> Buy Now
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($monthlyPlans)): ?>
                        <div class="col-12 text-center">
                            <p>No monthly plans available at the moment. Please check back later.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>




            <div class="bg-light rounded p-4 mt-5">
                <h2 class="h3 mb-4">How to Pay</h2>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="d-flex">
                            <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0">
                                <span class="fw-bold">1</span>
                            </div>
                            <div>
                                <h3 class="h5 fw-bold">Select a Plan</h3>
                                <p class="text-muted">Choose the plan that best suits your needs from the options above.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="d-flex">
                            <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0">
                                <span class="fw-bold">2</span>
                            </div>
                            <div>
                                <h3 class="h5 fw-bold">Pay via M-Pesa</h3>
                                <p class="text-muted">Send the payment to our Paybill Number: <strong>123456</strong><br>
                                Account Number: <strong>WIFI</strong></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="d-flex">
                            <div class="bg-primary text-white rounded-circle p-3 me-3 flex-shrink-0">
                                <span class="fw-bold">3</span>
                            </div>
                            <div>
                                <h3 class="h5 fw-bold">Login with Receipt Code</h3>
                                <p class="text-muted">Use your phone number and the M-Pesa receipt code to login to the WiFi.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
            <div class="bg-white rounded p-4 mt-5 border">
                <h2 class="h3 mb-4">How to Login</h2>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white">
                                <h3 class="h5 mb-0">Quick Login (No Account Required)</h3>
                            </div>
                            <div class="card-body">
                                <ol class="mb-0">
                                    <li class="mb-2">Click on "Login to WiFi" button</li>
                                    <li class="mb-2">Enter your phone number used for payment</li>
                                    <li class="mb-2">Enter the M-Pesa receipt code you received</li>
                                    <li class="mb-2">Click "Connect to WiFi"</li>
                                    <li>You'll be connected immediately!</li>
                                </ol>
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i> Quick login is perfect for one-time use, but you won't have access to usage history.
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <a href="#" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#loginModal">
                                    <i class="fas fa-sign-in-alt me-2"></i> Quick Login
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header bg-success text-white">
                                <h3 class="h5 mb-0">Account Login (Recommended)</h3>
                            </div>
                            <div class="card-body">
                                <ol class="mb-0">
                                    <li class="mb-2">Create an account (one-time setup)</li>
                                    <li class="mb-2">Login with your email and password</li>
                                    <li class="mb-2">Link your M-Pesa payments to your account</li>
                                    <li class="mb-2">Track your usage history</li>
                                    <li>Manage multiple devices and plans</li>
                                </ol>
                                <div class="alert alert-success mt-3">
                                    <i class="fas fa-check-circle me-2"></i> Creating an account gives you access to usage history and makes future logins easier!
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <a href="#" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#registerModal">
                                    <i class="fas fa-user-plus me-2"></i> Create Account
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <a href="#" class="btn btn-lg btn-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                    <i class="fas fa-sign-in-alt me-2"></i> Proceed to Login
                </a>
            </div>
        </div>
    </section>


    <div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Payment Instructions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <h4 id="planName">Basic Hour</h4>
                        <h3 class="text-primary" id="planPrice">KSh 20</h3>
                    </div>
                    
                    <div class="alert alert-info">
                        <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i> M-Pesa Payment Instructions</h5>
                        <ol class="mb-0">
                            <li>Go to M-Pesa on your phone</li>
                            <li>Select "Pay Bill"</li>
                            <li>Enter Business Number: <strong>123456</strong></li>
                            <li>Enter Account Number: <strong>WIFI</strong></li>
                            <li>Enter Amount: <strong id="modalAmount">KSh 20</strong></li>
                            <li>Enter your M-Pesa PIN and confirm</li>
                            <li>You will receive an M-Pesa confirmation message</li>
                            <li>Use the receipt code from the message to login</li>
                        </ol>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">Proceed to Login</a>
                </div>
            </div>
        </div>
    </div>
			

    <!-- Quick Access Section -->
    <section class="quick-access-section py-5 bg-light">
        <div class="container">
            <h2 class="section-title text-center mb-5">Quick Access</h2>
            <div class="row g-4">
                <div class="col-md-6">
                    <?php if ($loggedIn): ?>
                        <a href="dashboard.php" class="text-decoration-none">
                            <div class="card h-100 bg-primary text-white text-center p-5 quick-access-card">
                                <i class="fas fa-tachometer-alt fa-4x mb-3"></i>
                                <h3 class="mb-2">My Dashboard</h3>
                                <p>View your usage, active plans, and account information</p>
                            </div>
                        </a>
                    <?php else: ?>
                        <a href="#" class="text-decoration-none" data-bs-toggle="modal" data-bs-target="#loginModal">
                            <div class="card h-100 bg-primary text-white text-center p-5 quick-access-card">
                                <i class="fas fa-sign-in-alt fa-4x mb-3"></i>
                                <h3 class="mb-2">Login to WiFi</h3>
                                <p>Connect to our network using your phone number and M-Pesa receipt code</p>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="col-md-6">
                    <a href="plans.php" class="text-decoration-none">
                        <div class="card h-100 bg-success text-white text-center p-5 quick-access-card">
                            <i class="fas fa-list-alt fa-4x mb-3"></i>
                            <h3 class="mb-2">View Plans</h3>
                            <p>Explore our available WiFi plans and learn how to make payments</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </section>
	
	

	


    <section class="faq-section py-5">
        <div class="container">
            <h2 class="section-title text-center mb-5">Frequently Asked Questions</h2>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I connect to the WiFi?
                                </button>
                            </h3>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <p>After purchasing a plan, you'll receive an M-Pesa receipt code. Use this code along with your phone number to login through our portal.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    What plans are available?
                                </button>
                            </h3>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <p>We offer various plans ranging from hourly to monthly packages with different data limits. Visit our Plans page to see all options.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    How do I make a payment?
                                </button>
                            </h3>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <p>Payments are made through M-Pesa. Visit our Plans page for the payment instructions and paybill numbers.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h3 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    Is my connection secure?
                                </button>
                            </h3>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    <p>Yes, our network includes integrated security features to protect your browsing and data. Learn more on our Cybersecurity information page.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>





    <footer class="footer bg-dark text-white">
        <div class="container py-5">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h4>Get Instant Support</h4>
                    <a href="https://wa.me/1234567890" class="btn btn-success whatsapp-btn">
                        <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                    </a>
                </div>
                <div class="col-lg-4">
                    <h4>Join Our Community</h4>
                    <div class="social-links">
                        <a href="#" class="btn btn-outline-light">
                            <i class="fab fa-github"></i>
                        </a>
                        <a href="#" class="btn btn-outline-light">
                            <i class="fab fa-facebook"></i>
                        </a>
                        <a href="#" class="btn btn-outline-light">
                            <i class="fab fa-linkedin"></i>
                        </a>
                    </div>
                </div>
                <div class="col-lg-4">
                    <h4>Enterprise Solutions</h4>
                    <p>For organization deployments and custom integrations:</p>
                    <a href="mailto:info@taransvar.no" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Contact Us
                    </a>
                </div>
            </div>
            <div class="row mt-4 pt-4 border-top border-secondary">
                <div class="col-12 text-center">
                    <p>&copy; <script>document.write(new Date().getFullYear())</script> Taransvar. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>

   
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">WiFi Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" id="loginTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="quick-login-tab" data-bs-toggle="tab" data-bs-target="#quick-login" type="button" role="tab">Quick Login</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="account-login-tab" data-bs-toggle="tab" data-bs-target="#account-login" type="button" role="tab">Account Login</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="loginTabsContent">
                     
                        <div class="tab-pane fade show active" id="quick-login" role="tabpanel">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Quick login allows you to connect without creating an account. For usage history and tracking, please register.
                            </div>
                            
                            <form id="quickLoginForm" action="login.php" method="post">
                                <input type="hidden" name="login_type" value="quick">
                                <div class="mb-3">
                                    <label for="phoneNumber" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phoneNumber" name="phone" placeholder="e.g., 0712345678" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="mpesaCode" class="form-label">M-Pesa Receipt Code</label>
                                    <input type="text" class="form-control" id="mpesaCode" name="mpesa_code" placeholder="e.g., PK7HXYZ123" required>
                                    <div class="form-text">Enter the receipt code you received after payment</div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">Connect to WiFi</button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-3">
                                <p class="mb-0">Don't have a plan yet? <a href="plans.php">View available plans</a></p>
                            </div>
                        </div>
                       
                        <div class="tab-pane fade" id="account-login" role="tabpanel">
                            <form id="accountLoginForm" action="login.php" method="post">
                                <input type="hidden" name="login_type" value="account">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="rememberMe" name="remember">
                                    <label class="form-check-label" for="rememberMe">Remember me</label>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">Login</button>
                                </div>
                            </form>
                            
                            <div class="text-center mt-3">
                                <p class="mb-2">Don't have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#registerModal" data-bs-dismiss="modal">Register now</a></p>
                                <p class="mb-0"><a href="#" data-bs-toggle="modal" data-bs-target="#recoverModal" data-bs-dismiss="modal">Forgot password?</a></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create an Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Creating an account allows you to track your usage history and manage your WiFi plans.
                    </div>
                    
                    <form id="registerForm" action="register.php" method="post">
                        <div class="mb-3">
                            <label for="regName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="regName" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="regEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="regEmail" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="regPhone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="regPhone" name="phone" placeholder="e.g., 0712345678" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="regPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="regPassword" name="password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="regConfirmPassword" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="regConfirmPassword" name="confirm_password" required>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="termsCheck" name="terms" required>
                            <label class="form-check-label" for="termsCheck">I agree to the <a href="terms.html" target="_blank">Terms of Service</a></label>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Register</button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-3">
                        <p class="mb-0">Already have an account? <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">Login</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

   
    <div class="modal fade" id="recoverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Recover Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Enter your email address below. We'll send you a link to reset your password.
                    </div>
                    
                    <form id="recoverForm" action="recover.php" method="post">
                        <div class="mb-3">
                            <label for="recoverEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="recoverEmail" name="email" required>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Send Recovery Link</button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-3">
                        <p class="mb-0">Remember your password? <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal" data-bs-dismiss="modal">Login</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const error = urlParams.get('error');
            const success = urlParams.get('success');
            
            if (error) {
                alert(decodeURIComponent(error));
            }
            
            if (success) {
                alert(decodeURIComponent(success));
            }
            
            
            if (urlParams.get('login')) {
                const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
                loginModal.show();
            }
            
            
            if (urlParams.get('register')) {
                const registerModal = new bootstrap.Modal(document.getElementById('registerModal'));
                registerModal.show();
            }
        });
    </script>
</body>
</html>
