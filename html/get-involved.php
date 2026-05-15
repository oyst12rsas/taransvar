<?php
session_start();
require_once 'db_connect.php';
require_once 'auth.php';

// Check if user is already logged in
$loggedIn = isset($_SESSION['user_id']);
$username = $loggedIn ? $_SESSION['username'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Involved - Taransvar WiFi Hotspot</title>
	<link rel="stylesheet" href="lib/fontawesome/css/all.min.css">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="online-detector/online-detector.css">
    
    <style>
        .bg-purple {
            background-color: #6f42c1;
        }
        .bg-purple-light {
            background-color: #e2d9f3;
        }
        .text-purple {
            color: #6f42c1 !important;
        }
        .btn-purple {
            background-color: #6f42c1;
            color: white;
        }
        .btn-purple:hover {
            background-color: #5e35b1;
            color: white;
        }
        .btn-outline-purple {
            border-color: #6f42c1;
            color: #6f42c1;
        }
        .btn-outline-purple:hover {
            background-color: #6f42c1;
            color: white;
        }
        .feature-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .involvement-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        .involvement-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }
        .cta-section {
            background-color: #007bff;
            color: white;
            border-radius: 10px;
        }
        .testimonial-card {
            border-left: 4px solid #6f42c1;
        }
        .contact-form .form-control:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 0.25rem rgba(111, 66, 193, 0.25);
        }
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label {
            color: #6f42c1;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
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
                             <li><a class="dropdown-item" href="../websites/cybersecurity/index.html">
                                <i class="fas fa-shield-virus"></i> Cybersecurity & Mental Health 
                            </a></li>
                            
                        </ul>
                    </li>
                    
                </ul>
                
                <div class="d-flex align-items-center system-status">
                    <span class="badge me-3" id="connection-badge">
                        <i class="fas fa-circle"></i> 
                    </span>
                    <div class="dropdown">
                        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo $loggedIn ? htmlspecialchars($username) : 'Account'; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($loggedIn): ?>
                                <li><a class="dropdown-item active" href="user/dashboard.php">Dashboard</a></li>
								<li><a class="dropdown-item" href="user/profile.php">Profile Settings</a></li>
								<li><a class="dropdown-item" href="user/user-wallet.php">My Wallet</a></li>
								<li><a class="dropdown-item" href="user/usage-history.php">Usage History</a></li>
								<li><a class="dropdown-item" href="user/payment-history.php">Payment History</a></li>
								<li><a class="dropdown-item" href="user/user-plans.php">Wifi Plans</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Login</a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#registerModal">Register</a></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section - Adjusted to fix spacing issue -->
    <section class="hero-section bg-purple text-white" style="padding-top: 120px;">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <h1 class="display-4 mb-4 fw-bold">Join Our Mission for a Safer Digital World</h1>
                    <p class="lead mb-4">We're building a global system to secure the Internet and improve mental wellbeing in the digital age. Be part of this transformative effort to create lasting change.</p>
                    <div class="d-grid gap-3 d-md-flex mb-4">
                        <a href="#get-involved" class="btn btn-light btn-lg px-4 py-3">
                            <i class="fas fa-hands-helping me-2"></i> Get Involved
                        </a>
                        <a href="#join-us" class="btn btn-outline-light btn-lg px-4 py-3">
                            <i class="fas fa-envelope me-2"></i> Contact Us
                        </a>
                    </div>
                </div>
                <div class="col-lg-5 mt-5 mt-lg-0">
                    <div class="bg-white p-4 rounded-3 shadow-lg">
                        <div class="d-inline-flex align-items-center justify-content-center bg-purple rounded-circle mb-3" style="width: 100px; height: 100px;">
                            <i class="fas fa-shield-alt fa-3x text-white"></i>
                        </div>
                        <h2 class="h3 text-dark mb-3">Cybersecurity & Mental Health Initiative</h2>
                        <p class="text-muted mb-3">Cybercrime costs businesses hundreds of billions each year. Together, we can create solutions that prioritize long-term security over short-term profits.</p>
                        
						<a href="#join-us" class="btn btn-purple btn-lg px-4 py-3">
                            <i class="fas fa-handshake me-2"></i> Become a Partner
                        </a>
						
						
						
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Our Mission Section -->
    <section class="py-5">
        <div class="container">
            <div class="row justify-content-center mb-5">
                <div class="col-lg-8 text-center">
                    <h2 class="display-5 fw-bold mb-3">Our Mission</h2>
                    <p class="lead text-muted">We're building a global system to secure the Internet while supporting mental wellbeing in an increasingly digital world.</p>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-purple text-white rounded-circle p-3 me-3">
                                    <i class="fas fa-brain fa-2x"></i>
                                </div>
                                <h3 class="h4 mb-0">Mental Health</h3>
                            </div>
                            <p class="card-text">Taransvar is a registered mental health organization focused on helping individuals achieve better mental well-being by fostering focus and overcoming triggers that disrupt progress. We specialize in understanding how the brain's synaptic connections shape behavior and use this knowledge to help rewire destructive habits into positive ones through coaching.</p>
                            <p class="card-text">We're a Norwegian registered NGO aiming to change the way mental treatment is handled globally by aligning it with modern science about how the brain works. Knowledge, memories, experiences, skills, habits and personality all work the same way. All mental problems are also included here.</p>
                            <p class="card-text">We know from sports that the best way to change is by being away from destructive triggers, fully focus about learning better ways to respond and being part of a good team. That way, we can get good response warmed up and get the destructive parts of life rusty.</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-4">
                                <div class="bg-primary text-white rounded-circle p-3 me-3">
                                    <i class="fas fa-shield-alt fa-2x"></i>
                                </div>
                                <h3 class="h4 mb-0">Cybersecurity</h3>
                            </div>
                            <p class="card-text">One skill we'll be providing opportunities in is cyber security. We're building a global system to secure the Internet and are looking for partners who value a safer digital world and want to be part of this transformative effort.</p>
                            <p class="card-text">Becoming an addict is a process of learning. Rehabilitating is about getting rusty and relapsing is about warming up an old skill. We use the same brain when we play football as when we're into addiction or depression. It shouldn't be a surprise that it works the same way.</p>
                            <p class="card-text">Cybercrime costs businesses hundreds of billions each year, yet it can be controlled if we prioritize long-term solutions over short-term profits. We seek collaboration from IT security experts, telecom companies, major corporations, governments, and universities.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- The Problem Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <img src="img/cyber.jpg?height=400&width=600" alt="Cybersecurity Challenges" class="img-fluid rounded-3 shadow">
                </div>
                <div class="col-lg-6">
                    <h2 class="display-6 fw-bold mb-4">The Challenge We Face</h2>
                    <p class="lead mb-4">Cybercrime costs businesses hundreds of billions each year, yet it can be controlled if we prioritize long-term solutions over short-term profits.</p>
                    
                    <div class="d-flex mb-3">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-2 me-3">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <h4 class="h5 mb-1">Growing Threats</h4>
                            <p class="text-muted mb-0">Cyber attacks are becoming more sophisticated and frequent, targeting individuals and organizations alike.</p>
                        </div>
                    </div>
                    
                    <div class="d-flex mb-3">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-2 me-3">
                            <i class="fas fa-heart-broken"></i>
                        </div>
                        <div>
                            <h4 class="h5 mb-1">Mental Health Impact</h4>
                            <p class="text-muted mb-0">Digital overload and security concerns contribute to anxiety, stress, and other mental health challenges.</p>
                        </div>
                    </div>
                    
                    <div class="d-flex">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-2 me-3">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div>
                            <h4 class="h5 mb-1">Fragmented Approach</h4>
                            <p class="text-muted mb-0">Current solutions are often siloed, lacking the global coordination needed for effective protection.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Get Involved Section - Fixed display issues -->
    <section id="get-involved" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">How You Can Get Involved</h2>
                <p class="lead text-muted mx-auto" style="max-width: 800px;">We're seeking collaboration from various sectors to build a safer digital world</p>
            </div>
            
            <div class="row g-4 justify-content-center">
                <div class="col-md-4">
                    <div class="card involvement-card border-0 shadow-sm h-100">
                        <div class="card-body p-4 text-center d-flex flex-column">
                            <div class="feature-icon bg-primary bg-opacity-10 mx-auto">
                                <i class="fas fa-laptop-code text-primary fa-2x"></i>
                            </div>
                            <h3 class="h4 my-3">IT Security Experts</h3>
                            <p class="mb-4">Contribute your expertise to develop cutting-edge security solutions and protocols that can be implemented globally.</p>
                            <a href="#join-us" class="btn btn-outline-primary mt-auto">Join as an Expert</a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card involvement-card border-0 shadow-sm h-100">
                        <div class="card-body p-4 text-center d-flex flex-column">
                            <div class="feature-icon bg-success bg-opacity-10 mx-auto">
                                <i class="fas fa-building text-success fa-2x"></i>
                            </div>
                            <h3 class="h4 my-3">Corporations & Telecoms</h3>
                            <p class="mb-4">Partner with us to implement secure systems and promote digital wellbeing within your organization and for your customers.</p>
                            <a href="#join-us" class="btn btn-outline-success mt-auto">Become a Partner</a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card involvement-card border-0 shadow-sm h-100">
                        <div class="card-body p-4 text-center d-flex flex-column">
                            <div class="feature-icon bg-purple bg-opacity-10 mx-auto">
                                <i class="fas fa-university text-purple fa-2x"></i>
                            </div>
                            <h3 class="h4 my-3">Governments & Universities</h3>
                            <p class="mb-4">Collaborate on research, policy development, and educational initiatives that promote cybersecurity and digital wellness.</p>
                            <a href="#join-us" class="btn btn-outline-purple mt-auto">Establish Partnership</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-5">
        <div class="container">
            <div class="cta-section p-5">
                <div class="row align-items-center">
                    <div class="col-lg-8 mb-4 mb-lg-0">
                        <h2 class="h1 fw-bold mb-3">Ready to Make a Difference?</h2>
                        <p class="lead mb-0">Join our global initiative to create a safer, healthier digital world for everyone.</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="#join-us" class="btn btn-light btn-lg">Get Started Today</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 fw-bold">What Our Partners Say</h2>
                <p class="lead text-muted">Hear from organizations already working with us</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card testimonial-card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex mb-4">
                                <div class="flex-shrink-0">
                                    <img src="/placeholder.svg?height=60&width=60" alt="Testimonial" class="rounded-circle" width="60" height="60">
                                </div>
                                <div class="ms-3">
                                    <h3 class="h5 mb-1">Sarah </h3>
                                    <p class="text-muted mb-0">CTO</p>
                                </div>
                            </div>
                            <p class="mb-0">"Partnering with Taransvar has transformed our approach to cybersecurity. Their holistic view that connects digital security with mental wellbeing has helped us create a safer environment for our employees and customers."</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card testimonial-card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex mb-4">
                                <div class="flex-shrink-0">
                                    <img src="/placeholder.svg?height=60&width=60" alt="Testimonial" class="rounded-circle" width="60" height="60">
                                </div>
                                <div class="ms-3">
                                    <h3 class="h5 mb-1">Michael</h3>
                                    <p class="text-muted mb-0">Director</p>
                                </div>
                            </div>
                            <p class="mb-0">"The research collaboration with Taransvar has yielded valuable insights into how digital technologies affect mental health. Their commitment to creating solutions that address both security and wellbeing is truly innovative."</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contribution Opportunities Section -->
    <section id="contribution-opportunities" class="py-5 bg-dark text-white">
        <div class="container">
            <div class="text-center mb-5">
                <span class="bg-white text-primary px-3 py-1 rounded-pill d-inline-block mb-3">Contribution Opportunities</span>
                <h2 class="display-5 fw-bold mb-3" style="color: #5EEAD4;">What You Can Contribute</h2>
                <p class="lead mb-4">Taransvar is a non-profit organization. We don't have the capital to develop and launch this in an optimal way. So we are looking for financial partners who are willing to avail the necessary capital in exchange for a fair share of the future profit. Please contact us if you have any suggestion on this.</p>
                <p class="mb-5">We invite partners who recognize the importance of a secure Internet and want to be part of a pioneering team that shapes the future. Here are the areas where your contributions can make a meaningful impact.</p>
            </div>
            
            <div class="row g-4 mb-5">
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-info bg-opacity-25 text-info rounded-circle p-3 me-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-laptop fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-0">Programming skills for developing cybersecurity tools</h3>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-success bg-opacity-25 text-success rounded-circle p-3 me-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-wallet fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-0">Financial support to help scale our programs</h3>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-warning bg-opacity-25 text-warning rounded-circle p-3 me-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-briefcase fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-0">Advisory roles for strategic growth and innovation</h3>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-primary bg-opacity-25 text-primary rounded-circle p-3 me-3" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="h5 mb-0">Volunteering your time for community outreach</h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-center">
                <p class="lead">Join us in creating a legacy that safeguards global communication and engraves your name in the history of cybersecurity.</p>
                <a href="#join-us" class="btn btn-outline-light btn-lg mt-3">
                    <i class="fas fa-handshake me-2"></i> Partner With Us
                </a>
            </div>
        </div>
    </section>

    <!-- Join Us Section - Replaced contact form with join form -->
    <section id="join-us" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="bg-white text-primary px-3 py-1 rounded-pill d-inline-block mb-3">Join Us</span>
                <h2 class="display-5 fw-bold mb-3">Become a Part of Our Movement</h2>
                <p class="text-dark">Select your role and join us in making a positive impact.</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <form id="join-form" action="message.php" method="post">
                        <input type="hidden" name="formType" value="join">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" id="name" name="name" class="form-control text-dark" placeholder="Your Name" required>
                                    <label class="text-dark" for="name">Your Name</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="email" id="email" name="email" class="form-control text-dark" placeholder="Your Email" required>
                                    <label class="text-dark" for="email">Your Email</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select id="role" name="role" class="form-select text-dark" required>
                                        <option value="" disabled selected>Select Your Role</option>
                                        <option value="client">Client</option>
                                        <option value="volunteer">Volunteer</option>
                                        <option value="investor">Investor</option>
                                        <option value="contributor">Programming Contributor</option>
                                    </select>
                                    <label class="text-dark" for="role">Select Your Role</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" id="contact" name="contact" class="form-control text-dark" placeholder="Contact Number" required>
                                    <label class="text-dark" for="contact">Contact Number</label>
                                </div>
                            </div>
                            <div class="col-12 text-center">
                                <button type="submit" class="btn btn-primary rounded-pill py-3 px-5">Join Us Now</button>
                                <span class="status-text" style="display: none;"></span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

  

    <!-- Login Modal -->
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
                        
                        <!-- Account Login Tab -->
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

    <!-- Footer -->
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
                    <a href="admin/admin_dashboard.php" class="btn btn-primary">
                        <i class="fas fa-user-circle"></i> Admin Login Here
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
    <script src="online-detector/online-detector.js"></script>
    <script src="js/script.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Online detector initialization
            const detector = new OnlineDetector({
                checkInterval: 5000,
                timeout: 3000
            });
            
            detector.replaceHeaderBadge('#connection-badge');
        });
    </script>
</body>
</html>

