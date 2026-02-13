<?php
require_once '../config/Database.php';
session_start();
session_destroy();
redirect('auth/index.php');
?>