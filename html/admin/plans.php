<?php
session_start();
require_once '../db_connect.php';

// Check if admin is logged in
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


if (isset($_POST['toggle_status']) && isset($_POST['plan_id'])) {
    $planId = $_POST['plan_id'];
    $newStatus = $_POST['new_status'];
    
    try {
        $stmt = $conn->prepare("UPDATE plans SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $planId]);
        
        // Log the activity
        $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                               VALUES (?, 'update', ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            "Updated plan status: Plan ID $planId set to $newStatus",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $response = ['success' => true, 'message' => 'Plan status updated successfully'];
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => 'Error updating plan status: ' . $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handle plan deletion
if (isset($_POST['delete_plan']) && isset($_POST['plan_id'])) {
    $planId = $_POST['plan_id'];
    
    try {
        // Check if plan is in use
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM sessions WHERE plan_id = ? AND expires_at > NOW()");
        $stmt->execute([$planId]);
        $activeCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($activeCount > 0) {
            $response = ['success' => false, 'message' => 'Cannot delete plan: It is currently in use by active sessions'];
        } else {
            $stmt = $conn->prepare("DELETE FROM plans WHERE id = ?");
            $stmt->execute([$planId]);
            
            // Log the activity
            $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, description, ip_address, created_at) 
                                   VALUES (?, 'delete', ?, ?, NOW())");
            $stmt->execute([
                $adminId,
                "Deleted plan: Plan ID $planId",
                $_SERVER['REMOTE_ADDR']
            ]);
            
            $response = ['success' => true, 'message' => 'Plan deleted successfully'];
        }
    } catch (PDOException $e) {
        $response = ['success' => false, 'message' => 'Error deleting plan: ' . $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get all plans
$stmt = $conn->prepare("SELECT p.*, 
                       (SELECT COUNT(*) FROM sessions s WHERE s.plan_id = p.id AND s.expires_at > NOW()) as active_users,
                       (SELECT COUNT(*) FROM transactions t WHERE t.plan_id = p.id) as total_purchases,
                       (SELECT SUM(amount) FROM transactions t WHERE t.plan_id = p.id AND t.status = 'completed') as total_revenue
                       FROM plans p
                       ORDER BY p.created_at DESC");
$stmt->execute();
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plans - Taransvar WiFi Hotspot</title>
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
                                <a class="dropdown-item active" href="admin_approval.php">
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
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="container-fluid px-4">
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">Plans Management</h1>
                    <a href="add_plan.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
                        <i class="fas fa-plus fa-sm text-white-50"></i> Add New Plan
                    </a>
                </div>
                
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">All Plans</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="plansTable" width="100%" cellspacing="0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Data Limit</th>
                                        <th>Speed</th>
                                        <th>Devices</th>
                                        <th>Active Users</th>
                                        <th>Total Revenue</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($plans) > 0): ?>
                                        <?php foreach ($plans as $plan): ?>
                                        <tr>
                                            <td><?php echo $plan['id']; ?></td>
                                            <td><?php echo htmlspecialchars($plan['name']); ?></td>
                                            <td><?php echo ucfirst($plan['type']); ?></td>
                                            <td>KSh <?php echo number_format($plan['price'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($plan['data_limit']); ?></td>
                                            <td><?php echo htmlspecialchars($plan['speed']); ?></td>
                                            <td><?php echo $plan['devices']; ?></td>
                                            <td><?php echo number_format($plan['active_users']); ?></td>
                                            <td>KSh <?php echo number_format($plan['total_revenue'] ?? 0, 2); ?></td>
                                            <td>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input status-toggle" type="checkbox" 
                                                           data-plan-id="<?php echo $plan['id']; ?>" 
                                                           <?php echo $plan['status'] === 'active' ? 'checked' : ''; ?>>
                                                    <label class="form-check-label">
                                                        <span class="badge bg-<?php echo $plan['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                            <?php echo ucfirst($plan['status']); ?>
                                                        </span>
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="edit_plan.php?id=<?php echo $plan['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-danger delete-plan" data-plan-id="<?php echo $plan['id']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center">No plans found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
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
    
    <!-- Delete Plan Modal -->
    <div class="modal fade" id="deletePlanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this plan? This action cannot be undone.</p>
                    <p class="text-danger">Warning: Deleting a plan will affect all associated transactions and historical data.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../js/bootstrap.bundle.min.js"></script>
    <script src="../js/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Toggle plan status
            $('.status-toggle').on('change', function() {
                const planId = $(this).data('plan-id');
                const isChecked = $(this).prop('checked');
                const newStatus = isChecked ? 'active' : 'inactive';
                const badge = $(this).siblings('label').find('.badge');
                
                $.ajax({
                    url: 'plans.php',
                    type: 'POST',
                    data: {
                        toggle_status: true,
                        plan_id: planId,
                        new_status: newStatus
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            badge.removeClass('bg-success bg-secondary')
                                 .addClass(isChecked ? 'bg-success' : 'bg-secondary')
                                 .text(isChecked ? 'Active' : 'Inactive');
                            
                            // Show success message
                            alert('Plan status updated successfully');
                        } else {
                            // Revert the toggle if there was an error
                            $(this).prop('checked', !isChecked);
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        // Revert the toggle if there was an error
                        $(this).prop('checked', !isChecked);
                        alert('An error occurred while updating the plan status');
                    }
                });
            });
            
            // Delete plan
            let planIdToDelete;
            
            $('.delete-plan').on('click', function() {
                planIdToDelete = $(this).data('plan-id');
                $('#deletePlanModal').modal('show');
            });
            
            $('#confirmDelete').on('click', function() {
                $.ajax({
                    url: 'plans.php',
                    type: 'POST',
                    data: {
                        delete_plan: true,
                        plan_id: planIdToDelete
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#deletePlanModal').modal('hide');
                        
                        if (response.success) {
                            // Remove the row from the table
                            $('tr').filter(function() {
                                return $(this).find('td:first').text() == planIdToDelete;
                            }).remove();
                            
                            alert('Plan deleted successfully');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        $('#deletePlanModal').modal('hide');
                        alert('An error occurred while deleting the plan');
                    }
                });
            });
        });
    </script>
</body>
</html>