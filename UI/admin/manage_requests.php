<?php
include '../includes/header.php';

if (!in_array(getUserType(), ['Admin', 'Manager'])) {
    redirect('user/dashboard.php');
}

$admin_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle vehicle registration request actions
if (isset($_GET['vehicle_request_action']) && isset($_GET['vehicle_request_id'])) {
    $vehicle_request_id = (int)$_GET['vehicle_request_id'];
    $action = $_GET['vehicle_request_action'];
    
    if ($action == 'approve') {
        $pdo->beginTransaction();
        try {
            // Get vehicle registration request details
            $stmt = $pdo->prepare("SELECT * FROM Vehicle_registration_request WHERE request_id = ?");
            $stmt->execute([$vehicle_request_id]);
            $vehicle_request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($vehicle_request) {
                // Insert vehicle into Vehicle table with approved status
                $stmt = $pdo->prepare("INSERT INTO Vehicle (license_plate, color, model, user_id, access_status, approved_by) VALUES (?, ?, ?, ?, 1, ?)");
                $stmt->execute([$vehicle_request['license_plate'], $vehicle_request['color'], $vehicle_request['model'], $vehicle_request['user_id'], $admin_id]);
                
                // Update vehicle registration request status
                $stmt = $pdo->prepare("UPDATE Vehicle_registration_request SET status = 'Approved', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
                $stmt->execute([$admin_id, $vehicle_request_id]);
                
                $pdo->commit();
                $success = 'Vehicle registration approved and vehicle added successfully!';
            } else {
                $pdo->rollBack();
                $error = 'Vehicle registration request not found.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to approve vehicle registration: ' . $e->getMessage();
        }
    } elseif ($action == 'reject') {
        // Delete the vehicle registration request (rejected, so don't add to database)
        $stmt = $pdo->prepare("UPDATE Vehicle_registration_request SET status = 'Rejected', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
        if ($stmt->execute([$admin_id, $vehicle_request_id])) {
            $success = 'Vehicle registration rejected.';
        } else {
            $errorInfo = $stmt->errorInfo();
            $error = 'Failed to reject vehicle registration: ' . ($errorInfo[2] ?? 'Unknown error');
        }
    }
}

// Handle actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($action == 'approve') {
        $pdo->beginTransaction();
        try {
            // Update request
            $stmt = $pdo->prepare("UPDATE Service_request SET status = 'Approved', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
            $stmt->execute([$admin_id, $request_id]);
            
            // Approve vehicle if linked
            $stmt = $pdo->prepare("UPDATE v SET v.access_status = 1, v.approved_by = ? 
                                   FROM Vehicle v 
                                   INNER JOIN Service_request sr ON v.vehicle_id = sr.vehicle_id 
                                   WHERE sr.request_id = ?");
            $stmt->execute([$admin_id, $request_id]);
            
            // Update spot status if selected
            $stmt = $pdo->prepare("UPDATE ps SET ps.spot_status = 'Reserved' 
                                   FROM Parking_spots ps 
                                   INNER JOIN Service_request sr ON ps.spot_id = sr.spot_id 
                                   WHERE sr.request_id = ?");
            $stmt->execute([$request_id]);
            
            $pdo->commit();
            $success = 'Request approved successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to approve request: ' . $e->getMessage();
        }
    } elseif ($action == 'reject') {
        $stmt = $pdo->prepare("UPDATE Service_request SET status = 'Rejected', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
        if ($stmt->execute([$admin_id, $request_id])) {
            $success = 'Request rejected.';
        } else {
            $errorInfo = $stmt->errorInfo();
            $error = 'Failed to reject request: ' . ($errorInfo[2] ?? 'Unknown error');
        }
    }
}

// Get filter
$status_filter = $_GET['status'] ?? 'all';

// Ensure price column exists in Service_type
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Service_type' AND COLUMN_NAME = 'price'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE Service_type ADD price DECIMAL(10,2) DEFAULT 0.00");
    }
} catch (Exception $e) {
    // Column might already exist
}

// Build query - get service requests and vehicle registration requests separately, then combine
try {
    // Get service requests
    $service_sql = "SELECT sr.*, st.service_name, st.price as service_price, u.name as user_name, u.user_type, u.uni_ID,
                    v.license_plate, v.model as vehicle_model, ps.spot_number, pg.name as garage_name,
                    approver.name as approver_name, 'service' as request_type
                    FROM Service_request sr 
                    JOIN Service_type st ON sr.service_id = st.service_id 
                    JOIN Users u ON sr.user_id = u.user_id 
                    LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id 
                    LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id 
                    LEFT JOIN Parking_garage pg ON ps.garage_id = pg.garage_id
                    LEFT JOIN Users approver ON sr.approved_by = approver.user_id";
    
    if ($status_filter != 'all') {
        $service_sql .= " WHERE sr.status = ?";
        $stmt = $pdo->prepare($service_sql);
        $stmt->execute([$status_filter]);
    } else {
        $stmt = $pdo->query($service_sql);
    }
    $service_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get vehicle registration requests
    $vehicle_sql = "SELECT 
                    vrr.request_id,
                    vrr.user_id,
                    NULL as vehicle_id,
                    NULL as service_id,
                    vrr.status,
                    vrr.approved_by,
                    vrr.created_at,
                    vrr.completed_at,
                    NULL as spot_id,
                    NULL as receipt_id,
                    'Vehicle Registration' as service_name,
                    0.00 as service_price,
                    u.name as user_name,
                    u.user_type,
                    u.uni_ID,
                    vrr.license_plate,
                    vrr.model as vehicle_model,
                    NULL as spot_number,
                    NULL as garage_name,
                    approver.name as approver_name,
                    'vehicle_registration' as request_type
                    FROM Vehicle_registration_request vrr
                    JOIN Users u ON vrr.user_id = u.user_id
                    LEFT JOIN Users approver ON vrr.approved_by = approver.user_id";
    
    if ($status_filter != 'all') {
        $vehicle_sql .= " WHERE vrr.status = ?";
        $stmt = $pdo->prepare($vehicle_sql);
        $stmt->execute([$status_filter]);
    } else {
        $stmt = $pdo->query($vehicle_sql);
    }
    $vehicle_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Combine both types of requests
    $requests = array_merge($service_requests, $vehicle_requests);
    
    // Sort by pending first, then by date
    usort($requests, function($a, $b) {
        if ($a['status'] == 'Pending' && $b['status'] != 'Pending') return -1;
        if ($a['status'] != 'Pending' && $b['status'] == 'Pending') return 1;
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
} catch (Exception $e) {
    $requests = [];
    if (empty($error)) {
        $error = 'Error loading requests: ' . $e->getMessage();
    }
}

// Get counts
// Get request counts (service requests + vehicle registration requests)
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM Service_request GROUP BY status");
    $service_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get vehicle registration request counts
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM Vehicle_registration_request GROUP BY status");
        $vehicle_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $vehicle_counts = [];
    }
    
    // Combine counts
    $status_counts = [];
    foreach (['Pending', 'Approved', 'Rejected'] as $status) {
        $status_counts[$status] = ($service_counts[$status] ?? 0) + ($vehicle_counts[$status] ?? 0);
    }
} catch (Exception $e) {
    $status_counts = [];
}
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>⚙️ Manage Requests</h2>
        <p>Review and process parking and service requests</p>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <?= $success ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <span class="alert-icon">❌</span>
            <?= $error ?>
        </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="cards-container">
        <div class="card animate-fadeInUp">
            <h3>⏳ Pending</h3>
            <div class="card-value"><?= $status_counts['Pending'] ?? 0 ?></div>
        </div>
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>✅ Approved</h3>
            <div class="card-value"><?= $status_counts['Approved'] ?? 0 ?></div>
        </div>
        <div class="card card-gold animate-fadeInUp delay-2">
            <h3>❌ Rejected</h3>
            <div class="card-value"><?= $status_counts['Rejected'] ?? 0 ?></div>
        </div>
    </div>
    
    <!-- Requests Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>📋 All Requests</h3>
            <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
                <div class="filter-buttons">
                    <a href="?status=all" class="filter-btn <?= $status_filter == 'all' ? 'active' : '' ?>">All</a>
                    <a href="?status=Pending" class="filter-btn <?= $status_filter == 'Pending' ? 'active' : '' ?>">Pending</a>
                    <a href="?status=Approved" class="filter-btn <?= $status_filter == 'Approved' ? 'active' : '' ?>">Approved</a>
                    <a href="?status=Rejected" class="filter-btn <?= $status_filter == 'Rejected' ? 'active' : '' ?>">Rejected</a>
                </div>
                <div class="table-search">
                    <input type="text" placeholder="Search...">
                </div>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Receipt ID</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Service</th>
                        <th>Price</th>
                        <th>Payment</th>
                        <th>Vehicle</th>
                        <th>Spot</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <div class="icon">📭</div>
                                    <h4>No requests found</h4>
                                    <p>No requests match your filter</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td><strong>#<?= $req['request_id'] ?></strong></td>
                            <td>
                                <?php if (!empty($req['receipt_id'])): ?>
                                    <strong style="color:var(--primary-blue);font-size:0.9rem;"><?= htmlspecialchars($req['receipt_id']) ?></strong>
                                <?php else: ?>
                                    <span style="color:var(--gray);font-size:0.85rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($req['user_name']) ?></strong>
                                    <small style="display:block;color:var(--gray);"><?= htmlspecialchars($req['uni_ID']) ?></small>
                                </div>
                            </td>
                            <td><span class="badge badge-info"><?= $req['user_type'] ?></span></td>
                            <td>
                                <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                    <span class="badge badge-info">🚗 <?= htmlspecialchars($req['service_name']) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($req['service_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                    <span style="color:var(--gray);">-</span>
                                <?php else: ?>
                                    <strong style="color:var(--primary-blue);">EGP <?= number_format($req['service_price'] ?? 0, 2) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                    <span style="color:var(--gray);">-</span>
                                <?php elseif ($req['status'] == 'Approved'): ?>
                                    <?php if (($req['payment_status'] ?? 'Pending') == 'Paid'): ?>
                                        <span class="badge badge-approved" style="font-size:0.85rem;">
                                            ✅ <?= htmlspecialchars($req['payment_method'] ?? 'N/A') ?><br>
                                            <small>EGP <?= number_format($req['payment_amount'] ?? 0, 2) ?></small>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-pending" style="font-size:0.85rem;">
                                            ⏳ Pending Payment
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--gray);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['license_plate']): ?>
                                    <div>
                                        <strong><?= htmlspecialchars($req['license_plate']) ?></strong>
                                        <?php if ($req['vehicle_model']): ?>
                                            <small style="display:block;color:var(--gray);"><?= htmlspecialchars($req['vehicle_model']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--gray);">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['spot_number']): ?>
                                    <div>
                                        <strong><?= htmlspecialchars($req['spot_number']) ?></strong>
                                        <small style="display:block;color:var(--gray);"><?= htmlspecialchars($req['garage_name']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--gray);">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower($req['status']) ?>">
                                    <?= $req['status'] ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <?= date('M d, Y', strtotime($req['created_at'])) ?>
                                    <small style="display:block;color:var(--gray);"><?= date('H:i', strtotime($req['created_at'])) ?></small>
                                </div>
                            </td>
                            <td>
                                <?php if ($req['status'] == 'Pending'): ?>
                                    <div class="action-btns">
                                        <?php if (($req['request_type'] ?? 'service') == 'vehicle_registration'): ?>
                                            <a href="?vehicle_request_action=approve&vehicle_request_id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-approve" 
                                               data-tooltip="Approve"
                                               data-confirm="Approve this vehicle registration? The vehicle will be added to the user's account.">
                                                ✓
                                            </a>
                                            <a href="?vehicle_request_action=reject&vehicle_request_id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-reject"
                                               data-tooltip="Reject"
                                               data-confirm="Reject this vehicle registration? The request will be removed.">
                                                ✗
                                            </a>
                                        <?php else: ?>
                                            <a href="?action=approve&id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-approve" 
                                               data-tooltip="Approve"
                                               data-confirm="Approve this request?">
                                                ✓
                                            </a>
                                            <a href="?action=reject&id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-reject"
                                               data-tooltip="Reject"
                                               data-confirm="Reject this request?">
                                                ✗
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <small style="color:var(--gray);">
                                        By: <?= htmlspecialchars($req['approver_name'] ?? 'System') ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>