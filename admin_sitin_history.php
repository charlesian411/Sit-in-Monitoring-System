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

$alert_message = "";
$alert_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'clear_history')) {
    if ($conn->query("DELETE FROM sit_in_records WHERE status = 'completed'")) {
        $deleted_rows = (int) $conn->affected_rows;
        if ($deleted_rows > 0) {
            $alert_message = "Completed sit-in record history cleared.";
        } else {
            $alert_message = "No completed history records to clear.";
        }
        $alert_type = "success";
    } else {
        $alert_message = "Unable to clear sit-in history.";
        $alert_type = "error";
    }
}

$search_id_number = trim($_GET['search_id'] ?? '');
$open_history_modal = false;
$history_modal_student = null;
$history_modal_records = [];

if ($search_id_number !== '') {
    $student_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
    $student_stmt->bind_param("s", $search_id_number);
    $student_stmt->execute();
    $student_res = $student_stmt->get_result();
    $history_modal_student = $student_res->fetch_assoc();
    $student_stmt->close();

    if (!$history_modal_student) {
        $alert_message = "Student ID not found.";
        $alert_type = "error";
    } else {
        $history_user_id = (int) $history_modal_student['id'];
        $history_stmt = $conn->prepare("SELECT
                s.purpose,
                s.sit_lab,
                s.status,
                s.started_at,
                s.ended_at,
                (
                    SELECT COUNT(*)
                    FROM sit_in_records s2
                    WHERE s2.user_id = s.user_id AND s2.id <= s.id
                ) AS session_number
            FROM sit_in_records s
            WHERE s.user_id = ?
            ORDER BY s.started_at DESC");
        $history_stmt->bind_param("i", $history_user_id);
        $history_stmt->execute();
        $history_res = $history_stmt->get_result();
        while ($history_row = $history_res->fetch_assoc()) {
            $history_modal_records[] = $history_row;
        }
        $history_stmt->close();
        $open_history_modal = true;
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

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="students-toolbar">
        <form method="GET" class="inline-form" style="display:flex; gap:0.5rem; align-items:center;">
            <input
                type="text"
                name="search_id"
                class="form-control"
                placeholder="Search Student ID"
                value="<?php echo htmlspecialchars($search_id_number); ?>"
                style="min-width:240px;"
            >
            <button type="submit" class="admin-btn admin-btn-secondary">Search</button>
            <?php if ($search_id_number !== ''): ?>
                <a href="admin_sitin_history.php" class="admin-btn admin-btn-muted">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="students-toolbar">
        <form method="POST" class="inline-form" onsubmit="return confirm('Clear completed sit-in history records? Active sessions will be kept.');">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="admin-btn admin-btn-danger">Clear Record History</button>
        </form>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
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
                        <td colspan="8" class="empty-table">No data available.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
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

<div class="modal-overlay <?php echo $open_history_modal ? 'is-open' : ''; ?>" id="student-history-modal">
    <div class="admin-modal" style="max-width: 920px; width: 95%;">
        <div class="modal-header">
            <h3>
                Student Sit-in History
                <?php if ($history_modal_student): ?>
                    - <?php echo htmlspecialchars($history_modal_student['id_number'] . ' | ' . $history_modal_student['first_name'] . ' ' . ($history_modal_student['middle_name'] ? $history_modal_student['middle_name'] . ' ' : '') . $history_modal_student['last_name']); ?>
                <?php endif; ?>
            </h3>
            <a href="admin_sitin_history.php" class="modal-close" aria-label="Close">&times;</a>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table students-table">
                <thead>
                    <tr>
                        <th>Purpose</th>
                        <th>Sit Lab</th>
                        <th>Session #</th>
                        <th>Status</th>
                        <th>Started At</th>
                        <th>Ended At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history_modal_records)): ?>
                        <tr>
                            <td colspan="6" class="empty-table">No sit-in history for this student.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history_modal_records as $history_record): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($history_record['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($history_record['sit_lab']); ?></td>
                                <td><?php echo (int) $history_record['session_number']; ?></td>
                                <td>
                                    <?php if ($history_record['status'] === 'active'): ?>
                                        <span class="role-badge role-student">Active</span>
                                    <?php else: ?>
                                        <span class="status-badge status-approved">Completed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($history_record['started_at']))); ?></td>
                                <td><?php echo $history_record['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($history_record['ended_at']))) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
