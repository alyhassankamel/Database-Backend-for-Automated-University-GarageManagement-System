<?php
/**
 * Admin Tool: Create Manager Account
 * This script allows you to quickly create a Manager account directly in the database
 * Access this file directly in your browser to create a manager account
 */

require_once '../config/Database.php';

$success = '';
$error = '';
$created_user = null;

// First, ensure the database schema supports Manager type
try {
    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Users' AND COLUMN_NAME = 'user_type'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($column) {
        // Check if CHECK constraint exists and includes Manager
        $stmt = $pdo->query("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE TABLE_NAME = 'Users' AND CONSTRAINT_NAME LIKE '%user_type%'");
        $constraint = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($constraint) {
            if (stripos($constraint['CHECK_CLAUSE'], 'Manager') === false) {
                // Update constraint to include Manager
                try {
                    $pdo->exec("ALTER TABLE Users DROP CONSTRAINT " . $constraint['CONSTRAINT_NAME']);
                    $pdo->exec("ALTER TABLE Users ADD CONSTRAINT CK_Users_user_type CHECK (user_type IN ('Student', 'Staff', 'Admin', 'Manager'))");
                    $success = "✅ Updated database schema to support Manager type.\n";
                } catch (Exception $e) {
                    $error = "Warning: Could not update constraint: " . $e->getMessage() . "\n";
                }
            }
        } else {
            // No constraint exists, add one
            try {
                $pdo->exec("ALTER TABLE Users ADD CONSTRAINT CK_Users_user_type CHECK (user_type IN ('Student', 'Staff', 'Admin', 'Manager'))");
                $success = "✅ Added CHECK constraint to support Manager type.\n";
            } catch (Exception $e) {
                $error = "Warning: Could not add constraint: " . $e->getMessage() . "\n";
            }
        }
    }
} catch (Exception $e) {
    $error = "Warning: Could not check/update schema: " . $e->getMessage() . "\n";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uni_id = trim($_POST['uni_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($uni_id) || empty($name) || empty($phone) || empty($password)) {
        $error = 'All fields are required.';
    } else {
        // Check if user already exists
        $stmt = $pdo->prepare("SELECT * FROM Users WHERE uni_ID = ? OR phone = ?");
        $stmt->execute([$uni_id, $phone]);
        
        if ($stmt->rowCount() > 0) {
            $error = 'University ID or Phone number already exists.';
        } else {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO Users (uni_ID, name, phone, password, user_type) VALUES (?, ?, ?, ?, 'Manager')");
                
                if ($stmt->execute([$uni_id, $name, $phone, $hashed_password])) {
                    $user_id = $pdo->lastInsertId();
                    $stmt = $pdo->prepare("SELECT * FROM Users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $created_user = $stmt->fetch(PDO::FETCH_ASSOC);
                    $success = 'Manager account created successfully!';
                } else {
                    $error = 'Failed to create manager account.';
                }
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

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

// Get all managers with fallback ordering
$order_by = $has_created_at ? 'created_at DESC' : 'user_id DESC';
try {
    $stmt = $pdo->query("SELECT * FROM Users WHERE user_type = 'Manager' ORDER BY $order_by");
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $managers = [];
    $error = 'Error loading managers: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Manager Account</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2a9d8f; margin-bottom: 20px; }
        h2 { color: #264653; margin-top: 30px; margin-bottom: 15px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 5px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #264653; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { background: #2a9d8f; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        button:hover { background: #21867a; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #2a9d8f; color: white; }
        tr:hover { background: #f5f5f5; }
        .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #2a9d8f; }
    </style>
</head>
<body>
    <div class="container">
        <h1>👔 Create Manager Account</h1>
        
        <div class="info-box">
            <strong>ℹ️ Instructions:</strong><br>
            Fill in the form below to create a Manager account. This account will be saved to the database and you can log in immediately using the University ID and password you provide.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
                <?php if ($created_user): ?>
                    <br><br>
                    <strong>Account Details:</strong><br>
                    University ID: <strong><?= htmlspecialchars($created_user['uni_ID']) ?></strong><br>
                    Name: <strong><?= htmlspecialchars($created_user['name']) ?></strong><br>
                    Type: <strong><?= htmlspecialchars($created_user['user_type']) ?></strong><br>
                    <br>
                    <a href="<?= url('auth/index.php') ?>" style="color: #2a9d8f; text-decoration: underline;">Click here to log in</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>University ID *</label>
                <input type="text" name="uni_id" required placeholder="e.g., MGR20240001" value="<?= htmlspecialchars($_POST['uni_id'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" required placeholder="Enter full name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Phone Number *</label>
                <input type="text" name="phone" required placeholder="e.g., 01012345678" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required placeholder="Create a password (min 6 characters)" minlength="6">
            </div>
            
            <button type="submit">Create Manager Account</button>
        </form>
        
        <h2>Existing Managers</h2>
        <?php if (empty($managers)): ?>
            <p>No manager accounts found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>University ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($managers as $mgr): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($mgr['uni_ID']) ?></strong></td>
                        <td><?= htmlspecialchars($mgr['name']) ?></td>
                        <td><?= htmlspecialchars($mgr['phone']) ?></td>
                        <td>
                            <?php if (isset($mgr['created_at']) && !empty($mgr['created_at'])): ?>
                                <?= date('M d, Y', strtotime($mgr['created_at'])) ?>
                            <?php else: ?>
                                <span style="color:var(--gray);">N/A</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <a href="<?= url('auth/index.php') ?>" style="color: #2a9d8f;">← Back to Login</a> | 
            <a href="<?= url('auth/register.php') ?>" style="color: #2a9d8f;">Go to Registration</a>
        </div>
    </div>
</body>
</html>