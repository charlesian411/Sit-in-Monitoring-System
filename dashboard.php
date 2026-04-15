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

$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    author_name VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

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
    CONSTRAINT fk_sit_in_user_student_dashboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

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
    INDEX idx_reservation_schedule (reservation_date, reservation_time),
    CONSTRAINT fk_reservation_user_student_dashboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$reservation_notified_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'student_notified'");
if ($reservation_notified_column && $reservation_notified_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN student_notified TINYINT(1) NOT NULL DEFAULT 0");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_notifications_read')) {
    $notify_stmt = $conn->prepare("UPDATE reservations SET student_notified = 1 WHERE user_id = ? AND status IN ('approved', 'rejected') AND student_notified = 0");
    $notify_stmt->bind_param("i", $_SESSION['user_id']);
    $notify_stmt->execute();
    $notify_stmt->close();

    header("Location: dashboard.php");
    exit();
}

// Fetch full user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$profile_image_path = "";
$existing_images = glob(__DIR__ . "/uploads/profile_" . (int) $_SESSION['user_id'] . ".*");
if (!empty($existing_images)) {
    $profile_image_path = "uploads/" . basename($existing_images[0]);
}

$profile_image_url = "";
if (!empty($profile_image_path) && file_exists(__DIR__ . "/" . $profile_image_path)) {
    $profile_image_url = $profile_image_path . "?v=" . filemtime(__DIR__ . "/" . $profile_image_path);
}

$announcements = [];
$ann_result = $conn->query("SELECT author_name, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 10");
if ($ann_result) {
    while ($ann = $ann_result->fetch_assoc()) {
        $announcements[] = $ann;
    }
}

$reservation_notifications = [];
$reservation_notification_stmt = $conn->prepare("SELECT id, purpose, sit_lab, status, admin_note, reviewed_at FROM reservations WHERE user_id = ? AND status IN ('approved', 'rejected') AND student_notified = 0 ORDER BY reviewed_at DESC, id DESC");
$reservation_notification_stmt->bind_param("i", $_SESSION['user_id']);
$reservation_notification_stmt->execute();
$reservation_notification_res = $reservation_notification_stmt->get_result();
while ($notify_row = $reservation_notification_res->fetch_assoc()) {
    $reservation_notifications[] = $notify_row;
}
$reservation_notification_stmt->close();

$unread_notification_count = count($reservation_notifications);

$remaining_sessions = 30;
$session_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
$session_stmt->bind_param("i", $_SESSION['user_id']);
$session_stmt->execute();
$session_result = $session_stmt->get_result();
$session_row = $session_result->fetch_assoc();
$used_sessions = (int) ($session_row['total_sessions'] ?? 0);
$remaining_sessions = max(0, 30 - $used_sessions);
$session_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Dashboard</title>
    <link rel="stylesheet" href="style.css?v=9">
</head>
<body>

<nav class="student-dashboard-nav">
    <span class="nav-brand">Dashboard</span>
    <ul class="nav-links student-nav-links">
        <li>
            <button type="button" class="dropdown-toggle" id="student-notification-btn">
                Notification<?php if ($unread_notification_count > 0): ?> (<?php echo (int) $unread_notification_count; ?>)<?php endif; ?>
            </button>
        </li>
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="student-logout-btn">Log out</a></li>
    </ul>
</nav>

