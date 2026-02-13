<?php
include '../includes/header.php';

if (!in_array(getUserType(), ['Student', 'Staff'])) {
    redirect('user/dashboard.php');
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Ensure payment columns exist FIRST
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Service_request' AND COLUMN_NAME = 'payment_method'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE Service_request ADD payment_method VARCHAR(20) DEFAULT 'Pending' CHECK (payment_method IN ('Credit', 'Cash', 'Pending'))");
        $pdo->exec("ALTER TABLE Service_request ADD payment_amount DECIMAL(10,2) DEFAULT NULL");
        $pdo->exec("ALTER TABLE Service_request ADD card_number VARCHAR(20) DEFAULT NULL");
        $pdo->exec("ALTER TABLE Service_request ADD card_cvv VARCHAR(4) DEFAULT NULL");
        $pdo->exec("ALTER TABLE Service_request ADD card_expiry VARCHAR(5) DEFAULT NULL");
        $pdo->exec("ALTER TABLE Service_request ADD cardholder_name VARCHAR(100) DEFAULT NULL");
        $pdo->exec("ALTER TABLE Service_request ADD payment_status VARCHAR(20) DEFAULT 'Pending' CHECK (payment_status IN ('Pending', 'Paid', 'Failed'))");
    }
} catch (Exception $e) {
    // Columns might already exist
}

// Get request_id from URL
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$request_id) {
    redirect('user/dashboard.php');
}

