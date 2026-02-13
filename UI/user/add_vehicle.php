<?php
include '../includes/header.php';

if (!in_array(getUserType(), ['Student', 'Staff'])) {
    redirect('user/dashboard.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle delete
if (isset($_GET['delete'])) {
    $vehicle_id = (int)$_GET['delete'];
    
    // Check if vehicle is used in any service requests
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Service_request WHERE vehicle_id = ?");
    $stmt->execute([$vehicle_id]);
    $request_count = $stmt->fetchColumn();
    
    if ($request_count > 0) {
        $error = 'Cannot delete vehicle: It is associated with ' . $request_count . ' service request(s). Please contact admin.';
    } else {
        $stmt = $pdo->prepare("DELETE FROM Vehicle WHERE vehicle_id = ? AND user_id = ?");
        if ($stmt->execute([$vehicle_id, $user_id])) {
            if ($stmt->rowCount() > 0) {
                $success = 'Vehicle deleted successfully!';
            } else {
                $error = 'Vehicle not found or you do not have permission to delete it.';
            }
        } else {
            $error = 'Failed to delete vehicle.';
        }
    }
}

// Handle update
$edit_vehicle_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_vehicle = null;
if ($edit_vehicle_id) {
    $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE vehicle_id = ? AND user_id = ?");
    $stmt->execute([$edit_vehicle_id, $user_id]);
    $edit_vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$edit_vehicle) {
        $edit_vehicle_id = 0;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $license_plate = trim($_POST['license_plate'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $model = trim($_POST['model'] ?? '');
    
    // Validate required fields
    if (empty($license_plate) || empty($color) || empty($model)) {
        $error = 'Please fill in all required fields.';
    } elseif (isset($_POST['update_vehicle'])) {
        // Handle update
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        
        if ($vehicle_id <= 0) {
            $error = 'Invalid vehicle ID.';
        } else {
            // Check if license plate is already taken by another vehicle
            $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE license_plate = ? AND vehicle_id != ?");
            $stmt->execute([$license_plate, $vehicle_id]);
            
            if ($stmt->rowCount() > 0) {
                $error = 'This license plate is already registered to another vehicle.';
            } else {
                $stmt = $pdo->prepare("UPDATE Vehicle SET license_plate = ?, color = ?, model = ? WHERE vehicle_id = ? AND user_id = ?");
                if ($stmt->execute([$license_plate, $color, $model, $vehicle_id, $user_id])) {
                    if ($stmt->rowCount() > 0) {
                        $success = 'Vehicle updated successfully!';
                        $edit_vehicle_id = 0; // Reset edit mode
                        $edit_vehicle = null;
                    } else {
                        $error = 'Vehicle not found or you do not have permission to update it.';
                    }
                } else {
                    $error = 'Failed to update vehicle.';
                }
            }
        }
    } else {
        // Handle add
        $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE license_plate = ?");
        $stmt->execute([$license_plate]);
        
        if ($stmt->rowCount() > 0) {
            $error = 'This license plate is already registered.';
        } else {
            // Check if there's already a pending registration request for this license plate
            try {
                $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'Vehicle_registration_request'");
                $table_exists = $stmt->rowCount() > 0;
                
                if (!$table_exists) {
                    // Create Vehicle_registration_request table if it doesn't exist
                    $pdo->exec("CREATE TABLE Vehicle_registration_request (
                        request_id INT IDENTITY(1,1) PRIMARY KEY,
                        user_id INT NOT NULL,
                        license_plate VARCHAR(20) NOT NULL,
                        color VARCHAR(30),
                        model VARCHAR(50),
                        status VARCHAR(30) DEFAULT 'Pending',
                        approved_by INT,
                        created_at DATETIME DEFAULT GETDATE(),
                        completed_at DATETIME,
                        FOREIGN KEY (user_id) REFERENCES Users(user_id),
                        FOREIGN KEY (approved_by) REFERENCES Users(user_id)
                    )");
                }
            } catch (Exception $e) {
                // Table might already exist
            }
            
            // Check for existing pending request
            $stmt = $pdo->prepare("SELECT * FROM Vehicle_registration_request WHERE license_plate = ? AND status = 'Pending'");
            $stmt->execute([$license_plate]);
            
            if ($stmt->rowCount() > 0) {
                $error = 'You already have a pending registration request for this license plate.';
            } else {
                // Create vehicle registration request instead of inserting directly
                $stmt = $pdo->prepare("INSERT INTO Vehicle_registration_request (user_id, license_plate, color, model, status) VALUES (?, ?, ?, ?, 'Pending')");
                if ($stmt->execute([$user_id, $license_plate, $color, $model])) {
                    $success = 'Vehicle registration request submitted! Waiting for admin/manager approval.';
                } else {
                    $error = 'Failed to submit vehicle registration request.';
                }
            }
        }
    }
}

// Get all vehicles for the user (approved and pending/manually inserted)
$stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$all_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate approved vehicles from pending/manually inserted ones
$vehicles = array_filter($all_vehicles, fn($v) => $v['access_status'] == 1);
$pending_vehicles = array_filter($all_vehicles, fn($v) => $v['access_status'] == 0 || $v['access_status'] === null);
$vehicles = array_values($vehicles); // Re-index array
$pending_vehicles = array_values($pending_vehicles); // Re-index array

// Get pending vehicle registration requests
try {
    $stmt = $pdo->prepare("SELECT vrr.*, 'Pending Registration' as status_display 
                           FROM Vehicle_registration_request vrr
                           WHERE vrr.user_id = ? AND vrr.status = 'Pending' ORDER BY vrr.created_at DESC");
    $stmt->execute([$user_id]);
    $pending_registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_registrations = [];
}
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>🚗 My Vehicles</h2>
        <p>Manage your registered vehicles</p>
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
    
    <div class="cards-container">
        <!-- Add/Edit Vehicle Form -->
        <div class="card animate-fadeInUp">
            <h3><?= $edit_vehicle ? '✏️ Edit Vehicle' : '➕ Add New Vehicle' ?></h3>
            
            <?php if ($edit_vehicle): ?>
                <a href="add_vehicle.php" class="btn btn-secondary btn-sm" style="margin-bottom:15px;">← Cancel Edit</a>
            <?php endif; ?>
            
            <form method="POST" style="margin-top:20px;">
                <?php if ($edit_vehicle): ?>
                    <input type="hidden" name="update_vehicle" value="1">
                    <input type="hidden" name="vehicle_id" value="<?= $edit_vehicle['vehicle_id'] ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>License Plate *</label>
                    <input type="text" name="license_plate" required placeholder="e.g., ABC 1234" 
                           value="<?= htmlspecialchars($edit_vehicle['license_plate'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label>Color *</label>
                    <input type="text" name="color" required placeholder="e.g., White" 
                           value="<?= htmlspecialchars($edit_vehicle['color'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label>Model *</label>
                    <input type="text" name="model" required placeholder="e.g., Toyota Corolla 2022" 
                           value="<?= htmlspecialchars($edit_vehicle['model'] ?? '') ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <?= $edit_vehicle ? '💾 Update Vehicle' : '🚗 Add Vehicle' ?>
                </button>
            </form>
        </div>
        
        <!-- Vehicle Stats -->
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>📊 Vehicle Stats</h3>
            <div class="stats-grid" style="margin-top:20px;">
                <div class="stat-item">
                    <div class="number"><?= count($vehicles) ?></div>
                    <div class="label">Approved</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?= count($pending_vehicles) + count($pending_registrations) ?></div>
                    <div class="label">Pending</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?= count($all_vehicles) + count($pending_registrations) ?></div>
                    <div class="label">Total</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Vehicles Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>🚗 My Registered Vehicles</h3>
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
                        <th>Status</th>
                        <th>Added Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_vehicles) && empty($pending_registrations)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="icon">🚗</div>
                                    <h4>No vehicles yet</h4>
                                    <p>Add your first vehicle to get started</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        // Show pending registration requests first
                        foreach ($pending_registrations as $pr): 
                        ?>
                        <tr style="background:var(--light-bg);">
                            <td><strong><?= htmlspecialchars($pr['license_plate']) ?></strong></td>
                            <td><?= htmlspecialchars($pr['model']) ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:8px;">
                                    <span style="width:15px;height:15px;border-radius:50%;background:<?= strtolower($pr['color']) ?>;border:1px solid #ddd;"></span>
                                    <?= htmlspecialchars($pr['color']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-pending">
                                    ⏳ Pending Approval
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($pr['created_at'])) ?></td>
                            <td>
                                <small style="color:var(--gray);">Waiting for admin/manager approval</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php 
                        // Show pending vehicles (inserted directly via SQL or not approved)
                        foreach ($pending_vehicles as $v): 
                        ?>
                        <tr style="background:var(--light-bg);">
                            <td><strong><?= htmlspecialchars($v['license_plate']) ?></strong></td>
                            <td><?= htmlspecialchars($v['model']) ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:8px;">
                                    <span style="width:15px;height:15px;border-radius:50%;background:<?= strtolower($v['color']) ?>;border:1px solid #ddd;"></span>
                                    <?= htmlspecialchars($v['color']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-pending">
                                    ⏳ Pending Approval
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($v['created_at'])) ?></td>
                            <td>
                                <div class="action-btns">
                                    <a href="?edit=<?= $v['vehicle_id'] ?>" 
                                       class="action-btn action-btn-approve"
                                       data-tooltip="Edit">
                                        ✏️
                                    </a>
                                    <a href="?delete=<?= $v['vehicle_id'] ?>" 
                                       class="action-btn action-btn-reject"
                                       data-confirm="Delete this vehicle?">
                                        🗑️
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php 
                        // Show approved vehicles
                        foreach ($vehicles as $v): 
                        ?>
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
                                <span class="badge badge-approved">
                                    ✅ Approved
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($v['created_at'])) ?></td>
                            <td>
                                <div class="action-btns">
                                    <a href="?edit=<?= $v['vehicle_id'] ?>" 
                                       class="action-btn action-btn-approve"
                                       data-tooltip="Edit">
                                        ✏️
                                    </a>
                                    <a href="?delete=<?= $v['vehicle_id'] ?>" 
                                       class="action-btn action-btn-reject"
                                       data-confirm="Delete this vehicle?">
                                        🗑️
                                    </a>
                                </div>
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