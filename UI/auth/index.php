<?php
require_once '../config/Database.php';

if (isLoggedIn()) {
    redirect('user/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uni_id = trim($_POST['uni_id']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM Users WHERE uni_ID = ?");
    $stmt->execute([$uni_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['uni_id'] = $user['uni_ID'];
        redirect('user/dashboard.php');
    } else {
        $error = 'Invalid University ID or Password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EUI Parking System</title>
    <link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body>
    <!-- Loading Screen -->
    <div class="loading-screen">
        <div class="loader">
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
            <img src="<?= asset('images/logo.png') ?>" alt="EUI Logo" class="logo">
            <h1>EUI Parking System</h1>
        </div>
    </header>
    
    <div class="auth-container">
        <div class="auth-box">
            <h2>
                Welcome Back
                <span>Sign in to access your parking dashboard</span>
            </h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">❌</span>
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>University ID</label>
                    <input type="text" name="uni_id" required placeholder="Enter your University ID">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter your password">
                    <span class="password-toggle"></span>
                </div>
                
                <button type="submit" class="btn btn-primary">
                     Sign In
                </button>
            </form>
            
            <p class="auth-link">
                Don't have an account? <a href="register.php">Create one here</a>
            </p>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?= date('Y') ?> Egypt University of Informatics</p>
    </footer>
    
    <script src="<?= asset('script.js') ?>"></script>
</body>
</html>