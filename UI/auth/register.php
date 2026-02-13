<?php
require_once '../config/Database.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (isLoggedIn()) {
    redirect('user/dashboard.php');
}

$error = '';
$success = '';
$show_login_link = false; // Flag to show login link for duplicate account errors

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uni_id = trim($_POST['uni_id']);
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password']; // Keep original for session
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $user_type = trim($_POST['user_type']);
    
    // Validate user_type
    $allowed_types = ['Student', 'Staff', 'Admin', 'Manager'];
    if (!in_array($user_type, $allowed_types)) {
        $error = 'Invalid user type selected.';
    }
    
    // Check for duplicate University ID (case-insensitive)
    $stmt = $pdo->prepare("SELECT uni_ID FROM Users WHERE LOWER(uni_ID) = LOWER(?)");
    $stmt->execute([$uni_id]);
    if ($stmt->rowCount() > 0) {
        $error = 'University ID "' . htmlspecialchars($uni_id) . '" is already registered. Please use a different ID or try logging in.';
        $show_login_link = true;
    }
    
    // Check for duplicate phone number
    if (empty($error)) {
        $stmt = $pdo->prepare("SELECT phone FROM Users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->rowCount() > 0) {
            $error = 'Phone number is already registered. Please use a different phone number or try logging in.';
            $show_login_link = true;
        }
    }
    
    // Proceed with registration if no errors
    if (empty($error)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO Users (uni_ID, name, phone, password, user_type) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$uni_id, $name, $phone, $hashed_password, $user_type])) {
                
                // ✅ AUTO-LOGIN: Set session variables
                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['user_name'] = $name;
                $_SESSION['user_type'] = $user_type;
                $_SESSION['uni_id'] = $uni_id;
                $_SESSION['logged_in'] = true;
                
                // ✅ Redirect to dashboard
                header('Location: ' . url('user/dashboard.php'));
                exit();
                
            } else {
                $error = 'Registration failed. Please try again.';
            }
        } catch (PDOException $e) {
            // Better error handling to see what's wrong
            $error_msg = $e->getMessage();
            $error_code = $e->getCode();
            
            // Check for UNIQUE constraint violation (SQL Server error code 23000)
            if (stripos($error_msg, 'UNIQUE KEY') !== false || 
                stripos($error_msg, 'duplicate key') !== false || 
                stripos($error_msg, 'UQ__') !== false ||
                $error_code == 23000) {
                
                // Extract which field is duplicate
                if (stripos($error_msg, 'uni_ID') !== false || stripos($error_msg, $uni_id) !== false) {
                    $error = 'University ID "' . htmlspecialchars($uni_id) . '" is already registered. Please use a different ID or try logging in.';
                    $show_login_link = true;
                } elseif (stripos($error_msg, 'phone') !== false) {
                    $error = 'Phone number is already registered. Please use a different phone number or try logging in.';
                    $show_login_link = true;
                } else {
                    $error = 'This account already exists. Please try logging in instead.';
                    $show_login_link = true;
                }
            }
            // Check if it's a constraint issue (SQL Server uses CHECK constraints instead of ENUM)
            elseif (stripos($error_msg, 'check') !== false || stripos($error_msg, 'user_type') !== false) {
                // Try to fix the schema automatically - SQL Server uses VARCHAR with CHECK constraint
                try {
                    $stmt = $pdo->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'Users' AND COLUMN_NAME = 'user_type'");
                    $column = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($column) {
                        // Check if constraint exists and update it
                        $stmt = $pdo->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS WHERE TABLE_NAME = 'Users' AND CONSTRAINT_NAME LIKE '%user_type%'");
                        if ($stmt->rowCount() == 0) {
                            // Add CHECK constraint if it doesn't exist
                            $pdo->exec("ALTER TABLE Users ADD CONSTRAINT CK_Users_user_type CHECK (user_type IN ('Student', 'Staff', 'Admin', 'Manager'))");
                        }
                        // Retry the insert
                        $stmt = $pdo->prepare("INSERT INTO Users (uni_ID, name, phone, password, user_type) VALUES (?, ?, ?, ?, ?)");
                        if ($stmt->execute([$uni_id, $name, $phone, $hashed_password, $user_type])) {
                            $_SESSION['user_id'] = $pdo->lastInsertId();
                            $_SESSION['user_name'] = $name;
                            $_SESSION['user_type'] = $user_type;
                            $_SESSION['uni_id'] = $uni_id;
                            $_SESSION['logged_in'] = true;
                            header('Location: ' . url('user/dashboard.php'));
                            exit();
                        }
                    }
                } catch (Exception $fix_error) {
                    $error = 'Registration failed. The database schema may need to be updated. Please check the user_type constraint.';
                }
            } else {
                $error = 'Registration failed: ' . htmlspecialchars($error_msg);
            }
            // Log the error for debugging
            error_log("Registration error: " . $error_msg . " | User type: " . $user_type . " | Uni ID: " . $uni_id);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - EUI Parking System</title>
    <link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body>
    <div class="loading-screen">
        <div class="loader">
            <div class="loader-car">🚗</div>
            <p style="margin-top:15px;font-size:1.2rem;">Loading...</p>
            <div class="loader-dots">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>
    </div>

    <header class="header">
        <div class="logo-container">
            <div class="logo">EUI</div>
            <h1>EUI Parking System</h1>
        </div>
    </header>
    
    <div class="auth-container">
        <div class="auth-box">
            <h2>
                📝 Create Account
                <span>Join us to manage your parking easily</span>
            </h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">❌</span>
                    <?= htmlspecialchars($error) ?>
                    <?php if ($show_login_link): ?>
                        <div style="margin-top: 10px;">
                            <a href="<?= url('auth/index.php') ?>" class="btn btn-primary btn-sm" style="display: inline-block;">Go to Login Page</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>Select User Type</label>
                    <div class="user-type-selector">
                        <div class="user-type-option">
                            <input type="radio" name="user_type" value="Student" id="student" required checked>
                            <label for="student">
                                <span class="icon">🎓</span>
                                <span class="text">Student</span>
                            </label>
                        </div>
                        <div class="user-type-option">
                            <input type="radio" name="user_type" value="Staff" id="staff">
                            <label for="staff">
                                <span class="icon">👨‍💼</span>
                                <span class="text">Staff</span>
                            </label>
                        </div>
                        <div class="user-type-option">
                            <input type="radio" name="user_type" value="Admin" id="admin">
                            <label for="admin">
                                <span class="icon">🔧</span>
                                <span class="text">Admin</span>
                            </label>
                        </div>
                        <div class="user-type-option">
                            <input type="radio" name="user_type" value="Manager" id="manager">
                            <label for="manager">
                                <span class="icon">👔</span>
                                <span class="text">Manager</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>University ID</label>
                    <input type="text" name="uni_id" required placeholder="e.g., STU20240001">
                </div>
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" required placeholder="Enter your full name">
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" required placeholder="e.g., 01012345678">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Create a strong password" minlength="6">
                    <span class="password-toggle">👁️</span>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    ✨ Create Account
                </button>
            </form>
            
            <p class="auth-link">
                Already have an account? <a href="<?= url('auth/index.php') ?>">Sign in here</a>
            </p>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Egypt University of Informatics</p>
    </footer>
    
    <script src="<?= asset('script.js') ?>"></script>
</body>
</html>