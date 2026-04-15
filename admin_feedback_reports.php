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
    CONSTRAINT fk_sit_in_user_feedback_reports FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$feedback_column_check = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'feedback'");
if ($feedback_column_check && $feedback_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN feedback TEXT NULL");
}

$reports = [];
$sql = "SELECT
            s.id,
            s.purpose,
            s.sit_lab,
            s.started_at,
            s.ended_at,
            s.feedback,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name
        FROM sit_in_records s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.feedback IS NOT NULL AND TRIM(s.feedback) <> ''
        ORDER BY COALESCE(s.ended_at, s.started_at) DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reports[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Feedback Reports</title>
    <link rel="stylesheet" href="style.css?v=1">
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
    <h1 class="admin-page-title">Feedback Reports</h1>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>Sit ID Number</th>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Purpose</th>
                    <th>Sit Lab</th>
                    <th>Ended At</th>
                    <th>Feedback</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="7" class="empty-table">No feedback reports yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo (int) $report['id']; ?></td>
                            <td><?php echo htmlspecialchars($report['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($report['first_name'] . ' ' . ($report['middle_name'] ? $report['middle_name'] . ' ' : '') . $report['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($report['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($report['sit_lab']); ?></td>
                            <td><?php echo $report['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($report['ended_at']))) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($report['feedback']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
