<?php
include '../includes/header.php';

if (getUserType() != 'Manager') {
    redirect('user/dashboard.php');
}

$manager_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle garage status toggle
if (isset($_GET['garage_action']) && isset($_GET['garage_id'])) {
    $garage_id = (int)$_GET['garage_id'];
    $action = $_GET['garage_action'];
    
    // Check if garage_status column exists, if not add it
    try {
        $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE Parking_garage ADD garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed'))");
        }
    } catch (Exception $e) {
        // Column might already exist or table doesn't exist
    }
    
    if ($action == 'open') {
        $stmt = $pdo->prepare("UPDATE Parking_garage SET garage_status = 'Open' WHERE garage_id = ?");
        if ($stmt->execute([$garage_id])) {
            $success = 'Garage opened successfully!';
        } else {
            $error = 'Failed to open garage.';
        }
    } elseif ($action == 'close') {
        $stmt = $pdo->prepare("UPDATE Parking_garage SET garage_status = 'Closed' WHERE garage_id = ?");
        if ($stmt->execute([$garage_id])) {
            $success = 'Garage closed successfully!';
        } else {
            $error = 'Failed to close garage.';
        }
    }
}

// Handle request actions (same as admin)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $request_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($action == 'approve') {
        $pdo->beginTransaction();
        try {
            // Update request
            $stmt = $pdo->prepare("UPDATE Service_request SET status = 'Approved', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
            $stmt->execute([$manager_id, $request_id]);
            
            // Approve vehicle if linked
            $stmt = $pdo->prepare("UPDATE v SET v.access_status = 1, v.approved_by = ? 
                                   FROM Vehicle v 
                                   INNER JOIN Service_request sr ON v.vehicle_id = sr.vehicle_id 
                                   WHERE sr.request_id = ?");
            $stmt->execute([$manager_id, $request_id]);
            
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
        if ($stmt->execute([$manager_id, $request_id])) {
            $success = 'Request rejected.';
        } else {
            $errorInfo = $stmt->errorInfo();
            $error = 'Failed to reject request: ' . ($errorInfo[2] ?? 'Unknown error');
        }
    }
}

// Get active tab
$active_tab = $_GET['tab'] ?? 'requests';

// Get request filter
$status_filter = $_GET['status'] ?? 'all';

// Get all garages with status
try {
    // Check for duplicate garage_status columns and fix if needed
    $stmt = $pdo->query("SELECT COLUMN_NAME, COUNT(*) as cnt 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status'
                        GROUP BY COLUMN_NAME");
    $col_check = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if column exists
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status'");
    $has_status_column = $stmt->fetchColumn() > 0;
    
    if (!$has_status_column) {
        // If column doesn't exist, add it
        $pdo->exec("ALTER TABLE Parking_garage ADD garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed'))");
        // Set all existing garages to 'Open' by default
        $pdo->exec("UPDATE Parking_garage SET garage_status = 'Open' WHERE garage_status IS NULL");
    }
    
    // Get all garage info including status
    // Using subquery to handle potential duplicate garage_status columns safely
    $garages_stmt = $pdo->query("SELECT pg.garage_id, 
                                pg.name,
                                ISNULL((SELECT TOP 1 garage_status FROM Parking_garage WHERE garage_id = pg.garage_id), 'Open') as garage_status,
                                COUNT(ps.spot_id) as total_spots,
                                SUM(CASE WHEN ps.spot_status = 'Available' THEN 1 ELSE 0 END) as available_spots,
                                SUM(CASE WHEN ps.spot_status = 'Reserved' THEN 1 ELSE 0 END) as reserved_spots
                                FROM Parking_garage pg
                                LEFT JOIN Parking_spots ps ON pg.garage_id = ps.garage_id
                                GROUP BY pg.garage_id, pg.name
                                ORDER BY pg.name");
    $garages = $garages_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure garage_status is set for all garages
    foreach ($garages as &$garage) {
        if (!isset($garage['garage_status']) || empty($garage['garage_status'])) {
            $garage['garage_status'] = 'Open';
        }
    }
    unset($garage);
} catch (Exception $e) {
    $garages = [];
    if (empty($error)) {
        $error = 'Error loading garages: ' . $e->getMessage() . '. Please check if garage_status column exists multiple times in the table.';
    }
}

// Ensure price column exists in Service_type
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Service_type' AND COLUMN_NAME = 'price'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE Service_type ADD price DECIMAL(10,2) DEFAULT 0.00");
    }
} catch (Exception $e) {
    // Column might already exist
}

// Build requests query - get service requests and vehicle registration requests
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

// Handle vehicle registration request actions (from Vehicle_registration_request table)
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
                $stmt->execute([$vehicle_request['license_plate'], $vehicle_request['color'], $vehicle_request['model'], $vehicle_request['user_id'], $manager_id]);
                
                // Update vehicle registration request status
                $stmt = $pdo->prepare("UPDATE Vehicle_registration_request SET status = 'Approved', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
                $stmt->execute([$manager_id, $vehicle_request_id]);
                
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
        // Update the vehicle registration request status (rejected, so don't add to database)
        $stmt = $pdo->prepare("UPDATE Vehicle_registration_request SET status = 'Rejected', approved_by = ?, completed_at = GETDATE() WHERE request_id = ?");
        if ($stmt->execute([$manager_id, $vehicle_request_id])) {
            $success = 'Vehicle registration rejected.';
        } else {
            $errorInfo = $stmt->errorInfo();
            $error = 'Failed to reject vehicle registration: ' . ($errorInfo[2] ?? 'Unknown error');
        }
    }
}