// Get request details (including payment columns)
$stmt = $pdo->prepare("SELECT sr.*, st.service_name, st.price as service_price, 
                       u.name as user_name, u.uni_ID,
                       v.license_plate, v.model as vehicle_model,
                       ps.spot_number, pg.name as garage_name
                       FROM Service_request sr
                       JOIN Service_type st ON sr.service_id = st.service_id
                       JOIN Users u ON sr.user_id = u.user_id
                       LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
                       LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id
                       LEFT JOIN Parking_garage pg ON ps.garage_id = pg.garage_id
                       WHERE sr.request_id = ? AND sr.user_id = ?");
$stmt->execute([$request_id, $user_id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    redirect('user/dashboard.php');
}

// Initialize payment_status if not set
$request['payment_status'] = $request['payment_status'] ?? 'Pending';
$request['payment_method'] = $request['payment_method'] ?? 'Pending';
$request['payment_amount'] = $request['payment_amount'] ?? null;
$request['receipt_id'] = $request['receipt_id'] ?? null;

// Check if request is approved
if ($request['status'] != 'Approved') {
    $error = 'This request is not approved yet. Payment can only be made for approved requests.';
}

// Check if already paid
if ($request['payment_status'] == 'Paid') {
    $success = 'Payment already completed for this request.';
}

// Handle payment form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $request['status'] == 'Approved' && ($request['payment_status'] ?? 'Pending') != 'Paid') {
    $payment_method = $_POST['payment_method'] ?? '';
    $amount = $request['service_price'];
    
    if ($payment_method == 'Credit') {
        $card_number = trim($_POST['card_number'] ?? '');
        $card_cvv = trim($_POST['card_cvv'] ?? '');
        $card_expiry = trim($_POST['card_expiry'] ?? '');
        $cardholder_name = trim($_POST['cardholder_name'] ?? '');
        
        // Validate credit card info
        if (empty($card_number) || empty($card_cvv) || empty($card_expiry) || empty($cardholder_name)) {
            $error = 'Please fill in all credit card information.';
        } elseif (strlen($card_number) < 13 || strlen($card_number) > 19) {
            $error = 'Invalid card number.';
        } elseif (strlen($card_cvv) != 3 && strlen($card_cvv) != 4) {
            $error = 'Invalid CVV.';
        } elseif (!preg_match('/^\d{2}\/\d{2}$/', $card_expiry)) {
            $error = 'Invalid expiry date. Use MM/YY format.';
        } else {
            // Process payment with Invoice and Payment records
            $pdo->beginTransaction();
            try {
                // Check if Invoice exists, create if not
                $stmt = $pdo->prepare("SELECT invoice_id FROM Invoice WHERE request_id = ?");
                $stmt->execute([$request_id]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Ensure receipt_id column exists in Invoice table
                try {
                    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Invoice' AND COLUMN_NAME = 'receipt_id'");
                    if ($stmt->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE Invoice ADD receipt_id VARCHAR(50) DEFAULT NULL");
                    }
                } catch (Exception $e) {
                    // Column might already exist
                }
                
                if (!$invoice) {
                    // Create Invoice first
                    $stmt = $pdo->prepare("INSERT INTO Invoice (request_id, amount, date_issued, time_issued) 
                                          VALUES (?, ?, CAST(GETDATE() AS DATE), CAST(GETDATE() AS TIME))");
                    $stmt->execute([$request_id, $amount]);
                    $invoice_id = $pdo->lastInsertId();
                } else {
                    $invoice_id = $invoice['invoice_id'];
                }
                
                // Use invoice_id as receipt_id
                $receipt_id = (string)$invoice_id;
                
                // Update Invoice with receipt_id (same as invoice_id)
                $stmt = $pdo->prepare("UPDATE Invoice SET receipt_id = ? WHERE invoice_id = ?");
                $stmt->execute([$receipt_id, $invoice_id]);
                
                // Update Service_request with receipt_id and payment info
                $stmt = $pdo->prepare("UPDATE Service_request SET 
                                      payment_method = 'Credit',
                                      payment_amount = ?,
                                      card_number = ?,
                                      card_cvv = ?,
                                      card_expiry = ?,
                                      cardholder_name = ?,
                                      payment_status = 'Paid',
                                      receipt_id = ?
                                      WHERE request_id = ?");
                $stmt->execute([$amount, $card_number, $card_cvv, $card_expiry, $cardholder_name, $receipt_id, $request_id]);
                
                // Create Payment_method record with card details
                $payment_info = "Card: ****" . substr($card_number, -4) . ", Exp: " . $card_expiry . ", Holder: " . $cardholder_name;
                $stmt = $pdo->prepare("INSERT INTO Payment_method (method_type, payment_info) VALUES (?, ?)");
                $stmt->execute(['Credit Card', $payment_info]);
                $method_id = $pdo->lastInsertId();
                
                // Create Payment record
                $stmt = $pdo->prepare("INSERT INTO Payment (invoice_id, amount, date_paid, time_paid, status, method_id) 
                                      VALUES (?, ?, CAST(GETDATE() AS DATE), CAST(GETDATE() AS TIME), 'Paid', ?)");
                $stmt->execute([$invoice_id, $amount, $method_id]);
                
                $pdo->commit();
                $success = 'Payment completed successfully! Receipt ID: <strong>' . $receipt_id . '</strong>';
                
                // Refresh request data
                $stmt = $pdo->prepare("SELECT sr.*, st.service_name, st.price as service_price, 
                                       u.name as user_name, u.uni_ID,
                                       v.license_plate, v.model as vehicle_model,
                                       ps.spot_number, pg.name as garage_name
                                       FROM Service_request sr
                                       JOIN Service_type st ON sr.service_id = st.service_id
                                       JOIN Users u ON sr.user_id = u.user_id
                                       LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
                                       LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id
                                       LEFT JOIN Parking_garage pg ON ps.garage_id = pg.garage_id
                                       WHERE sr.request_id = ? AND sr.user_id = ?");
                $stmt->execute([$request_id, $user_id]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                $request['receipt_id'] = $receipt_id;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Payment failed: ' . $e->getMessage();
            }
        }
    } elseif ($payment_method == 'Cash') {
        // Process cash payment with Invoice and Payment records
        $pdo->beginTransaction();
        try {
            // Check if Invoice exists, create if not
            $stmt = $pdo->prepare("SELECT invoice_id FROM Invoice WHERE request_id = ?");
            $stmt->execute([$request_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Ensure receipt_id column exists in Invoice table
            try {
                $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Invoice' AND COLUMN_NAME = 'receipt_id'");
                if ($stmt->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE Invoice ADD receipt_id VARCHAR(50) DEFAULT NULL");
                }
            } catch (Exception $e) {
                // Column might already exist
            }
            
            if (!$invoice) {
                // Create Invoice first
                $stmt = $pdo->prepare("INSERT INTO Invoice (request_id, amount, date_issued, time_issued) 
                                      VALUES (?, ?, CAST(GETDATE() AS DATE), CAST(GETDATE() AS TIME))");
                $stmt->execute([$request_id, $amount]);
                $invoice_id = $pdo->lastInsertId();
            } else {
                $invoice_id = $invoice['invoice_id'];
            }
            
            // Use invoice_id as receipt_id
            $receipt_id = (string)$invoice_id;
            
            // Update Invoice with receipt_id (same as invoice_id)
            $stmt = $pdo->prepare("UPDATE Invoice SET receipt_id = ? WHERE invoice_id = ?");
            $stmt->execute([$receipt_id, $invoice_id]);
            
            // Update Service_request with receipt_id and payment info
            $stmt = $pdo->prepare("UPDATE Service_request SET 
                                  payment_method = 'Cash',
                                  payment_amount = ?,
                                  payment_status = 'Paid',
                                  receipt_id = ?
                                  WHERE request_id = ?");
            $stmt->execute([$amount, $receipt_id, $request_id]);
            
            // Create Payment_method record for Cash
            $stmt = $pdo->prepare("INSERT INTO Payment_method (method_type, payment_info) VALUES (?, ?)");
            $stmt->execute(['Cash', 'Cash payment at service counter']);
            $method_id = $pdo->lastInsertId();
            
            // Create Payment record
            $stmt = $pdo->prepare("INSERT INTO Payment (invoice_id, amount, date_paid, time_paid, status, method_id) 
                                  VALUES (?, ?, CAST(GETDATE() AS DATE), CAST(GETDATE() AS TIME), 'Paid', ?)");
            $stmt->execute([$invoice_id, $amount, $method_id]);
            
            $pdo->commit();
            $success = 'Payment method selected: Cash. Receipt ID: <strong>' . $receipt_id . '</strong>. Please pay at the service counter.';
            
            // Refresh request data
            $stmt = $pdo->prepare("SELECT sr.*, st.service_name, st.price as service_price, 
                                   u.name as user_name, u.uni_ID,
                                   v.license_plate, v.model as vehicle_model,
                                   ps.spot_number, pg.name as garage_name
                                   FROM Service_request sr
                                   JOIN Service_type st ON sr.service_id = st.service_id
                                   JOIN Users u ON sr.user_id = u.user_id
                                   LEFT JOIN Vehicle v ON sr.vehicle_id = v.vehicle_id
                                   LEFT JOIN Parking_spots ps ON sr.spot_id = ps.spot_id
                                   LEFT JOIN Parking_garage pg ON ps.garage_id = pg.garage_id
                                   WHERE sr.request_id = ? AND sr.user_id = ?");
            $stmt->execute([$request_id, $user_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            $request['receipt_id'] = $receipt_id;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Payment failed: ' . $e->getMessage();
        }
    } else {
        $error = 'Please select a payment method.';
    }
}
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>💳 Payment</h2>
        <p>Complete payment for your approved request</p>
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
        <!-- Request Details -->
        <div class="card animate-fadeInUp">
            <h3>📋 Request Details</h3>
            <div style="margin-top:20px;">
                <div style="padding:15px;background:var(--light-bg);border-radius:8px;margin-bottom:15px;">
                    <strong>Service:</strong> <?= htmlspecialchars($request['service_name']) ?><br>
                    <strong>Amount:</strong> <span style="color:var(--primary-blue);font-size:1.2rem;font-weight:600;">EGP <?= number_format($request['service_price'], 2) ?></span>
                </div>
                
                <?php if ($request['license_plate']): ?>
                <div style="padding:10px;background:var(--light-bg);border-radius:8px;margin-bottom:10px;">
                    <strong>Vehicle:</strong> <?= htmlspecialchars($request['license_plate']) ?> - <?= htmlspecialchars($request['vehicle_model']) ?>
                </div>
                <?php endif; ?>
                
                <?php if ($request['spot_number']): ?>
                <div style="padding:10px;background:var(--light-bg);border-radius:8px;margin-bottom:10px;">
                    <strong>Parking Spot:</strong> <?= htmlspecialchars($request['spot_number']) ?> (<?= htmlspecialchars($request['garage_name']) ?>)
                </div>
                <?php endif; ?>
                
                <div style="padding:10px;background:var(--light-bg);border-radius:8px;">
                    <strong>Status:</strong> 
                    <span class="badge badge-<?= strtolower($request['status']) ?>">
                        <?= $request['status'] ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Payment Form -->
        <?php if ($request['status'] == 'Approved' && ($request['payment_status'] ?? 'Pending') != 'Paid'): ?>
        <div class="card card-teal animate-fadeInUp delay-1">
            <h3>💳 Payment Method</h3>
            
            <form method="POST" style="margin-top:20px;" id="paymentForm">
                <div class="form-group">
                    <label>Select Payment Method *</label>
                    <div style="display:flex;gap:15px;margin-top:10px;">
                        <label style="flex:1;padding:15px;border:2px solid var(--light-gray);border-radius:8px;cursor:pointer;text-align:center;transition:all 0.3s;" 
                               onmouseover="this.style.borderColor='var(--primary-blue)'" 
                               onmouseout="this.style.borderColor='var(--light-gray)'">
                            <input type="radio" name="payment_method" value="Credit" required onchange="toggleCreditForm()" style="margin-right:8px;">
                            <strong>💳 Credit Card</strong>
                        </label>
                        <label style="flex:1;padding:15px;border:2px solid var(--light-gray);border-radius:8px;cursor:pointer;text-align:center;transition:all 0.3s;"
                               onmouseover="this.style.borderColor='var(--primary-blue)'" 
                               onmouseout="this.style.borderColor='var(--light-gray)'">
                            <input type="radio" name="payment_method" value="Cash" required onchange="toggleCreditForm()" style="margin-right:8px;">
                            <strong>💵 Cash</strong>
                        </label>
                    </div>
                </div>
                
                <div id="credit_form" style="display:none;margin-top:20px;padding:20px;background:var(--light-bg);border-radius:8px;">
                    <h4 style="margin-bottom:15px;">Credit Card Information</h4>
                    
                    <div class="form-group">
                        <label>Cardholder Name *</label>
                        <input type="text" name="cardholder_name" placeholder="John Doe" maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Card Number *</label>
                        <input type="text" name="card_number" placeholder="1234 5678 9012 3456" maxlength="19" 
                               oninput="this.value = this.value.replace(/\s/g, '').replace(/(.{4})/g, '$1 ').trim()">
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                        <div class="form-group">
                            <label>Expiry Date (MM/YY) *</label>
                            <input type="text" name="card_expiry" placeholder="12/25" maxlength="5" 
                                   oninput="this.value = this.value.replace(/\D/g, '').replace(/(\d{2})(\d)/, '$1/$2').substring(0,5)">
                        </div>
                        
                        <div class="form-group">
                            <label>CVV *</label>
                            <input type="text" name="card_cvv" placeholder="123" maxlength="4" 
                                   oninput="this.value = this.value.replace(/\D/g, '')">
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:20px;">
                    💳 Complete Payment
                </button>
            </form>
        </div>
        <?php elseif (($request['payment_status'] ?? 'Pending') == 'Paid'): ?>
        <div class="card card-gold animate-fadeInUp delay-1">
            <h3>✅ Payment Completed</h3>
            <div style="margin-top:20px;padding:20px;background:var(--light-bg);border-radius:8px;">
                <p><strong>Receipt ID:</strong> <span style="color:var(--primary-blue);font-weight:600;font-size:1.1rem;"><?= htmlspecialchars($request['receipt_id'] ?? 'N/A') ?></span></p>
                <p><strong>Payment Method:</strong> <?= htmlspecialchars($request['payment_method'] ?? 'N/A') ?></p>
                <p><strong>Amount Paid:</strong> EGP <?= number_format($request['payment_amount'] ?? 0, 2) ?></p>
                <?php if (($request['payment_method'] ?? '') == 'Credit' && !empty($request['card_number'])): ?>
                <p><strong>Card:</strong> **** **** **** <?= substr($request['card_number'], -4) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleCreditForm() {
    const creditRadio = document.querySelector('input[name="payment_method"][value="Credit"]');
    const creditForm = document.getElementById('credit_form');
    
    if (creditRadio && creditRadio.checked) {
        creditForm.style.display = 'block';
        // Make credit card fields required
        creditForm.querySelectorAll('input').forEach(input => {
            input.setAttribute('required', 'required');
        });
    } else {
        creditForm.style.display = 'none';
        // Remove required from credit card fields
        creditForm.querySelectorAll('input').forEach(input => {
            input.removeAttribute('required');
        });
    }
}
</script>

<?php include '../includes/footer.php'; ?>