<div class="student-dashboard-wrapper student-dashboard-page">
    <?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="alert alert-success">Profile updated successfully.</div>
    <?php endif; ?>

    <div class="student-dashboard-layout">
        <section class="student-panel">
            <div class="student-panel-title">Student Information</div>
            <div class="student-panel-body">
                <div class="dashboard-header student-left-header">
                    <div class="avatar <?php echo !empty($profile_image_url) ? 'avatar-photo' : ''; ?>">
                        <?php if (!empty($profile_image_url)): ?>
                            <img src="<?php echo htmlspecialchars($profile_image_url); ?>" alt="Profile Image" class="avatar-img profile-two-by-two">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="student-info-list student-info-icons">
                    <p><strong>👤 Name:</strong> <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?></p>
                    <p><strong>🎓 Course:</strong> <?php echo htmlspecialchars($user['course']); ?></p>
                    <p><strong>↕️ Year:</strong> <?php echo (int) $user['course_level']; ?></p>
                    <p><strong>✉️ Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                    <p><strong>🪪 Address:</strong> <?php echo htmlspecialchars($user['address']); ?></p>
                    <p><strong>⏱️ Session:</strong> <?php echo (int) $remaining_sessions; ?></p>
                </div>
            </div>
        </section>

        <section class="student-panel">
            <div class="student-panel-title">📢 Announcement</div>
            <div class="student-panel-body student-announcement-body">
                <?php if (empty($announcements)): ?>
                    <p class="empty-text">No announcements available.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): ?>
                        <article class="student-announcement-item">
                            <div class="student-announcement-head"><?php echo htmlspecialchars($ann['author_name']); ?> | <?php echo htmlspecialchars(date('Y-M-d', strtotime($ann['created_at']))); ?></div>
                            <div class="student-announcement-content announcement-text-box"><?php echo nl2br(htmlspecialchars($ann['content'])); ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="student-panel">
            <div class="student-panel-title">Rules and Regulation</div>
            <div class="student-panel-body student-rules-body">
                <h3>University of Cebu</h3>
                <h4>COLLEGE OF INFORMATION &amp; COMPUTER STUDIES</h4>
                <h4>LABORATORY RULES AND REGULATIONS</h4>

                <p>To avoid embarrassment and maintain camaraderie with your friends and superiors at our laboratories, please observe the following:</p>
                <ol>
                    <li>Maintain silence, proper decorum, and discipline inside the laboratory. Mobile phones, walkmans and other personal pieces of equipment must be switched off.</li>
                    <li>Games are not allowed inside the lab. This includes computer-related games, card games and other games that may disturb the operation of the lab.</li>
                    <li>Surfing the Internet is allowed only with the permission of the instructor. Downloading and installing software are strictly prohibited.</li>
                    <li>Observe proper care when using laboratory resources. Any damage should be reported immediately.</li>
                </ol>
            </div>
        </section>
    </div>
</div>

<div class="modal-overlay <?php echo $unread_notification_count > 0 ? 'is-open' : ''; ?>" id="student-notification-modal">
    <div class="admin-modal">
        <div class="modal-header">
            <h3>Reservation Alerts</h3>
            <button type="button" class="modal-close" id="student-notification-close">&times;</button>
        </div>

        <?php if ($unread_notification_count === 0): ?>
            <p class="empty-table" style="padding: 0.75rem 0;">No new reservation alerts.</p>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Purpose</th>
                            <th>Lab</th>
                            <th>Admin Note</th>
                            <th>Reviewed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservation_notifications as $notification): ?>
                            <tr>
                                <td>
                                    <?php if ($notification['status'] === 'approved'): ?>
                                        <span class="status-badge status-approved">Approved</span>
                                    <?php else: ?>
                                        <span class="status-badge status-rejected">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($notification['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($notification['sit_lab']); ?></td>
                                <td><?php echo htmlspecialchars($notification['admin_note'] ?: '-'); ?></td>
                                <td><?php echo $notification['reviewed_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($notification['reviewed_at']))) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" style="margin-top: 0.75rem;">
                <input type="hidden" name="action" value="mark_notifications_read">
                <button type="submit" class="admin-btn admin-btn-secondary">Mark as read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('student-notification-modal');
    var openBtn = document.getElementById('student-notification-btn');
    var closeBtn = document.getElementById('student-notification-close');

    if (!modal || !openBtn || !closeBtn) {
        return;
    }

    openBtn.addEventListener('click', function () {
        modal.classList.add('is-open');
    });

    closeBtn.addEventListener('click', function () {
        modal.classList.remove('is-open');
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.classList.remove('is-open');
        }
    });
})();
</script>

</body>
</html>
