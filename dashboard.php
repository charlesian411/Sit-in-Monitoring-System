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
    pc_number VARCHAR(10) NULL,
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

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('message', 'event', 'task', 'alert', 'reservation') NOT NULL DEFAULT 'alert',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_read (is_read),
    CONSTRAINT fk_notif_user_dashboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'mark_notifications_read')) {
    $notify_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $notify_stmt->bind_param("i", $_SESSION['user_id']);
    $notify_stmt->execute();
    $notify_stmt->close();

    // Also mark legacy reservation notifications as read
    $res_stmt = $conn->prepare("UPDATE reservations SET student_notified = 1 WHERE user_id = ? AND student_notified = 0");
    $res_stmt->bind_param("i", $_SESSION['user_id']);
    $res_stmt->execute();
    $res_stmt->close();

    header("Location: dashboard.php");
    exit();
}

// Sync reservations to notifications table
$sync_res = $conn->prepare("SELECT id, status, reviewed_at FROM reservations WHERE user_id = ? AND student_notified = 0 AND status IN ('approved', 'rejected')");
$sync_res->bind_param("i", $_SESSION['user_id']);
$sync_res->execute();
$sync_data = $sync_res->get_result();
while ($row = $sync_data->fetch_assoc()) {
    $notif_title = "Reservation " . ucfirst($row['status']);
    $notif_msg = "Your lab reservation has been " . $row['status'] . ".";
    // Avoid duplicates
    $check_exists = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND title = ? AND created_at = ?");
    $check_exists->bind_param("iss", $_SESSION['user_id'], $notif_title, $row['reviewed_at']);
    $check_exists->execute();
    if ($check_exists->get_result()->num_rows === 0) {
        $ins_notif = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'reservation', ?, ?, ?)");
        $ins_notif->bind_param("isss", $_SESSION['user_id'], $notif_title, $notif_msg, $row['reviewed_at']);
        $ins_notif->execute();
        $ins_notif->close();
    }
    $check_exists->close();
}
$sync_res->close();

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

$notifications = [];
$notif_stmt = $conn->prepare("SELECT id, type, title, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$notif_stmt->bind_param("i", $_SESSION['user_id']);
$notif_stmt->execute();
$notif_res = $notif_stmt->get_result();
while ($row = $notif_res->fetch_assoc()) {
    $notifications[] = $row;
}
$notif_stmt->close();

$unread_notification_count = 0;
foreach($notifications as $n) { if(!$n['is_read']) $unread_notification_count++; }

$remaining_sessions = 30;
$session_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
$session_stmt->bind_param("i", $_SESSION['user_id']);
$session_stmt->execute();
$session_result = $session_stmt->get_result();
$session_row = $session_result->fetch_assoc();
$used_sessions = (int) ($session_row['total_sessions'] ?? 0);
$remaining_sessions = max(0, 30 - $used_sessions);
$session_stmt->close();

// Sit-in summary stats
$summary_stats = [
    'total_hours' => 0,
    'num_sessions' => 0,
    'avg_duration' => 0,
    'longest_session' => 0
];

$summary_stmt = $conn->prepare("SELECT 
    COUNT(*) AS num_sessions,
    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, started_at, ended_at)), 0) AS total_minutes,
    COALESCE(AVG(TIMESTAMPDIFF(MINUTE, started_at, ended_at)), 0) AS avg_minutes,
    COALESCE(MAX(TIMESTAMPDIFF(MINUTE, started_at, ended_at)), 0) AS longest_minutes
    FROM sit_in_records WHERE user_id = ? AND status = 'completed' AND ended_at IS NOT NULL");
$summary_stmt->bind_param("i", $_SESSION['user_id']);
$summary_stmt->execute();
$summary_res = $summary_stmt->get_result();
$summary_row = $summary_res->fetch_assoc();
if ($summary_row) {
    $summary_stats['num_sessions'] = (int) $summary_row['num_sessions'];
    $summary_stats['total_hours'] = round((int) $summary_row['total_minutes'] / 60, 1);
    $summary_stats['avg_duration'] = round((int) $summary_row['avg_minutes'] / 60, 1);
    $summary_stats['longest_session'] = round((int) $summary_row['longest_minutes'] / 60, 1);
}
$summary_stmt->close();