// Handle pending vehicle actions (from Vehicle table - vehicles inserted via SQL)
if (isset($_GET['vehicle_action']) && isset($_GET['vehicle_id'])) {
    $vehicle_id = (int)$_GET['vehicle_id'];
    $action = $_GET['vehicle_action'];
    
    if ($action == 'approve') {
        // Approve the vehicle by setting access_status = 1
        $stmt = $pdo->prepare("UPDATE Vehicle SET access_status = 1, approved_by = ? WHERE vehicle_id = ?");
        if ($stmt->execute([$manager_id, $vehicle_id])) {
            $success = 'Vehicle approved successfully!';
        } else {
            $errorInfo = $stmt->errorInfo();
            $error = 'Failed to approve vehicle: ' . ($errorInfo[2] ?? 'Unknown error');
        }
    } elseif ($action == 'reject') {
        // Delete the vehicle (reject and remove from database)
        $pdo->beginTransaction();
        try {
            // Check if vehicle has associated service requests
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Service_request WHERE vehicle_id = ?");
            $stmt->execute([$vehicle_id]);
            $request_count = $stmt->fetchColumn();
            
            if ($request_count > 0) {
                $pdo->rollBack();
                $error = 'Cannot delete vehicle: It has ' . $request_count . ' associated service request(s). Please delete the service requests first.';
            } else {
                // Delete Log_record entries for this vehicle
                $stmt = $pdo->prepare("DELETE FROM Log_record WHERE vehicle_id = ?");
                $stmt->execute([$vehicle_id]);
                
                // Delete the vehicle
                $stmt = $pdo->prepare("DELETE FROM Vehicle WHERE vehicle_id = ?");
                $stmt->execute([$vehicle_id]);
                
                $pdo->commit();
                $success = 'Vehicle rejected and deleted successfully.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to reject vehicle: ' . $e->getMessage();
        }
    }
}

// Handle user deletion
if (isset($_GET['delete_user']) && isset($_GET['user_id'])) {
    $delete_user_id = (int)$_GET['user_id'];
    $delete_user_type = $_GET['user_type'] ?? '';
    
    // Prevent deleting self
    if ($delete_user_id == $manager_id) {
        $error = 'You cannot delete your own account.';
    } elseif (!in_array($delete_user_type, ['Student', 'Staff', 'Admin'])) {
        $error = 'You can only delete Students, Staff, or Admins.';
    } else {
        $pdo->beginTransaction();
        try {
            // Delete in correct order to respect foreign key constraints
            // 1. Delete Payment records (references Invoice)
            $stmt = $pdo->prepare("DELETE p FROM Payment p 
                                  INNER JOIN Invoice i ON p.invoice_id = i.invoice_id 
                                  INNER JOIN Service_request sr ON i.request_id = sr.request_id 
                                  WHERE sr.user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 2. Delete Invoice records (references Service_request)
            $stmt = $pdo->prepare("DELETE i FROM Invoice i 
                                  INNER JOIN Service_request sr ON i.request_id = sr.request_id 
                                  WHERE sr.user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 3. Delete Log_record records that reference Service_request
            $stmt = $pdo->prepare("DELETE lr FROM Log_record lr 
                                  INNER JOIN Service_request sr ON lr.request_id = sr.request_id 
                                  WHERE sr.user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 4. Delete Owns records (M:N relationship)
            $stmt = $pdo->prepare("DELETE o FROM Owns o 
                                  INNER JOIN Service_request sr ON o.request_id = sr.request_id 
                                  WHERE sr.user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 5. Update Service_request records approved by the user (set approved_by to NULL)
            $stmt = $pdo->prepare("UPDATE Service_request SET approved_by = NULL WHERE approved_by = ?");
            $stmt->execute([$delete_user_id]);
            
            // 5b. Delete Service_request records (references Vehicle, Users, etc.)
            $stmt = $pdo->prepare("DELETE FROM Service_request WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 5a. Delete Vehicle_registration_request records (references Users as user_id and approved_by)
            try {
                // Delete vehicle registration requests made by the user
                $stmt = $pdo->prepare("DELETE FROM Vehicle_registration_request WHERE user_id = ?");
                $stmt->execute([$delete_user_id]);
                
                // Update vehicle registration requests approved by the user (set approved_by to NULL)
                $stmt = $pdo->prepare("UPDATE Vehicle_registration_request SET approved_by = NULL WHERE approved_by = ?");
                $stmt->execute([$delete_user_id]);
            } catch (Exception $e) {
                // Table might not exist, continue
            }
            
            // 6. Delete Log_record records that reference Vehicle
            $stmt = $pdo->prepare("DELETE lr FROM Log_record lr 
                                  INNER JOIN Vehicle v ON lr.vehicle_id = v.vehicle_id 
                                  WHERE v.user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 6a. Update vehicles approved by the user (set approved_by to NULL to remove foreign key reference)
            $stmt = $pdo->prepare("UPDATE Vehicle SET approved_by = NULL WHERE approved_by = ?");
            $stmt->execute([$delete_user_id]);
            
            // 7. Delete Vehicle records (references Users)
            $stmt = $pdo->prepare("DELETE FROM Vehicle WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 8. Delete Log_record records that reference User
            $stmt = $pdo->prepare("DELETE FROM Log_record WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 9. Delete Reports records
            $stmt = $pdo->prepare("DELETE FROM Reports WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 10. Delete Requests records (M:N relationship)
            $stmt = $pdo->prepare("DELETE FROM Requests WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 11. Delete Controls records (M:N relationship)
            $stmt = $pdo->prepare("DELETE FROM Controls WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            // 12. Finally, delete the User
            $stmt = $pdo->prepare("DELETE FROM Users WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            
            $pdo->commit();
            $success = 'User deleted successfully from the database.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to delete user: ' . $e->getMessage();
        }
    }
}

// Get user filter
$user_filter = $_GET['filter'] ?? 'all';

// Check if created_at column exists, if not add it
try {
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Users' AND COLUMN_NAME = 'created_at'");
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $pdo->exec("ALTER TABLE Users ADD created_at DATETIME DEFAULT GETDATE()");
        // Update existing records to have a default date
        $pdo->exec("UPDATE Users SET created_at = GETDATE() WHERE created_at IS NULL");
    }
} catch (Exception $e) {
    // If we can't add the column, we'll use fallback ordering
}

// Check if created_at column exists for ordering
$has_created_at = false;
try {
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Users' AND COLUMN_NAME = 'created_at'");
    $has_created_at = ($stmt->rowCount() > 0);
} catch (Exception $e) {
    $has_created_at = false;
}

// Build users query with fallback ordering
$order_by = $has_created_at ? 'u.created_at DESC' : 'u.user_id DESC';

try {
    if ($user_filter == 'all') {
        $stmt = $pdo->query("SELECT u.*, 
                             (SELECT COUNT(*) FROM Vehicle WHERE user_id = u.user_id AND access_status = 1) as vehicle_count,
                             (SELECT COUNT(*) FROM Service_request WHERE user_id = u.user_id) as request_count
                             FROM Users u ORDER BY $order_by");
    } else {
        $stmt = $pdo->prepare("SELECT u.*, 
                               (SELECT COUNT(*) FROM Vehicle WHERE user_id = u.user_id AND access_status = 1) as vehicle_count,
                               (SELECT COUNT(*) FROM Service_request WHERE user_id = u.user_id) as request_count
                               FROM Users u WHERE u.user_type = ? ORDER BY $order_by");
        $stmt->execute([$user_filter]);
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $error = 'Error loading users: ' . $e->getMessage();
}

// Get user type counts
try {
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM Users GROUP BY user_type");
    $type_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $total_users = array_sum($type_counts);
} catch (Exception $e) {
    $type_counts = [];
    $total_users = 0;
}

// Get pending vehicles for approval
// Get pending vehicles from Vehicle table (inserted via SQL)
try {
    $stmt = $pdo->query("SELECT v.*, u.name as user_name, u.uni_ID, u.user_type, 'direct_insert' as source
                         FROM Vehicle v 
                         JOIN Users u ON v.user_id = u.user_id 
                         WHERE v.access_status = 0 OR v.access_status IS NULL
                         ORDER BY v.created_at DESC");
    $pending_vehicles_direct = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_vehicles_direct = [];
}

// Get pending vehicle registration requests
try {
    $stmt = $pdo->query("SELECT vrr.*, u.name as user_name, u.uni_ID, u.user_type, 'registration_request' as source,
                         NULL as vehicle_id, NULL as vehicle_approved_by
                         FROM Vehicle_registration_request vrr
                         JOIN Users u ON vrr.user_id = u.user_id
                         WHERE vrr.status = 'Pending'
                         ORDER BY vrr.created_at DESC");
    $pending_vehicles_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_vehicles_requests = [];
}

// Combine both types of pending vehicles
$pending_vehicles = array_merge($pending_vehicles_direct, $pending_vehicles_requests);
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>👔 Manager Dashboard</h2>
        <p>Manage requests, users, and garage operations</p>
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
    
    <!-- Tabs -->
    <div class="tabs-container" style="margin-bottom:30px;border-bottom:2px solid var(--light-gray);">
        <div class="tabs">
            <a href="Manager.php?tab=requests&status=<?= $status_filter ?>" 
               class="tab <?= $active_tab == 'requests' ? 'active' : '' ?>">
                📋 Manage Requests
            </a>
            <a href="Manager.php?tab=garages" 
               class="tab <?= $active_tab == 'garages' ? 'active' : '' ?>">
                🏢 Garage Management
            </a>
            <a href="Manager.php?tab=users&filter=<?= $user_filter ?>" 
               class="tab <?= $active_tab == 'users' ? 'active' : '' ?>">
                👥 User Management
            </a>
            <a href="Manager.php?tab=vehicles" 
               class="tab <?= $active_tab == 'vehicles' ? 'active' : '' ?>">
                🚗 Vehicle Approvals
            </a>
        </div>
    </div>
    
    <?php if ($active_tab == 'requests'): ?>
    
    <!-- Requests Tab -->
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
                    <a href="Manager.php?tab=requests&status=all" class="filter-btn <?= $status_filter == 'all' ? 'active' : '' ?>">All</a>
                    <a href="Manager.php?tab=requests&status=Pending" class="filter-btn <?= $status_filter == 'Pending' ? 'active' : '' ?>">Pending</a>
                    <a href="Manager.php?tab=requests&status=Approved" class="filter-btn <?= $status_filter == 'Approved' ? 'active' : '' ?>">Approved</a>
                    <a href="Manager.php?tab=requests&status=Rejected" class="filter-btn <?= $status_filter == 'Rejected' ? 'active' : '' ?>">Rejected</a>
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
                                            <a href="Manager.php?tab=requests&vehicle_request_action=approve&vehicle_request_id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-approve" 
                                               data-tooltip="Approve"
                                               data-confirm="Approve this vehicle registration? The vehicle will be added to the user's account.">
                                                ✓
                                            </a>
                                            <a href="Manager.php?tab=requests&vehicle_request_action=reject&vehicle_request_id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-reject"
                                               data-tooltip="Reject"
                                               data-confirm="Reject this vehicle registration? The request will be removed.">
                                                ✗
                                            </a>
                                        <?php else: ?>
                                            <a href="Manager.php?tab=requests&action=approve&id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
                                               class="action-btn action-btn-approve" 
                                               data-tooltip="Approve"
                                               data-confirm="Approve this request?">
                                                ✓
                                            </a>
                                            <a href="Manager.php?tab=requests&action=reject&id=<?= $req['request_id'] ?>&status=<?= $status_filter ?>" 
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
    
    <?php elseif ($active_tab == 'garages'): ?>
    
    <!-- Garage Management Tab -->
    <div class="cards-container">
        <?php foreach ($garages as $garage): ?>
        <div class="card animate-fadeInUp" style="position:relative;">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:15px;">
                <div>
                    <h3>🏢 <?= htmlspecialchars($garage['name']) ?></h3>
                    <p style="color:var(--gray);margin-top:5px;">
                        Garage ID: <?= htmlspecialchars($garage['garage_id']) ?>
                    </p>
                </div>
                <?php 
                $garage_status = $garage['garage_status'] ?? 'Open';
                $is_open = ($garage_status == 'Open');
                ?>
                <span class="badge badge-<?= $is_open ? 'approved' : 'rejected' ?>" 
                      style="font-size:0.9rem;padding:8px 15px;">
                    <?= $is_open ? '🟢 Open' : '🔴 Closed' ?>
                </span>
            </div>
            
            <div class="stats-grid" style="margin:20px 0;">
                <div class="stat-item">
                    <div class="number"><?= $garage['total_spots'] ?? 0 ?></div>
                    <div class="label">Total Spots</div>
                </div>
                <div class="stat-item">
                    <div class="number" style="color:var(--primary-teal);"><?= $garage['available_spots'] ?? 0 ?></div>
                    <div class="label">Available</div>
                </div>
                <div class="stat-item">
                    <div class="number" style="color:var(--primary-gold);"><?= $garage['reserved_spots'] ?? 0 ?></div>
                    <div class="label">Reserved</div>
                </div>
            </div>
            
            <div style="display:flex;gap:10px;margin-top:20px;">
                <?php if ($is_open): ?>
                    <a href="Manager.php?tab=garages&garage_action=close&garage_id=<?= $garage['garage_id'] ?>" 
                       class="btn btn-danger btn-sm"
                       data-confirm="Close this garage? All parking operations will be suspended.">
                        🔴 Close Garage
                    </a>
                <?php else: ?>
                    <a href="Manager.php?tab=garages&garage_action=open&garage_id=<?= $garage['garage_id'] ?>" 
                       class="btn btn-success btn-sm"
                       data-confirm="Open this garage? Parking operations will resume.">
                        🟢 Open Garage
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($garages)): ?>
        <div class="card" style="text-align:center;padding:40px;">
            <div class="empty-state">
                <div class="icon">🏢</div>
                <h4>No garages found</h4>
                <p>No parking garages are registered in the system</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php elseif ($active_tab == 'users'): ?>
    
    <!-- Users Tab -->
    <!-- Stats Cards -->
    <div class="cards-container">
        <div class="card animate-fadeInUp">
            <h3>👥 Total Users</h3>
            <div class="card-value"><?= $total_users ?></div>
        </div>
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>🎓 Students</h3>
            <div class="card-value"><?= $type_counts['Student'] ?? 0 ?></div>
        </div>
        <div class="card card-gold animate-fadeInUp delay-2">
            <h3>👨‍💼 Staff</h3>
            <div class="card-value"><?= $type_counts['Staff'] ?? 0 ?></div>
        </div>
        <div class="card animate-fadeInUp delay-3">
            <h3>🔧 Admins</h3>
            <div class="card-value"><?= $type_counts['Admin'] ?? 0 ?></div>
        </div>
        <div class="card animate-fadeInUp delay-4">
            <h3>👔 Managers</h3>
            <div class="card-value"><?= $type_counts['Manager'] ?? 0 ?></div>
        </div>
    </div>
    
    <!-- Users Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>📋 All Users</h3>
            <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
                <div class="filter-buttons">
                    <a href="Manager.php?tab=users&filter=all" class="filter-btn <?= $user_filter == 'all' ? 'active' : '' ?>">All</a>
                    <a href="Manager.php?tab=users&filter=Student" class="filter-btn <?= $user_filter == 'Student' ? 'active' : '' ?>">Students</a>
                    <a href="Manager.php?tab=users&filter=Staff" class="filter-btn <?= $user_filter == 'Staff' ? 'active' : '' ?>">Staff</a>
                    <a href="Manager.php?tab=users&filter=Admin" class="filter-btn <?= $user_filter == 'Admin' ? 'active' : '' ?>">Admins</a>
                    <a href="Manager.php?tab=users&filter=Manager" class="filter-btn <?= $user_filter == 'Manager' ? 'active' : '' ?>">Managers</a>
                </div>
                <div class="table-search">
                    <input type="text" placeholder="Search users...">
                </div>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Type</th>
                        <th>Vehicles</th>
                        <th>Requests</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <div class="icon">👥</div>
                                    <h4>No users found</h4>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['uni_ID']) ?></strong></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary-blue),var(--primary-teal));display:flex;align-items:center;justify-content:center;color:white;font-weight:600;">
                                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                    </div>
                                    <span><?= htmlspecialchars($u['name']) ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($u['phone']) ?></td>
                            <td>
                                <?php
                                $user_type_display = ucfirst($u['user_type'] ?? 'Unknown');
                                $type_classes = [
                                    'Student' => 'badge-info',
                                    'Staff' => 'badge-approved',
                                    'Admin' => 'badge-warning',
                                    'Manager' => 'badge-pending'
                                ];
                                $icons = ['Student' => '🎓', 'Staff' => '👨‍💼', 'Admin' => '🔧', 'Manager' => '👔'];
                                $badge_class = $type_classes[$user_type_display] ?? 'badge-info';
                                $icon = $icons[$user_type_display] ?? '👤';
                                ?>
                                <span class="badge <?= $badge_class ?>">
                                    <?= $icon ?> <?= htmlspecialchars($user_type_display) ?>
                                </span>
                            </td>
                            <td>
                                <span style="background:var(--light-bg);padding:5px 12px;border-radius:15px;font-weight:600;">
                                    🚗 <?= $u['vehicle_count'] ?>
                                </span>
                            </td>
                            <td>
                                <span style="background:var(--light-bg);padding:5px 12px;border-radius:15px;font-weight:600;">
                                    📋 <?= $u['request_count'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $u['access_status'] ? 'badge-approved' : 'badge-rejected' ?>">
                                    <?= $u['access_status'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <?php if (isset($u['created_at']) && !empty($u['created_at'])): ?>
                                    <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                    <small style="display:block;color:var(--gray);">
                                        <?= date('H:i', strtotime($u['created_at'])) ?>
                                    </small>
                                    <?php else: ?>
                                    <span style="color:var(--gray);">N/A</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (in_array($u['user_type'], ['Student', 'Staff', 'Admin']) && $u['user_id'] != $manager_id): ?>
                                    <div class="action-btns">
                                        <a href="Manager.php?tab=users&filter=<?= $user_filter ?>&delete_user=1&user_id=<?= $u['user_id'] ?>&user_type=<?= urlencode($u['user_type']) ?>" 
                                           class="action-btn action-btn-reject"
                                           data-confirm="Delete this user (<?= htmlspecialchars($u['name']) ?>) from the database? This will also delete all their vehicles and requests.">
                                            🗑️
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <small style="color:var(--gray);">-</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php elseif ($active_tab == 'vehicles'): ?>
    
    <!-- Vehicle Approvals Tab -->
    <!-- Stats Cards -->
    <div class="cards-container">
        <div class="card animate-fadeInUp">
            <h3>⏳ Pending Vehicles</h3>
            <div class="card-value"><?= count($pending_vehicles) ?></div>
            <p style="color:var(--gray);">Awaiting approval</p>
        </div>
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>✅ Approved Vehicles</h3>
            <?php
            $stmt = $pdo->query("SELECT COUNT(*) FROM Vehicle WHERE access_status = 1");
            $approved_count = $stmt->fetchColumn();
            ?>
            <div class="card-value"><?= $approved_count ?></div>
            <p style="color:var(--gray);">Total approved</p>
        </div>
    </div>
    
    <!-- Pending Vehicles Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>🚗 Pending Vehicle Registrations</h3>
            <div class="table-search">
                <input type="text" placeholder="Search vehicles...">
            </div>
        </div>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>License Plate</th>
                        <th>Model</th>
                        <th>Color</th>
                        <th>Owner</th>
                        <th>User Type</th>
                        <th>Registered Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_vehicles)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="icon">🚗</div>
                                    <h4>No pending vehicles</h4>
                                    <p>All vehicle registrations have been processed</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pending_vehicles as $v): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($v['license_plate']) ?></strong></td>
                            <td><?= htmlspecialchars($v['model']) ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:8px;">
                                    <span style="width:15px;height:15px;border-radius:50%;background:<?= strtolower($v['color']) ?>;border:1px solid #ddd;"></span>
                                    <?= htmlspecialchars($v['color']) ?>
                                </span>
                            </td>
                            <td>
                                <div>
                                    <strong><?= htmlspecialchars($v['user_name']) ?></strong>
                                    <small style="display:block;color:var(--gray);"><?= htmlspecialchars($v['uni_ID']) ?></small>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= htmlspecialchars($v['user_type']) ?></span>
                                <?php if (($v['source'] ?? '') == 'direct_insert'): ?>
                                    <br><small style="color:var(--gray);font-size:0.75rem;">Inserted via SQL</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <?= date('M d, Y', strtotime($v['created_at'])) ?>
                                    <small style="display:block;color:var(--gray);">
                                        <?= date('H:i', strtotime($v['created_at'])) ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <?php if (($v['source'] ?? '') == 'direct_insert'): ?>
                                        <!-- Direct insert from Vehicle table -->
                                        <a href="Manager.php?tab=vehicles&vehicle_action=approve&vehicle_id=<?= $v['vehicle_id'] ?>" 
                                           class="action-btn action-btn-approve" 
                                           data-tooltip="Approve"
                                           data-confirm="Approve this vehicle?">
                                            ✓
                                        </a>
                                        <a href="Manager.php?tab=vehicles&vehicle_action=reject&vehicle_id=<?= $v['vehicle_id'] ?>" 
                                           class="action-btn action-btn-reject"
                                           data-tooltip="Reject"
                                           data-confirm="Reject and delete this vehicle?">
                                            ✗
                                        </a>
                                    <?php else: ?>
                                        <!-- Vehicle registration request -->
                                        <a href="Manager.php?tab=requests&vehicle_request_action=approve&vehicle_request_id=<?= $v['request_id'] ?>" 
                                           class="action-btn action-btn-approve" 
                                           data-tooltip="Approve"
                                           data-confirm="Approve this vehicle registration request?">
                                            ✓
                                        </a>
                                        <a href="Manager.php?tab=requests&vehicle_request_action=reject&vehicle_request_id=<?= $v['request_id'] ?>" 
                                           class="action-btn action-btn-reject"
                                           data-tooltip="Reject"
                                           data-confirm="Reject this vehicle registration request?">
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

<style>
.tabs-container {
    margin-bottom: 30px;
}

.tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tab {
    padding: 12px 24px;
    background: var(--light-bg);
    border-radius: 8px;
    text-decoration: none;
    color: var(--dark-blue);
    font-weight: 600;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}

.tab:hover {
    background: var(--light-gray);
    color: var(--primary-blue);
}

.tab.active {
    background: var(--primary-blue);
    color: white;
    border-bottom-color: var(--primary-blue);
}
</style>

<?php include '../includes/footer.php'; ?>