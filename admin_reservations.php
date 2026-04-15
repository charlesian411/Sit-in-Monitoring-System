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
    pc_number VARCHAR(10) NULL,
    status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    INDEX idx_sit_in_user (user_id),
    INDEX idx_sit_in_status (status),
    CONSTRAINT fk_sit_in_user_reservation_page FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    pc_number VARCHAR(10) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_at TIMESTAMP NULL,
    student_notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_user (user_id),
    INDEX idx_reservation_status (status),
    INDEX idx_reservation_schedule (reservation_date, reservation_time),
    CONSTRAINT fk_reservation_user_admin FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$reservation_notified_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'student_notified'");
if ($reservation_notified_column && $reservation_notified_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN student_notified TINYINT(1) NOT NULL DEFAULT 0");
}

$reservation_pc_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
if ($reservation_pc_column && $reservation_pc_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN pc_number VARCHAR(10) NOT NULL DEFAULT 'PC1'");
}

$sitin_pc_column = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'pc_number'");
if ($sitin_pc_column && $sitin_pc_column->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN pc_number VARCHAR(10) NULL");
}

$alert_message = "";
$alert_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'review_reservation') {
        $reservation_id = (int) ($_POST['reservation_id'] ?? 0);
        $decision = trim($_POST['decision'] ?? '');
        $admin_note = trim($_POST['admin_note'] ?? '');

        if ($reservation_id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            $alert_message = "Invalid reservation action.";
            $alert_type = "error";
        } else {
            $reservation_stmt = $conn->prepare("SELECT id, user_id, purpose, sit_lab, pc_number, status FROM reservations WHERE id = ? LIMIT 1");
            $reservation_stmt->bind_param("i", $reservation_id);
            $reservation_stmt->execute();
            $reservation_res = $reservation_stmt->get_result();
            $reservation = $reservation_res->fetch_assoc();
            $reservation_stmt->close();

            if (!$reservation) {
                $alert_message = "Reservation not found.";
                $alert_type = "error";
            } elseif ($reservation['status'] !== 'pending') {
                $alert_message = "Reservation is already reviewed.";
                $alert_type = "error";
            } elseif ($decision === 'approved') {
                $user_id = (int) $reservation['user_id'];

                $active_stmt = $conn->prepare("SELECT id FROM sit_in_records WHERE user_id = ? AND status = 'active' LIMIT 1");
                $active_stmt->bind_param("i", $user_id);
                $active_stmt->execute();
                $active_stmt->store_result();

                $count_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
                $count_stmt->bind_param("i", $user_id);
                $count_stmt->execute();
                $count_res = $count_stmt->get_result();
                $count_row = $count_res->fetch_assoc();
                $total_sessions = (int) ($count_row['total_sessions'] ?? 0);

                if ($active_stmt->num_rows > 0) {
                    $alert_message = "Cannot approve: student already has an active Sit-in.";
                    $alert_type = "error";
                } elseif ($total_sessions >= 30) {
                    $alert_message = "Cannot approve: student has no remaining sessions.";
                    $alert_type = "error";
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO sit_in_records (user_id, purpose, sit_lab, pc_number, status) VALUES (?, ?, ?, ?, 'active')");
                    $insert_stmt->bind_param("isss", $user_id, $reservation['purpose'], $reservation['sit_lab'], $reservation['pc_number']);

                    if ($insert_stmt->execute()) {
                        $update_stmt = $conn->prepare("UPDATE reservations SET status = 'approved', admin_note = ?, reviewed_at = NOW(), student_notified = 0 WHERE id = ?");
                        $update_stmt->bind_param("si", $admin_note, $reservation_id);
                        if ($update_stmt->execute()) {
                            $alert_message = "Reservation approved and Sit-in started.";
                            $alert_type = "success";
                        } else {
                            $alert_message = "Sit-in started, but reservation status was not updated.";
                            $alert_type = "error";
                        }
                        $update_stmt->close();
                    } else {
                        $alert_message = "Unable to create Sit-in from reservation.";
                        $alert_type = "error";
                    }
                    $insert_stmt->close();
                }

                $active_stmt->close();
                $count_stmt->close();
            } else {
                $reject_stmt = $conn->prepare("UPDATE reservations SET status = 'rejected', admin_note = ?, reviewed_at = NOW(), student_notified = 0 WHERE id = ?");
                $reject_stmt->bind_param("si", $admin_note, $reservation_id);
                if ($reject_stmt->execute()) {
                    $alert_message = "Reservation rejected.";
                    $alert_type = "success";
                } else {
                    $alert_message = "Unable to reject reservation.";
                    $alert_type = "error";
                }
                $reject_stmt->close();
            }
        }
    }
}

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_row['total'];
}

$reservations = [];
$res = $conn->query("SELECT
    r.id,
    r.purpose,
    r.sit_lab,
    r.pc_number,
    r.reservation_date,
    r.reservation_time,
    r.status,
    r.admin_note,
    r.created_at,
    r.reviewed_at,
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name,
    u.course,
    u.course_level
FROM reservations r
INNER JOIN users u ON u.id = r.user_id
ORDER BY (r.status = 'pending') DESC, r.created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reservations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Reservations</title>
    <link rel="stylesheet" href="style.css?v=5">
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
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title">Reservations</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="admin-table-wrap">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>ID Number</th>
                    <th>Name</th>
                    <th>Course</th>
                    <th>Purpose</th>
                    <th>Lab</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Admin Note</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                    <tr>
                        <td colspan="9" class="empty-table">No reservations found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reservations as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($item['first_name'] . ' ' . ($item['middle_name'] ? $item['middle_name'] . ' ' : '') . $item['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['course'] . ' ' . (int) $item['course_level']); ?></td>
                            <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($item['sit_lab']); ?></td>
                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($item['reservation_date'])) . ' ' . date('h:i A', strtotime($item['reservation_time']))); ?></td>
                            <td><span class="status-badge status-<?php echo htmlspecialchars($item['status']); ?>"><?php echo htmlspecialchars(ucfirst($item['status'])); ?></span></td>
                            <td><?php echo htmlspecialchars($item['admin_note'] ?? ''); ?></td>
                            <td>
                                <?php if ($item['status'] === 'pending'): ?>
                                    <div class="reservation-action-group reservation-action-column">
                                        <form method="POST" class="inline-form reservation-review-form">
                                            <input type="hidden" name="action" value="review_reservation">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="decision" value="approved">
                                            <input type="text" name="admin_note" class="form-control review-note" placeholder="Optional note">
                                            <button type="submit" class="admin-btn admin-btn-primary">Approve</button>
                                        </form>

                                        <form method="POST" class="inline-form reservation-review-form">
                                            <input type="hidden" name="action" value="review_reservation">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="decision" value="rejected">
                                            <input type="text" name="admin_note" class="form-control review-note" placeholder="Reason for reject">
                                            <button type="submit" class="admin-btn admin-btn-danger">Reject</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="empty-text">Reviewed</span>
                                <?php endif; ?>
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
