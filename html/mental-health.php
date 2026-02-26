<?php
session_start();
require_once 'db_connect.php';
require_once 'auth.php';


$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? $_SESSION['username'] : '';


?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mental Health Hub - Taransvar WiFi Hotspot</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .bg-purple {
            background-color: #6f42c1;
        }
        .bg-purple-light {
            background-color: #e2d9f3;
        }
        .text-purple {
            color: #6f42c1;
        }
    </style>
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

 
    <section class="py-5 mt-5">
        <div class="container">
            <div class="text-center mb-5">
                <h1 class="display-5 fw-bold">Mental Health Hub</h1>
                
            </div>

            <div class="bg-purple-light rounded-3 p-5 mb-5">
                <div class="row align-items-center">
                    <div class="col-md-4 text-center mb-4 mb-md-0">
                        <div class="bg-purple text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px;">
                            <i class="fas fa-brain fa-5x"></i>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <h2 class="h2 fw-bold mb-3">Mental Wellness Initiative</h2>
                        <p class="lead mb-4">
                            Taransvar is a registered mental health organization focused on helping individuals achieve better mental well-being by fostering focus and overcoming triggers that disrupt progress. We specialize in understanding how the brain’s synaptic connections shape behavior and use this knowledge to help rewire destructive habits into positive ones through coaching. Whether you work on this journey alone or with support, we provide tools to make it easier and more effective.</p>

						<p class="lead mb-4"> Our work spans two key areas: mental health and cybersecurity. In mental health, we treat challenges as destructive habits that can be reshaped with the right guidance. In cybersecurity, we advocate for stronger online governance and propose innovative solutions, like an NGO-owned global network, to combat cybercrime. At Taransvar, we’re dedicated to improving both mental well-being and digital safety.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="bg-white rounded p-3 shadow-sm d-inline-flex align-items-center">
                                <i class="fas fa-balance-scale text-purple me-2"></i>
                                <span>Cyber Security</span>
                            </div>
                            <div class="bg-white rounded p-3 shadow-sm d-inline-flex align-items-center">
                                <i class="fas fa-heart text-purple me-2"></i>
                                <span>Mental Wellbeing</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            


            <div class="card shadow-sm mb-5">
                <div class="card-body p-5">
                    <h2 class="h2 fw-bold mb-4 text-center">Resources & Support</h2>
                    <div class="row g-4">
                        <div class="col-md-4">
                            <div class="text-center p-4 bg-light rounded-3 h-100">
                                <div class="bg-success bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                                    <i class="fas fa-book text-success fa-2x"></i>
                                </div>
                                <h3 class="h5 fw-bold mb-3">Educational Materials</h3>
                                <p class="text-muted mb-3">Access articles, guides, and research on digital wellness.</p>
                                <button class="btn btn-outline-success btn-sm">Browse Resources</button>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="text-center p-4 bg-light rounded-3 h-100">
                                <div class="bg-primary bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                                    <i class="fas fa-comments text-primary fa-2x"></i>
                                </div>
                                <h3 class="h5 fw-bold mb-3">Community Support</h3>
                                <p class="text-muted mb-3">Connect with others focused on digital wellbeing.</p>
                                <button class="btn btn-outline-primary btn-sm">Join Community</button>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="text-center p-4 bg-light rounded-3 h-100">
                                <div class="bg-danger bg-opacity-25 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                                    <i class="fas fa-phone-alt text-danger fa-2x"></i>
                                </div>
                                <h3 class="h5 fw-bold mb-3">Crisis Support</h3>
                                <p class="text-muted mb-3">Access to mental health helplines and resources.</p>
                                <button class="btn btn-outline-danger btn-sm">Get Help</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <a href="index.php" class="btn btn-outline-secondary me-3" >
                    <i class="fas fa-arrow-left me-2"></i> Back to Home
                </a>
                <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#loginModal">
                    <i class="fas fa-sign-in-alt me-2"></i> Login to WiFi
                </a>
            </div>
        </div>
    </section>
	
	
	

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
                        <!-- Quick Login Tab (Phone + M-Pesa) -->
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
	
	


    <footer class="footer bg-dark text-white mt-5">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>