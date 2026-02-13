<?php
include '../includes/header.php';

if (!in_array(getUserType(), ['Student', 'Staff'])) {
    redirect('user/dashboard.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Check if created_at column exists in Vehicle table
$has_vehicle_created_at = false;
try {
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Vehicle' AND COLUMN_NAME = 'created_at'");
    $has_vehicle_created_at = ($stmt->rowCount() > 0);
} catch (Exception $e) {
    $has_vehicle_created_at = false;
}

// Get all vehicles (approved and pending) - vehicles inserted via SQL will also appear
try {
    if ($has_vehicle_created_at) {
        $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE user_id = ? ORDER BY 
                               CASE WHEN access_status = 1 THEN 0 ELSE 1 END, 
                               created_at DESC");
    } else {
        // Fallback ordering if created_at doesn't exist
        $stmt = $pdo->prepare("SELECT * FROM Vehicle WHERE user_id = ? ORDER BY 
                               CASE WHEN access_status = 1 THEN 0 ELSE 1 END, 
                               vehicle_id DESC");
    }
    $stmt->execute([$user_id]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
    error_log("Error loading vehicles in add_request.php: " . $e->getMessage());
}

// Get services with prices
// Ensure price column exists
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Service_type' AND COLUMN_NAME = 'price'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE Service_type ADD price DECIMAL(10,2) DEFAULT 0.00");
    }
} catch (Exception $e) {
    // Column might already exist
}

$stmt = $pdo->query("SELECT * FROM Service_type ORDER BY service_name");
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available spots from OPEN garages only
// First, ensure garage_status column exists
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Parking_garage' AND COLUMN_NAME = 'garage_status'");
    $has_status_column = $stmt->rowCount() > 0;
    
    if (!$has_status_column) {
        $pdo->exec("ALTER TABLE Parking_garage ADD garage_status VARCHAR(10) DEFAULT 'Open' CHECK (garage_status IN ('Open', 'Closed'))");
        $pdo->exec("UPDATE Parking_garage SET garage_status = 'Open' WHERE garage_status IS NULL");
    }
} catch (Exception $e) {
    // Column might already exist
}

// Query spots only from open garages (case-insensitive check for spot_status)
try {
    $stmt = $pdo->query("SELECT ps.*, pg.name as garage_name, pg.garage_status
                         FROM Parking_spots ps 
                         JOIN Parking_garage pg ON ps.garage_id = pg.garage_id 
                         WHERE UPPER(ps.spot_status) = 'AVAILABLE' 
                         AND COALESCE(pg.garage_status, 'Open') = 'Open'
                         ORDER BY pg.name, ps.spot_number");
    $spots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log if no spots found
    if (empty($spots)) {
        // Check what's actually in the database
        $debug_stmt = $pdo->query("SELECT 
            ps.spot_id, 
            ps.spot_number, 
            ps.spot_status,
            pg.name as garage_name,
            pg.garage_status,
            pg.garage_id
            FROM Parking_spots ps 
            LEFT JOIN Parking_garage pg ON ps.garage_id = pg.garage_id 
            ORDER BY pg.name, ps.spot_number");
        $all_spots = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("No available spots found. Total spots in DB: " . count($all_spots));
        if (!empty($all_spots)) {
            error_log("Sample spot data: " . json_encode($all_spots[0]));
        }
    }
} catch (Exception $e) {
    $spots = [];
    error_log("Error loading parking spots: " . $e->getMessage());
}

// Handle form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $service_id = $_POST['service_id'];
    $vehicle_id = !empty($_POST['vehicle_id']) ? $_POST['vehicle_id'] : null;
    $spot_id = !empty($_POST['spot_id']) ? $_POST['spot_id'] : null;
    
    // Validate that if a spot is selected, it's from an open garage
    if ($spot_id) {
        // Check if spot exists, is available, and from an open garage
        $stmt = $pdo->prepare("SELECT ps.spot_status, COALESCE(pg.garage_status, 'Open') as garage_status
                               FROM Parking_spots ps 
                               JOIN Parking_garage pg ON ps.garage_id = pg.garage_id 
                               WHERE ps.spot_id = ?");
        $stmt->execute([$spot_id]);
        $spot_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$spot_info) {
            $error = 'Selected parking spot does not exist.';
        } elseif (strtoupper($spot_info['spot_status']) != 'AVAILABLE') {
            $error = 'Selected parking spot is not available.';
        } elseif ($spot_info['garage_status'] == 'Closed') {
            $error = 'Cannot select parking spot from a closed garage. Please select a spot from an open garage.';
        }
    }
    
    // Only proceed if no validation errors
    if (empty($error)) {
        // Ensure receipt_id column exists
        try {
            $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Service_request' AND COLUMN_NAME = 'receipt_id'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE Service_request ADD receipt_id VARCHAR(50) DEFAULT NULL");
            }
        } catch (Exception $e) {
            // Column might already exist
        }
        
        // Ensure receipt_id column exists in Invoice table
        try {
            $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Invoice' AND COLUMN_NAME = 'receipt_id'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE Invoice ADD receipt_id VARCHAR(50) DEFAULT NULL");
            }
        } catch (Exception $e) {
            // Column might already exist
        }
        
        // Set status to 'Pending' so it appears to admin and manager for approval
        $status = 'Pending';
        
        // Get service price for invoice
        $stmt = $pdo->prepare("SELECT price FROM Service_type WHERE service_id = ?");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_price = $service['price'] ?? 0;
        
        $pdo->beginTransaction();
        try {
            // Insert Service_request (receipt_id will be set after invoice is created)
            $stmt = $pdo->prepare("INSERT INTO Service_request (user_id, vehicle_id, service_id, spot_id, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $vehicle_id, $service_id, $spot_id, $status]);
            $new_request_id = $pdo->lastInsertId();
            
            // Create Invoice for the request first
            $stmt = $pdo->prepare("INSERT INTO Invoice (request_id, amount, date_issued, time_issued) 
                                  VALUES (?, ?, CAST(GETDATE() AS DATE), CAST(GETDATE() AS TIME))");
            $stmt->execute([$new_request_id, $service_price]);
            $invoice_id = $pdo->lastInsertId();
            
            // Use invoice_id as receipt_id
            $receipt_id = (string)$invoice_id;
            
            // Update Invoice with receipt_id (same as invoice_id)
            $stmt = $pdo->prepare("UPDATE Invoice SET receipt_id = ? WHERE invoice_id = ?");
            $stmt->execute([$receipt_id, $invoice_id]);
            
            // Update Service_request with receipt_id (same as invoice_id)
            $stmt = $pdo->prepare("UPDATE Service_request SET receipt_id = ? WHERE request_id = ?");
            $stmt->execute([$receipt_id, $new_request_id]);
            
            $pdo->commit();
            $success = 'Request submitted successfully! Receipt ID: <strong>' . $receipt_id . '</strong>. Request ID: <strong>' . $new_request_id . '</strong>. Please wait for admin/manager approval.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to submit request: ' . $e->getMessage();
        }
    }
}
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>📝 New Service Request</h2>
        <p>Request parking permits and car services</p>
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
        <!-- Request Form -->
        <div class="card animate-fadeInUp">
            <h3>➕ Create New Request</h3>
            
            <form method="POST" style="margin-top:20px;">
                <div class="form-group">
                    <label>Service Type *</label>
                    <select name="service_id" id="service_id" required onchange="updateServicePrice()">
                        <option value="">-- Select Service --</option>
                        <?php foreach ($services as $s): ?>
                            <option value="<?= $s['service_id'] ?>" data-price="<?= number_format($s['price'] ?? 0, 2) ?>">
                                <?= htmlspecialchars($s['service_name']) ?> - EGP <?= number_format($s['price'] ?? 0, 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="service_price_display" style="margin-top:10px;padding:10px;background:var(--light-bg);border-radius:5px;display:none;">
                        <strong style="color:var(--primary-blue);">💰 Price: EGP <span id="selected_price">0.00</span></strong>
                        <small style="display:block;color:var(--gray);margin-top:5px;">Payment will be required after approval</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Select Vehicle</label>
                    <select name="vehicle_id" id="vehicle_select">
                        <option value="">-- No Vehicle --</option>
                        <?php if (!empty($vehicles)): ?>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>">
                                    <?= htmlspecialchars($v['license_plate']) ?> - <?= htmlspecialchars($v['model'] ?? 'N/A') ?> 
                                    <?= ($v['color'] ?? '') ? '(' . htmlspecialchars($v['color']) . ')' : '' ?>
                                    <?= $v['access_status'] == 1 ? ' ✓ Approved' : ' (Pending)' ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($vehicles)): ?>
                        <small style="color:var(--gray);display:block;margin-top:5px;">
                            ⚠️ No vehicles found. <a href="<?= url('user/add_vehicle.php') ?>" style="color:var(--primary-blue);">Add a vehicle first</a>
                        </small>
                        <small style="color:var(--gray);display:block;margin-top:5px;">
                            ℹ️ Note: Vehicles need admin/manager approval before they can be used in service requests.
                        </small>
                    <?php else: ?>
                        <small style="color:var(--gray);display:block;margin-top:5px;">
                            ℹ️ Showing <?= count($vehicles) ?> vehicle(s). Only approved vehicles (✓) are recommended for service requests.
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Parking Spot (Optional)</label>
                    <select name="spot_id" id="spot_select">
                        <option value="">-- Select Spot --</option>
                        <?php if (!empty($spots)): ?>
                            <?php 
                            $current_garage = '';
                            foreach ($spots as $sp): 
                                if (isset($sp['garage_name']) && $current_garage != $sp['garage_name']):
                                    if ($current_garage != '') echo '</optgroup>';
                                    $current_garage = $sp['garage_name'];
                                    echo '<optgroup label="' . htmlspecialchars($current_garage) . '">';
                                endif;
                            ?>
                                <option value="<?= isset($sp['spot_id']) ? $sp['spot_id'] : '' ?>">
                                    Spot <?= isset($sp['spot_number']) ? htmlspecialchars($sp['spot_number']) : 'N/A' ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($current_garage != '') echo '</optgroup>'; ?>
                        <?php else: ?>
                            <option value="" disabled>No available spots found</option>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($spots)): ?>
                        <small style="color:var(--orange);display:block;margin-top:5px;">
                            ⚠️ No parking spots available. Please contact administrator or check <a href="<?= url('debug_spots.php') ?>" target="_blank">debug page</a>.
                        </small>
                    <?php else: ?>
                        <small style="color:var(--gray);display:block;margin-top:5px;">
                            ℹ️ Showing <?= count($spots) ?> available spot(s) from open garages.
                        </small>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    📤 Submit Request
                </button>
            </form>
        </div>
        
        <!-- Available Services -->
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>📌 Available Services</h3>
            <ul style="list-style:none;padding:0;margin-top:15px;">
                <?php foreach ($services as $s): ?>
                    <li style="padding:15px;border-bottom:1px solid var(--light-gray);display:flex;align-items:flex-start;gap:12px;">
                        <span style="font-size:1.5rem;">
                            <?php
                            $icons = [
                                'Parking Permit' => '🅿️',
                                'Reserved Spot' => '📍',
                                'Car Wash' => '🚿',
                                'Oil Change' => '🛢️',
                                'Maintenance' => '🔧'
                            ];
                            echo $icons[$s['service_name']] ?? '📋';
                            ?>
                        </span>
                        <div>
                            <strong style="color:var(--dark-blue);"><?= htmlspecialchars($s['service_name']) ?></strong>
                            <p style="font-size:0.9rem;color:var(--gray);margin-top:3px;">
                                <?= htmlspecialchars($s['description'] ?? 'No description available') ?>
                            </p>
                            <p style="font-size:1rem;color:var(--primary-blue);font-weight:600;margin-top:5px;">
                                💰 EGP <?= number_format($s['price'] ?? 0, 2) ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <!-- Parking Info -->
        <div class="card card-gold animate-fadeInUp delay-2">
            <h3>🅿️ Available Spots</h3>
            <div class="stats-grid" style="margin-top:15px;">
                <?php
                $garages = [];
                foreach ($spots as $sp) {
                    if (!isset($garages[$sp['garage_name']])) {
                        $garages[$sp['garage_name']] = 0;
                    }
                    $garages[$sp['garage_name']]++;
                }
                foreach ($garages as $name => $count):
                ?>
                <div class="stat-item">
                    <div class="number"><?= $count ?></div>
                    <div class="label"><?= htmlspecialchars($name) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updateServicePrice() {
    const select = document.getElementById('service_id');
    const priceDisplay = document.getElementById('service_price_display');
    const priceSpan = document.getElementById('selected_price');
    
    if (select.value) {
        const selectedOption = select.options[select.selectedIndex];
        const price = selectedOption.getAttribute('data-price');
        priceSpan.textContent = price;
        priceDisplay.style.display = 'block';
    } else {
        priceDisplay.style.display = 'none';
    }
}
</script>

<?php include '../includes/footer.php'; ?>