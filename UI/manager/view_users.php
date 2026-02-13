<?php
include '../includes/header.php';

if (getUserType() != 'Manager') {
    redirect('user/dashboard.php');
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

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Build query with fallback ordering
$order_by = $has_created_at ? 'u.created_at DESC' : 'u.user_id DESC';

try {
    if ($filter == 'all') {
        $stmt = $pdo->query("SELECT u.*, 
                             (SELECT COUNT(*) FROM Vehicle WHERE user_id = u.user_id AND access_status = 1) as vehicle_count,
                             (SELECT COUNT(*) FROM Service_request WHERE user_id = u.user_id) as request_count
                             FROM Users u ORDER BY $order_by");
    } else {
        $stmt = $pdo->prepare("SELECT u.*, 
                               (SELECT COUNT(*) FROM Vehicle WHERE user_id = u.user_id AND access_status = 1) as vehicle_count,
                               (SELECT COUNT(*) FROM Service_request WHERE user_id = u.user_id) as request_count
                               FROM Users u WHERE u.user_type = ? ORDER BY $order_by");
        $stmt->execute([$filter]);
    }
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $error = 'Error loading users: ' . $e->getMessage();
}

// Get counts
$stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM Users GROUP BY user_type");
$type_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$total_users = array_sum($type_counts);
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h2>👥 User Management</h2>
        <p>View and manage all system users</p>
    </div>
    
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
    </div>
    
    <!-- Users Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>📋 All Users</h3>
            <div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;">
                <div class="filter-buttons">
                    <a href="?filter=all" class="filter-btn <?= $filter == 'all' ? 'active' : '' ?>">All</a>
                    <a href="?filter=Student" class="filter-btn <?= $filter == 'Student' ? 'active' : '' ?>">Students</a>
                    <a href="?filter=Staff" class="filter-btn <?= $filter == 'Staff' ? 'active' : '' ?>">Staff</a>
                    <a href="?filter=Admin" class="filter-btn <?= $filter == 'Admin' ? 'active' : '' ?>">Admins</a>
                    <a href="?filter=Manager" class="filter-btn <?= $filter == 'Manager' ? 'active' : '' ?>">Managers</a>
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8">
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
                                $type_classes = [
                                    'Student' => 'badge-info',
                                    'Staff' => 'badge-approved',
                                    'Admin' => 'badge-warning',
                                    'Manager' => 'badge-pending'
                                ];
                                $icons = ['Student' => '🎓', 'Staff' => '👨‍💼', 'Admin' => '🔧', 'Manager' => '👔'];
                                ?>
                                <span class="badge <?= $type_classes[$u['user_type']] ?? '' ?>">
                                    <?= $icons[$u['user_type']] ?? '' ?> <?= $u['user_type'] ?>
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
                                <?php if (isset($u['created_at']) && !empty($u['created_at'])): ?>
                                <div>
                                    <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                    <small style="display:block;color:var(--gray);">
                                        <?= date('H:i', strtotime($u['created_at'])) ?>
                                    </small>
                                </div>
                                <?php else: ?>
                                <div>
                                    <span style="color:var(--gray);">N/A</span>
                                </div>
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