// Recent sessions for table
$recent_sessions = [];
$recent_stmt = $conn->prepare("SELECT id, purpose, sit_lab, pc_number, status, started_at, ended_at,
    TIMESTAMPDIFF(MINUTE, started_at, ended_at) AS duration_minutes
    FROM sit_in_records WHERE user_id = ? ORDER BY started_at DESC LIMIT 10");
$recent_stmt->bind_param("i", $_SESSION['user_id']);
$recent_stmt->execute();
$recent_res = $recent_stmt->get_result();
while ($row = $recent_res->fetch_assoc()) {
    $recent_sessions[] = $row;
}
$recent_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Dashboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
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
        <li><a href="student_lab_software.php">Lab Software</a></li>
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

    <!-- Sit-In Summary -->
    <div class="sitin-summary-row">
        <div class="summary-stat-card">
            <div class="summary-stat-value"><?php echo $summary_stats['total_hours']; ?> hrs</div>
            <div class="summary-stat-label">Total Sit-In Hours</div>
        </div>
        <div class="summary-stat-card">
            <div class="summary-stat-value"><?php echo $summary_stats['num_sessions']; ?></div>
            <div class="summary-stat-label">Number of Sessions</div>
        </div>
        <div class="summary-stat-card">
            <div class="summary-stat-value"><?php echo $summary_stats['avg_duration']; ?> hrs</div>
            <div class="summary-stat-label">Avg Session Duration</div>
        </div>
        <div class="summary-stat-card">
            <div class="summary-stat-value"><?php echo $summary_stats['longest_session']; ?> hrs</div>
            <div class="summary-stat-label">Longest Session</div>
        </div>
    </div>

    <!-- Sessions Table -->
    <section class="student-panel" style="min-height: auto;">
        <div class="student-panel-title">📋 My Recent Sessions</div>
        <div class="admin-table-wrap" style="border: none; border-radius: 0;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Duration</th>
                        <th>PC No.</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_sessions)): ?>
                        <tr>
                            <td colspan="6" class="empty-table">No sessions yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_sessions as $sess): ?>
                            <?php
                                $dur_min = (int) ($sess['duration_minutes'] ?? 0);
                                $d_h = floor($dur_min / 60);
                                $d_m = $dur_min % 60;
                                $dur_display = $sess['ended_at'] ? (($d_h > 0 ? $d_h . 'h ' : '') . $d_m . 'm') : '-';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($sess['started_at']))); ?></td>
                                <td><?php echo htmlspecialchars(date('h:i A', strtotime($sess['started_at']))); ?></td>
                                <td><?php echo $sess['ended_at'] ? htmlspecialchars(date('h:i A', strtotime($sess['ended_at']))) : '-'; ?></td>
                                <td><?php echo $dur_display; ?></td>
                                <td><?php echo htmlspecialchars($sess['pc_number'] ?? '-'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($sess['status']); ?>">
                                        <?php echo htmlspecialchars(ucfirst($sess['status'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal-overlay" id="student-notification-modal">
    <div class="notif-modal-card">
        <div class="notif-modal-header">
            <div class="notif-header-left">
                <h2>Notifications</h2>
                <p>You have <?php echo (int) $unread_notification_count; ?> new notifications.</p>
            </div>
            <div class="notif-header-right">
                <button type="button" class="notif-modal-close" id="student-notification-close">&times;</button>
            </div>
        </div>

        <div class="notif-modal-body">
            <?php if (empty($notifications)): ?>
                <div class="notif-empty-state">
                    <p>No notifications yet.</p>
                </div>
            <?php else: ?>
                <div class="notif-timeline">
                    <?php foreach ($notifications as $notif): 
                        $icon = '🔔';
                        $color = '#3b82f6';
                        if ($notif['type'] === 'message') { $icon = '✉️'; $color = '#f59e0b'; }
                        elseif ($notif['type'] === 'event') { $icon = '📅'; $color = '#10b981'; }
                        elseif ($notif['type'] === 'task') { $icon = '✅'; $color = '#8b5cf6'; }
                        elseif ($notif['type'] === 'alert') { $icon = '⚠️'; $color = '#ef4444'; }
                        elseif ($notif['type'] === 'reservation') { $icon = '🖥️'; $color = '#06b6d4'; }
                        
                        $time_ago = 'Just now';
                        $diff = time() - strtotime($notif['created_at']);
                        if ($diff >= 604800) $time_ago = floor($diff / 604800) . ' week ago';
                        elseif ($diff >= 86400) $time_ago = floor($diff / 86400) . ' day ago';
                        elseif ($diff >= 3600) $time_ago = floor($diff / 3600) . ' hours ago';
                        elseif ($diff >= 60) $time_ago = floor($diff / 60) . ' mins ago';
                    ?>
                        <div class="notif-item <?php echo $notif['is_read'] ? '' : 'is-unread'; ?>">
                            <div class="notif-icon-wrap">
                                <div class="notif-icon-circle" style="border-color: <?php echo $color; ?>; color: <?php echo $color; ?>">
                                    <?php echo $icon; ?>
                                </div>
                                <div class="notif-timeline-line"></div>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title-row">
                                    <h4 class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></h4>
                                    <span class="notif-time"><?php echo $time_ago; ?></span>
                                </div>
                                <p class="notif-message"><?php echo htmlspecialchars($notif['message']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="notif-modal-footer">
            <form method="POST">
                <input type="hidden" name="action" value="mark_notifications_read">
                <button type="submit" class="notif-mark-read-btn">Mark all as read</button>
            </form>
        </div>
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

<script src="theme.js"></script>
</body>
</html>
