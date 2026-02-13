<?php
include '../includes/header.php';

$user_type = getUserType();
$user_id = $_SESSION['user_id'];

// Get data based on user type
if ($user_type == 'Student' || $user_type == 'Staff') {
    // Get all user's vehicles (approved and pending/manually inserted)
    $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Count only approved vehicles for the card display
    $vehicle_count = count(array_filter($vehicles, fn($v) => $v['access_status'] == 1));
    
    // Get user's service requests with payment info
    $stmt = $pdo->prepare("SELECT sr.*, st.service_name, st.price as service_price, v.license_plate, ps.spot_number, 'service' as request_type
                           FROM Service_request sr 
                           JOIN Service_type st ON sr.service_id = st.service_id 
                           LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id 
                           LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id 
                           WHERE sr.user_id = ? ORDER BY sr.created_at DESC");
    $stmt->execute([$user_id]);
    $service_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's vehicle registration requests
    try {
        $stmt = $pdo->prepare("SELECT vrr.*, 'Vehicle Registration' as service_name, 0.00 as service_price, 
                               vrr.license_plate, NULL as spot_number, 'vehicle_registration' as request_type
                               FROM Vehicle_registration_request vrr
                               WHERE vrr.user_id = ? ORDER BY vrr.created_at DESC");
        $stmt->execute([$user_id]);
        $vehicle_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $vehicle_requests = [];
    }
    
    // Combine both types of requests
    $requests = array_merge($service_requests, $vehicle_requests);
    
    // Sort by date (most recent first)
    usort($requests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    // Get top 10
    $requests = array_slice($requests, 0, 10);
    $request_count = count($service_requests) + count($vehicle_requests);
    
    // Count by status (service requests + vehicle registration requests)
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM Service_request WHERE user_id = ? GROUP BY status");
    $stmt->execute([$user_id]);
    $service_status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get vehicle registration request status counts
    try {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM Vehicle_registration_request WHERE user_id = ? GROUP BY status");
        $stmt->execute([$user_id]);
        $vehicle_status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $vehicle_status_counts = [];
    }
    
    // Combine status counts
    $status_counts = [];
    foreach (['Pending', 'Approved', 'Rejected'] as $status) {
        $status_counts[$status] = ($service_status_counts[$status] ?? 0) + ($vehicle_status_counts[$status] ?? 0);
    }
}

if ($user_type == 'Admin' || $user_type == 'Manager') {
    // Get pending requests count (service requests + vehicle registration requests)
    $stmt = $pdo->query("SELECT COUNT(*) FROM Service_request WHERE status = 'Pending'");
    $service_pending = $stmt->fetchColumn();
    
    // Get pending vehicle registration requests
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM Vehicle_registration_request WHERE status = 'Pending'");
        $vehicle_pending = $stmt->fetchColumn();
    } catch (Exception $e) {
        $vehicle_pending = 0;
    }
    
    $pending_count = $service_pending + $vehicle_pending;
    
    // Get approved today
    $stmt = $pdo->query("SELECT COUNT(*) FROM Service_request WHERE status = 'Approved' AND CAST(completed_at AS DATE) = CAST(GETDATE() AS DATE)");
    $approved_today = $stmt->fetchColumn();
    
    // Get total users
    $stmt = $pdo->query("SELECT COUNT(*) FROM Users");
    $total_users = $stmt->fetchColumn();
    
    // Get total approved vehicles only
    $stmt = $pdo->query("SELECT COUNT(*) FROM Vehicle WHERE access_status = 1");
    $total_vehicles = $stmt->fetchColumn();
    
    // Get pending requests (service + vehicle registration)
    $stmt = $pdo->query("SELECT TOP 10 sr.*, st.service_name, u.name as user_name, u.user_type, 
                         v.license_plate, ps.spot_number, 'service' as request_type
                         FROM Service_request sr 
                         JOIN Service_type st ON sr.service_id = st.service_id 
                         JOIN Users u ON sr.user_id = u.user_id 
                         LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id 
                         LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id 
                         WHERE sr.status = 'Pending' ORDER BY sr.created_at DESC");
    $service_pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending vehicle registration requests
    try {
        $stmt = $pdo->query("SELECT TOP 10 vrr.*, 'Vehicle Registration' as service_name, u.name as user_name, u.user_type, 
                             vrr.license_plate, NULL as spot_number, 'vehicle_registration' as request_type
                             FROM Vehicle_registration_request vrr
                             JOIN Users u ON vrr.user_id = u.user_id 
                             WHERE vrr.status = 'Pending' ORDER BY vrr.created_at DESC");
        $vehicle_pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $vehicle_pending_requests = [];
    }
    
    // Combine and sort
    $pending_requests = array_merge($service_pending_requests, $vehicle_pending_requests);
    usort($pending_requests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $pending_requests = array_slice($pending_requests, 0, 10);
}

if ($user_type == 'Manager') {
    // Get user counts by type
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM Users GROUP BY user_type");
    $user_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>Welcome back, <?= htmlspecialchars($_SESSION['user_name']) ?> 👋</h2>
        <p>Here's what's happening with your parking today</p>
        <span class="user-badge">
            <?php 
            $icons = ['Student' => '🎓', 'Staff' => '👨‍💼', 'Admin' => '🔧', 'Manager' => '👔'];
            echo ($icons[$user_type] ?? '👤') . ' ' . $user_type;
            ?>
        </span>
    </div>
    
    <?php if ($user_type == 'Student' || $user_type == 'Staff'): ?>
    
    <!-- Student/Staff Dashboard -->
    <div class="cards-container">
        <div class="card animate-fadeInUp">
            <h3>🚗 My Vehicles</h3>
            <div class="card-value"><?= $vehicle_count ?></div>
            <p style="color:var(--gray);">Registered vehicles</p>
            <a href="<?= url('user/add_vehicle.php') ?>" class="btn btn-gold btn-sm" style="margin-top:15px;">
                ➕ Add Vehicle
            </a>
        </div>
        
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>📋 My Requests</h3>
            <div class="card-value"><?= $request_count ?></div>
            <p style="color:var(--gray);">Total requests</p>
            <a href="<?= url('user/add_request.php') ?>" class="btn btn-success btn-sm" style="margin-top:15px;">
                ➕ New Request
            </a>
        </div>
        
        <div class="card card-gold animate-fadeInUp delay-2">
            <h3>📊 Request Status</h3>
            <div class="stats-grid" style="margin-top:15px;">
                <div class="stat-item">
                    <div class="number"><?= $status_counts['Pending'] ?? 0 ?></div>
                    <div class="label">Pending</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?= $status_counts['Approved'] ?? 0 ?></div>
                    <div class="label">Approved</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Requests Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>📋 Recent Requests</h3>
            <div class="table-search">
                <input type="text" placeholder="Search requests...">
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Vehicle</th>
                        <th>Parking Spot</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="icon">📭</div>
                                    <h4>No requests yet</h4>
                                    <p>Create your first parking request</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                    <strong>🚗 <?= htmlspecialchars($req['service_name']) ?></strong>
                                    <small style="display:block;color:var(--gray);">Vehicle Registration</small>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars($req['service_name']) ?></strong>
                                    <small style="display:block;color:var(--gray);">EGP <?= number_format($req['service_price'] ?? 0, 2) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($req['license_plate'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($req['spot_number'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($req['status']) ?>">
                                    <?= $req['status'] ?>
                                </span>
                                <?php 
                                if (($req['request_type'] ?? 'service') == 'vehicle_registration'): 
                                    // Vehicle registration request - no payment needed
                                elseif ($req['status'] == 'Approved'):
                                    $payment_status = $req['payment_status'] ?? 'Pending';
                                    if ($payment_status != 'Paid'): 
                                ?>
                                    <br><a href="<?= url('user/Payment.php?id=' . $req['request_id']) ?>" 
                                           style="color:var(--primary-blue);font-size:0.85rem;margin-top:5px;display:inline-block;">
                                        💳 Pay Now
                                    </a>
                                <?php elseif ($payment_status == 'Paid'): ?>
                                    <br><small style="color:var(--primary-teal);font-size:0.85rem;">✅ Paid</small>
                                <?php endif; 
                                endif; ?>
                            </td>
                            <td><?= date('M d, Y - H:i', strtotime($req['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($user_type == 'Admin' || $user_type == 'Manager'): ?>
    
    <!-- Admin/Manager Dashboard -->
    <div class="cards-container">
        <div class="card animate-fadeInUp">
            <h3>⏳ Pending Requests</h3>
            <div class="card-value"><?= $pending_count ?></div>
            <p style="color:var(--gray);">Awaiting approval</p>
            <a href="<?= url('admin/manage_requests.php') ?>" class="btn btn-primary btn-sm" style="margin-top:15px;">
                View All →
            </a>
        </div>
        
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>✅ Approved Today</h3>
            <div class="card-value"><?= $approved_today ?></div>
            <p style="color:var(--gray);">Requests approved today</p>
        </div>
        
        <div class="card card-gold animate-fadeInUp delay-2">
            <h3>👥 Total Users</h3>
            <div class="card-value"><?= $total_users ?></div>
            <p style="color:var(--gray);">Registered users</p>
            <?php if ($user_type == 'Manager'): ?>
            <a href="<?= url('manager/view_users.php') ?>" class="btn btn-gold btn-sm" style="margin-top:15px;">
                View Users →
            </a>
            <?php endif; ?>
        </div>
        
        <div class="card animate-fadeInUp delay-3">
            <h3>🚗 Total Vehicles</h3>
            <div class="card-value"><?= $total_vehicles ?></div>
            <p style="color:var(--gray);">Registered vehicles</p>
        </div>
    </div>
    
    <?php if ($user_type == 'Manager'): ?>
    <!-- User Statistics for Manager -->
    <div class="table-container" style="margin-bottom:30px;">
        <h3 style="margin-bottom:20px;color:var(--primary-blue);">👥 User Statistics</h3>
        <div class="stats-grid">
            <?php foreach ($user_counts as $uc): ?>
            <div class="stat-item">
                <div class="number"><?= $uc['count'] ?></div>
                <div class="label"><?= $uc['user_type'] ?>s</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Pending Requests Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>⏳ Pending Requests</h3>
            <div class="table-search">
                <input type="text" placeholder="Search requests...">
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Type</th>
                        <th>Service</th>
                        <th>Vehicle</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_requests)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="icon">✅</div>
                                    <h4>All caught up!</h4>
                                    <p>No pending requests at the moment</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pending_requests as $req): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($req['user_name']) ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= $req['user_type'] ?></span>
                            </td>
                            <td>
                                <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                    <span class="badge badge-info">🚗 <?= htmlspecialchars($req['service_name']) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($req['service_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($req['license_plate'] ?? 'N/A') ?></td>
                            <td><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
                            <td>
                                <div class="action-btns">
                                    <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                        <a href="<?= url('admin/manage_requests.php?vehicle_request_action=approve&vehicle_request_id=' . $req['request_id']) ?>" 
                                           class="action-btn action-btn-approve" 
                                           data-tooltip="Approve"
                                           data-confirm="Approve this vehicle registration? The vehicle will be added to the user's account.">
                                            ✓
                                        </a>
                                        <a href="<?= url('admin/manage_requests.php?vehicle_request_action=reject&vehicle_request_id=' . $req['request_id']) ?>" 
                                           class="action-btn action-btn-reject"
                                           data-tooltip="Reject"
                                           data-confirm="Reject this vehicle registration? The request will be removed.">
                                            ✗
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= url('admin/manage_requests.php?action=approve&id=' . $req['request_id']) ?>" 
                                           class="action-btn action-btn-approve" 
                                           data-tooltip="Approve"
                                           data-confirm="Approve this request?">
                                            ✓
                                        </a>
                                        <a href="<?= url('admin/manage_requests.php?action=reject&id=' . $req['request_id']) ?>" 
                                           class="action-btn action-btn-reject"
                                           data-tooltip="Reject"
                                           data-confirm="Reject this request?">
                                            ✗
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>