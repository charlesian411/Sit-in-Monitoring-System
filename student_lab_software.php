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

$conn->query("CREATE TABLE IF NOT EXISTS lab_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(50) NOT NULL,
    software_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_software (lab_name, software_name)
)");

$lab_options = ['524', '526', '528', '530', '542', '544'];

$software_by_lab = [];
foreach ($lab_options as $lab) {
    $software_by_lab[$lab] = [];
}

$sw_res = $conn->query("SELECT lab_name, software_name FROM lab_software ORDER BY lab_name ASC, software_name ASC");
if ($sw_res) {
    while ($row = $sw_res->fetch_assoc()) {
        $lab = $row['lab_name'];
        if (isset($software_by_lab[$lab])) {
            $software_by_lab[$lab][] = $row['software_name'];
        }
    }
}

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
    student_notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_user (user_id),
    INDEX idx_reservation_status (status),
    CONSTRAINT fk_reservation_user_student_lab_sw FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$reservation_notified_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'student_notified'");
if ($reservation_notified_column && $reservation_notified_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN student_notified TINYINT(1) NOT NULL DEFAULT 0");
}

$reservation_notifications = [];
$reservation_notification_stmt = $conn->prepare("SELECT id FROM reservations WHERE user_id = ? AND status IN ('approved', 'rejected') AND student_notified = 0");
$reservation_notification_stmt->bind_param("i", $_SESSION['user_id']);
$reservation_notification_stmt->execute();
$reservation_notification_res = $reservation_notification_stmt->get_result();
$unread_notification_count = $reservation_notification_res->num_rows;
$reservation_notification_stmt->close();

// Active PCs per lab (for showing availability counts)
$active_count_by_lab = [];
foreach ($lab_options as $lab) {
    $active_count_by_lab[$lab] = 0;
}

$active_res = $conn->query("SELECT sit_lab, COUNT(*) AS cnt FROM sit_in_records WHERE status = 'active' GROUP BY sit_lab");
if ($active_res) {
    while ($row = $active_res->fetch_assoc()) {
        $lab = $row['sit_lab'];
        if (isset($active_count_by_lab[$lab])) {
            $active_count_by_lab[$lab] = (int) $row['cnt'];
        }
    }
}

$max_pcs = 40;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Lab Software Availability</title>
    <link rel="stylesheet" href="style.css?v=13">
</head>
<body>

<nav class="student-dashboard-nav">
    <span class="nav-brand">Dashboard</span>
    <ul class="nav-links student-nav-links">
        <li>
            <button type="button" class="dropdown-toggle" onclick="window.location.href='dashboard.php'">
                Notification<?php if ($unread_notification_count > 0): ?> (<?php echo (int) $unread_notification_count; ?>)<?php endif; ?>
            </button>
        </li>
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="student_lab_software.php">Lab Software</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="student-logout-btn">Log out</a></li>
    </ul>
</nav>

<div class="admin-page" style="max-width: 1100px;">
    <h1 class="admin-page-title">Software Availability per Laboratory</h1>

    <div class="lab-sw-student-grid">
        <?php foreach ($lab_options as $lab): ?>
            <?php
                $active_pcs = $active_count_by_lab[$lab];
                $available_pcs = $max_pcs - $active_pcs;
                $sw_list = $software_by_lab[$lab];
            ?>
            <div class="lab-sw-student-card">
                <div class="lab-sw-student-header">
                    <div class="lab-sw-student-title">Lab <?php echo htmlspecialchars($lab); ?></div>
                    <div class="lab-sw-student-avail">
                        <span class="lab-avail-dot <?php echo $available_pcs > 0 ? 'lab-avail-open' : 'lab-avail-full'; ?>"></span>
                        <?php echo $available_pcs; ?>/<?php echo $max_pcs; ?> PCs available
                    </div>
                </div>
                <div class="lab-sw-student-body">
                    <?php if (empty($sw_list)): ?>
                        <p class="empty-text">No software listed for this lab.</p>
                    <?php else: ?>
                        <div class="lab-sw-student-tags">
                            <?php foreach ($sw_list as $sw_name): ?>
                                <span class="lab-sw-tag"><?php echo htmlspecialchars($sw_name); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>


<script src="theme.js"></script>
</body>
</html>
