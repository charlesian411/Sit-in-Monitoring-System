<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: admin_login.php");
    exit();
}

require_once 'config/db.php';

$conn->query("CREATE TABLE IF NOT EXISTS sit_in_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    INDEX idx_sit_in_user (user_id),
    INDEX idx_sit_in_status (status),
    CONSTRAINT fk_sit_in_user_history FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$records = [];
$sql = "SELECT
            s.id,
            s.user_id,
            s.purpose,
            s.sit_lab,
            s.status,
            s.started_at,
            s.ended_at,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            (
                SELECT COUNT(*)
                FROM sit_in_records s2
                WHERE s2.user_id = s.user_id AND s2.id <= s.id
            ) AS session_number
        FROM sit_in_records s
        INNER JOIN users u ON u.id = s.user_id
        ORDER BY s.started_at DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Sit-in Records</title>
    <link rel="stylesheet" href="style.css?v=12">
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_dashboard.php?open=sitin">Sit In</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_current_sitin.php">View Current Sitin</a></li>
        <li><a href="admin_sitin_history.php">View Sit-in Records</a></li>
        <li><a href="admin_feedback_reports.php">Feedback Reports</a></li>
        <li><a href="admin_reservations.php">Reservations</a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title">Sit-in Records History</h1>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>Sit ID Number</th>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Purpose</th>
                    <th>Sit Lab</th>
                    <th>Session #</th>
                    <th>Status</th>
                    <th>Started At</th>
                    <th>Ended At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9" class="empty-table">No data available.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?php echo (int) $record['id']; ?></td>
                            <td><?php echo htmlspecialchars($record['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($record['first_name'] . ' ' . ($record['middle_name'] ? $record['middle_name'] . ' ' : '') . $record['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($record['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($record['sit_lab']); ?></td>
                            <td><?php echo (int) $record['session_number']; ?></td>
                            <td>
                                <?php if ($record['status'] === 'active'): ?>
                                    <span class="role-badge role-student">Active</span>
                                <?php else: ?>
                                    <span class="status-badge status-approved">Completed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($record['started_at']))); ?></td>
                            <td><?php echo $record['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($record['ended_at']))) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
