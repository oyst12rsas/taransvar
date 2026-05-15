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

// Process form submission
$message = '';
$messageType = '';
$formData = [
    'name' => '',
    'description' => '',
    'type' => 'hourly',
    'price' => '',
    'data_limit' => '',
    'speed' => '',
    'devices' => 1,
    'status' => 'active'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $formData = [
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'type' => $_POST['type'] ?? 'hourly',
        'price' => $_POST['price'] ?? '',
        'data_limit' => trim($_POST['data_limit'] ?? ''),
        'speed' => trim($_POST['speed'] ?? ''),
        'devices' => intval($_POST['devices'] ?? 1),
        'status' => $_POST['status'] ?? 'active'
    ];
    
    // Validate input
    $errors = [];
    
    if (empty($formData['name'])) {
        $errors[] = 'Plan name is required';
    }
    
    if (empty($formData['price']) || !is_numeric($formData['price']) || $formData['price'] <= 0) {
        $errors[] = 'Price must be a positive number';
    }
    
    if (empty($formData['data_limit'])) {
        $errors[] = 'Data limit is required';
    }
    
    if (empty($formData['speed'])) {
        $errors[] = 'Speed is required';
    }
    
    if ($formData['devices'] < 1) {
        $errors[] = 'Number of devices must be at least 1';
    }
    
    // If no errors, create plan
    if (empty($errors)) {
        try {
            // Begin transaction
            $conn->beginTransaction();
            
            // Insert plan
            $stmt = $conn->prepare("INSERT INTO plans (name, description, type, price, data_limit, speed, devices, status, created_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $formData['name'],
                $formData['description'],
                $formData['type'],
                $formData['price'],
                $formData['data_limit'],
                $formData['speed'],
                $formData['devices'],
                $formData['status']
            ]);
            $planId = $conn->lastInsertId();
            
 
            $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                   VALUES (?, 'create', ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                "Created new plan: {$formData['name']} (ID: $planId)",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            // Commit transaction
            $conn->commit();
            
            $message = "Plan created successfully!";
            $messageType = 'success';
            
            // Reset form data
            $formData = [
                'name' => '',
                'description' => '',
                'type' => 'hourly',
                'price' => '',
                'data_limit' => '',
                'speed' => '',
                'devices' => 1,
                'status' => 'active'
            ];
        } catch (PDOException $e) {
            // Rollback transaction on error
            $conn->rollBack();
            $message = "Error creating plan: " . $e->getMessage();
            $messageType = 'danger';
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Plan - Taransvar WiFi Hotspot</title>
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
                        <a class="nav-link active" href="plans.php">
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
                </ul>
                
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($admin['name']); ?>
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
                        <a class="nav-link active" href="plans.php">
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
                        <a class="nav-link active" href="add_plan.php">
                            <i class="fas fa-plus-circle"></i> Add Plan
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
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="container-fluid px-4">
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Add New Plan</h1>
                    <a href="plans.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                        <i class="fas fa-arrow-left fa-sm text-white-50"></i> Back to Plans
                    </a>
                </div>
                
                <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Plan Information</h6>
                    </div>
                    <div class="card-body">
                        <form method="post" action="add_plan.php">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Plan Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="type" class="form-label">Plan Type</label>
                                    <select class="form-select" id="type" name="type">
                                        <option value="hourly" <?php echo $formData['type'] === 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                        <option value="daily" <?php echo $formData['type'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="monthly" <?php echo $formData['type'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($formData['description']); ?></textarea>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="price" class="form-label">Price (KSh)</label>
                                    <input type="number" class="form-control" id="price" name="price" value="<?php echo htmlspecialchars($formData['price']); ?>" min="0" step="0.01" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="data_limit" class="form-label">Data Limit</label>
                                    <input type="text" class="form-control" id="data_limit" name="data_limit" value="<?php echo htmlspecialchars($formData['data_limit']); ?>" placeholder="e.g. 100MB, 1GB" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="speed" class="form-label">Speed</label>
                                    <input type="text" class="form-control" id="speed" name="speed" value="<?php echo htmlspecialchars($formData['speed']); ?>" placeholder="e.g. Standard, High, 1Mbps" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="devices" class="form-label">Number of Devices</label>
                                    <input type="number" class="form-control" id="devices" name="devices" value="<?php echo htmlspecialchars($formData['devices']); ?>" min="1" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo $formData['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $formData['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="reset" class="btn btn-secondary me-md-2">Reset</button>
                                <button type="submit" class="btn btn-primary">Create Plan</button>
                            </div>
                        </form>
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
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-format data limit input
            $('#data_limit').on('blur', function() {
                let value = $(this).val().trim().toUpperCase();
                if (value && !value.match(/[A-Z]/)) {
                    // Add MB if no unit is specified
                    $(this).val(value + 'MB');
                }
            });
            
            // Auto-format speed input
            $('#speed').on('blur', function() {
                let value = $(this).val().trim();
                if (value && value.match(/^\d+$/)) {
                    // Add Mbps if only a number is specified
                    $(this).val(value + ' Mbps');
                }
            });
            
            // Update price placeholder based on plan type
            $('#type').on('change', function() {
                const type = $(this).val();
                let placeholder = '';
                
                switch (type) {
                    case 'hourly':
                        placeholder = 'e.g. 20';
                        break;
                    case 'daily':
                        placeholder = 'e.g. 100';
                        break;
                    case 'monthly':
                        placeholder = 'e.g. 1000';
                        break;
                }
                
                $('#price').attr('placeholder', placeholder);
            });
            
            // Trigger change event to set initial placeholder
            $('#type').trigger('change');
        });
    </script>
</body>
</html>