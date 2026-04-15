<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!empty($_SESSION['is_admin'])) {
    header("Location: admin_dashboard.php");
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
    CONSTRAINT fk_sit_in_user_student_history_page FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$feedback_column_check = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'feedback'");
if ($feedback_column_check && $feedback_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN feedback TEXT NULL");
}

$alert_message = "";
$alert_type = "success";
$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    $record_id = (int) ($_POST['record_id'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');

    if ($record_id <= 0 || $feedback === '') {
        $alert_message = "Please enter your feedback.";
        $alert_type = "error";
    } else {
        $feedback_stmt = $conn->prepare("UPDATE sit_in_records SET feedback = ? WHERE id = ? AND user_id = ? AND status = 'completed'");
        $feedback_stmt->bind_param("sii", $feedback, $record_id, $user_id);

        if ($feedback_stmt->execute() && $feedback_stmt->affected_rows > 0) {
            $alert_message = "Feedback submitted successfully.";
            $alert_type = "success";
        } else {
            $alert_message = "Unable to submit feedback.";
            $alert_type = "error";
        }

        $feedback_stmt->close();
    }
}

$sitin_history = [];
$history_stmt = $conn->prepare("SELECT id, purpose, sit_lab, status, started_at, ended_at, feedback FROM sit_in_records WHERE user_id = ? ORDER BY started_at DESC");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$history_res = $history_stmt->get_result();
while ($row = $history_res->fetch_assoc()) {
    $sitin_history[] = $row;
}
$history_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | My History Sitin</title>
    <link rel="stylesheet" href="style.css?v=1">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="admin-page student-reservation-page">
    <h1 class="admin-page-title">My History Sitin</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <section class="admin-card student-history-card">
        <div class="admin-card-title">Sit-in History</div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Ended</th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sitin_history)): ?>
                        <tr>
                            <td colspan="6" class="empty-table">No sit-in history yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sitin_history as $history): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($history['sit_lab']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($history['status']); ?>"><?php echo htmlspecialchars(ucfirst($history['status'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($history['started_at']))); ?></td>
                                <td><?php echo $history['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($history['ended_at']))) : '-'; ?></td>
                                <td>
                                    <?php if ($history['status'] === 'completed'): ?>
                                        <?php if (trim((string) ($history['feedback'] ?? '')) !== ''): ?>
                                            <?php echo htmlspecialchars($history['feedback']); ?>
                                        <?php else: ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="submit_feedback">
                                                <input type="hidden" name="record_id" value="<?php echo (int) $history['id']; ?>">
                                                <input type="text" class="form-control" name="feedback" placeholder="Type feedback" required>
                                                <button type="submit" class="admin-btn admin-btn-secondary" style="margin-top:0.5rem;">Send</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

</body>
</html>
