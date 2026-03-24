<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: login.php");
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
    CONSTRAINT fk_sit_in_user_current FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end_sitin') {
    $record_id = (int) ($_POST['record_id'] ?? 0);
    if ($record_id > 0) {
        $stmt = $conn->prepare("UPDATE sit_in_records SET status = 'completed', ended_at = NOW() WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $record_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $alert_message = "Sit-in session completed.";
            $alert_type = "success";
        } else {
            $alert_message = "Unable to update sit-in status.";
            $alert_type = "error";
        }
        $stmt->close();
    }
}

$records = [];
$sql = "SELECT
            s.id,
            s.user_id,
            s.purpose,
            s.sit_lab,
            s.status,
            s.started_at,
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
        WHERE s.status = 'active'
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
    <title>CCS | Current Sit In</title>
    <link rel="stylesheet" href="style.css?v=4">
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_dashboard.php#sit-in-form">Sit-in</a></li>
        <li><a href="admin_current_sitin.php">View Sit-in Records</a></li>
        <li><a href="admin_reservations.php">Reservations</a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title">Current Sit in</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

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
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="empty-table">No data available.</td>
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
                            <td><span class="role-badge role-student">Active</span></td>
                            <td>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Mark this sit-in as completed?');">
                                    <input type="hidden" name="action" value="end_sitin">
                                    <input type="hidden" name="record_id" value="<?php echo (int) $record['id']; ?>">
                                    <button type="submit" class="admin-btn admin-btn-secondary">End</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
