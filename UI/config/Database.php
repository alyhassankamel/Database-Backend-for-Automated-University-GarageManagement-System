<?php
// Define base path for the project
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', '/Project');

$server = 'HAMZA_ELKODSH\SQLEXPRESS'; // SQL Server instance name (e.g., 'localhost' or 'localhost\SQLEXPRESS')
$dbname = 'AUGMS';

try {
    // SQL Server connection using Windows Authentication (no username/password needed)
    $pdo = new PDO("sqlsrv:Server=$server;Database=$dbname");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

function redirect($url) {
    // If URL is absolute (starts with http), use as is
    if (strpos($url, 'http') === 0) {
        header("Location: $url");
        exit();
    }
    
    // If URL already contains BASE_URL, use as is
    if (strpos($url, BASE_URL) === 0) {
        header("Location: $url");
        exit();
    }
    
    // Otherwise, prepend BASE_URL
    $url = BASE_URL . '/' . ltrim($url, '/');
    header("Location: $url");
    exit();
}

function asset($path) {
    return BASE_URL . '/assets/' . ltrim($path, '/');
}

function url($path) {
    return BASE_URL . '/' . ltrim($path, '/');
}
?>