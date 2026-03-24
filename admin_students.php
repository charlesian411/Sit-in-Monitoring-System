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
    CONSTRAINT fk_sit_in_user_students FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_sessions') {
        if ($conn->query("DELETE FROM sit_in_records")) {
            $alert_message = "All sit-in sessions were reset.";
            $alert_type = "success";
        } else {
            $alert_message = "Unable to reset sessions.";
            $alert_type = "error";
        }
    }

    if ($action === 'delete_student') {
        $student_id = (int) ($_POST['student_id'] ?? 0);
        if ($student_id > 0) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'student'");
            $stmt->bind_param("i", $student_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $alert_message = "Student deleted.";
                $alert_type = "success";
            } else {
                $alert_message = "Student not found or delete failed.";
                $alert_type = "error";
            }
            $stmt->close();
        }
    }
}

$students = [];
$sql = "SELECT
            u.id,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.course,
            u.course_level,
            GREATEST(0, 30 - COALESCE(sr.total_sessions, 0)) AS remaining_sessions
        FROM users u
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS total_sessions
            FROM sit_in_records
            GROUP BY user_id
        ) sr ON sr.user_id = u.id
        WHERE u.role = 'student'
        ORDER BY u.last_name ASC, u.first_name ASC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Student List</title>
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
    <h1 class="admin-page-title">Students Information</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="students-toolbar">
        <a href="register.php" class="admin-btn admin-btn-primary">Add Students</a>
        <form method="POST" class="inline-form" onsubmit="return confirm('Reset all sit-in sessions?');">
            <input type="hidden" name="action" value="reset_sessions">
            <button type="submit" class="admin-btn admin-btn-danger">Reset All Session</button>
        </form>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Year Level</th>
                    <th>Course</th>
                    <th>Remaining Session</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" class="empty-table">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?></td>
                            <td><?php echo (int) $student['course_level']; ?></td>
                            <td><?php echo htmlspecialchars($student['course']); ?></td>
                            <td><?php echo (int) $student['remaining_sessions']; ?></td>
                            <td>
                                <a href="admin_dashboard.php?open=search&search_id=<?php echo urlencode($student['id_number']); ?>" class="admin-btn admin-btn-secondary">View</a>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Delete this student?');">
                                    <input type="hidden" name="action" value="delete_student">
                                    <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                    <button type="submit" class="admin-btn admin-btn-danger">Delete</button>
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
