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
        <li class="dropdown">
            <button class="dropdown-toggle">Notification</button>
            <div class="dropdown-menu">
                <?php if (empty($announcements)): ?>
                    <a href="dashboard.php">No new notifications</a>
                <?php else: ?>
                    <?php foreach (array_slice($announcements, 0, 3) as $notice): ?>
                        <a href="dashboard.php"><?php echo htmlspecialchars(strlen($notice['content']) > 45 ? substr($notice['content'], 0, 45) . '...' : $notice['content']); ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </li>
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="reservation.php">History</a></li>
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

</body>
</html>
