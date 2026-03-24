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

$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_user (user_id),
    INDEX idx_reservation_status (status),
    INDEX idx_reservation_schedule (reservation_date, reservation_time),
    CONSTRAINT fk_reservation_user_student FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";
$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT id_number, first_name, middle_name, last_name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_res = $stmt->get_result();
$user = $user_res->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purpose = trim($_POST['purpose'] ?? '');
    $sit_lab = trim($_POST['sit_lab'] ?? '');
    $reservation_date = trim($_POST['reservation_date'] ?? '');
    $reservation_time = trim($_POST['reservation_time'] ?? '');

    $today = date('Y-m-d');

    if ($purpose === '' || $sit_lab === '' || $reservation_date === '' || $reservation_time === '') {
        $alert_message = "Please complete all reservation fields.";
        $alert_type = "error";
    } elseif ($reservation_date < $today) {
        $alert_message = "Reservation date cannot be in the past.";
        $alert_type = "error";
    } else {
        $pending_stmt = $conn->prepare("SELECT id FROM reservations WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $pending_stmt->bind_param("i", $user_id);
        $pending_stmt->execute();
        $pending_stmt->store_result();

        if ($pending_stmt->num_rows > 0) {
            $alert_message = "You already have a pending reservation.";
            $alert_type = "error";
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO reservations (user_id, purpose, sit_lab, reservation_date, reservation_time) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("issss", $user_id, $purpose, $sit_lab, $reservation_date, $reservation_time);

            if ($insert_stmt->execute()) {
                $alert_message = "Reservation submitted. Please wait for admin approval.";
                $alert_type = "success";
            } else {
                $alert_message = "Unable to submit reservation.";
                $alert_type = "error";
            }
            $insert_stmt->close();
        }

        $pending_stmt->close();
    }
}

$reservations = [];
$list_stmt = $conn->prepare("SELECT purpose, sit_lab, reservation_date, reservation_time, status, admin_note, created_at, reviewed_at FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
$list_stmt->bind_param("i", $user_id);
$list_stmt->execute();
$list_res = $list_stmt->get_result();
while ($row = $list_res->fetch_assoc()) {
    $reservations[] = $row;
}
$list_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Reservation</title>
    <link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="admin-page student-reservation-page">
    <h1 class="admin-page-title">Sit-in Reservation</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <section class="admin-card student-reservation-card">
        <div class="admin-card-title">Create Reservation</div>
        <form method="POST" class="student-reservation-form">
            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['id_number']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Student Name</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <input type="text" class="form-control" name="purpose" placeholder="e.g. C Programming" required>
            </div>

            <div class="form-group">
                <label class="form-label">Lab</label>
                <input type="text" class="form-control" name="sit_lab" placeholder="e.g. 524" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reservation Date</label>
                <input type="date" class="form-control" name="reservation_date" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reservation Time</label>
                <input type="time" class="form-control" name="reservation_time" required>
            </div>

            <div class="student-reservation-actions">
                <button type="submit" class="btn-save">Reserve</button>
            </div>
        </form>
    </section>

    <section class="admin-card student-history-card">
        <div class="admin-card-title">My Reservation History</div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Admin Note</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                        <tr>
                            <td colspan="6" class="empty-table">No reservations yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservations as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($item['sit_lab']); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($item['reservation_date'])) . ' ' . date('h:i A', strtotime($item['reservation_time']))); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($item['status']); ?>"><?php echo htmlspecialchars(ucfirst($item['status'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($item['admin_note'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($item['created_at']))); ?></td>
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
