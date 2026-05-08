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
    CONSTRAINT fk_sit_in_user_leaderboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$lb_sql = "SELECT
    u.id AS student_id,
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name,
    u.course,
    COUNT(s.id) AS total_sessions,
    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.started_at, s.ended_at)), 0) AS total_minutes,
    (COUNT(s.id) * 100) + ROUND((COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.started_at, s.ended_at)), 0) / 60) * 50) AS total_points
FROM sit_in_records s
INNER JOIN users u ON u.id = s.user_id
WHERE s.status = 'completed'
GROUP BY u.id, u.id_number, u.first_name, u.middle_name, u.last_name, u.course
ORDER BY total_points DESC, total_sessions DESC
LIMIT 50";

$leaderboard = [];
$lb_res = $conn->query($lb_sql);
if ($lb_res) {
    while ($row = $lb_res->fetch_assoc()) {
        $student_profile_image = "";
        $student_images = glob(__DIR__ . "/uploads/profile_" . (int) $row['student_id'] . ".*");
        if (!empty($student_images)) {
            $student_profile_image = "uploads/" . basename($student_images[0]);
        }
        $row['profile_image_url'] = "";
        if ($student_profile_image !== "" && file_exists(__DIR__ . "/" . $student_profile_image)) {
            $row['profile_image_url'] = $student_profile_image . "?v=" . filemtime(__DIR__ . "/" . $student_profile_image);
        }
        $leaderboard[] = $row;
    }
}

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_count_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_count_row['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Leaderboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
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
        <li><a href="admin_reports.php">Reports</a></li>
        <li><a href="admin_feedback_reports.php">Feedback Reports</a></li>
        <li><a href="admin_leaderboard.php">Leaderboard</a></li>
        <li><a href="admin_lab_software.php">Lab Software</a></li>
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page leaderboard-game-page">
    <div class="leaderboard-header">
        <h1 class="game-title">SIT-IN<span>X</span></h1>
        <div class="game-subtitle">Leaderboard</div>
    </div>

    <?php if (empty($leaderboard)): ?>
        <div class="empty-state-full">No completed sessions yet.</div>
    <?php else: ?>
        <!-- Podium Section -->
        <div class="game-podium">
            <?php 
                $podium_positions = [
                    ['rank' => 2, 'index' => 1, 'class' => 'game-podium-silver', 'color' => '#3b82f6'],
                    ['rank' => 1, 'index' => 0, 'class' => 'game-podium-gold', 'color' => '#f59e0b'],
                    ['rank' => 3, 'index' => 2, 'class' => 'game-podium-bronze', 'color' => '#ec4899']
                ];
            ?>
            <?php foreach ($podium_positions as $config): ?>
                <?php if (isset($leaderboard[$config['index']])): 
                    $p = $leaderboard[$config['index']];
                    $p_name = $p['first_name'] . ' ' . $p['last_name'];
                ?>
                    <div class="game-podium-card <?php echo $config['class']; ?>">
                        <div class="game-podium-rank-star" style="background: <?php echo $config['color']; ?>">
                            <span><?php echo $config['rank']; ?></span>
                        </div>
                        <div class="game-podium-avatar-wrap">
                            <?php if (!empty($p['profile_image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($p['profile_image_url']); ?>" alt="Avatar" class="game-podium-avatar">
                            <?php else: ?>
                                <div class="game-podium-avatar-placeholder">
                                    <?php echo strtoupper(substr($p['first_name'],0,1).substr($p['last_name'],0,1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="game-podium-info">
                            <div class="game-podium-name"><?php echo htmlspecialchars($p_name); ?></div>
                            <div class="game-podium-handle">@<?php echo htmlspecialchars($p['id_number']); ?></div>
                            <div class="game-podium-score" style="color: <?php echo $config['color']; ?>">
                                <?php echo number_format($p['total_points']); ?> <span>PTS</span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Rank List Section -->
        <div class="game-rank-list">
            <?php foreach ($leaderboard as $rank => $student): if ($rank < 3) continue; ?>
                <div class="game-rank-item">
                    <div class="game-rank-num">
                        <span><?php echo $rank + 1; ?></span>
                    </div>
                    <div class="game-rank-user">
                        <?php if (!empty($student['profile_image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($student['profile_image_url']); ?>" alt="Avatar" class="game-rank-avatar">
                        <?php else: ?>
                            <div class="game-rank-avatar-placeholder">
                                <?php echo strtoupper(substr($student['first_name'],0,1).substr($student['last_name'],0,1)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="game-rank-details">
                            <div class="game-rank-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                            <div class="game-rank-handle">@<?php echo htmlspecialchars($student['id_number']); ?></div>
                        </div>
                    </div>
                    <div class="game-rank-score-wrap">
                        <div class="game-rank-score"><?php echo number_format($student['total_points']); ?> PTS</div>
                        <div class="game-rank-progress-bg">
                            <?php 
                                $max_points = (int)$leaderboard[0]['total_points'];
                                $percent = $max_points > 0 ? round(($student['total_points'] / $max_points) * 100) : 0;
                            ?>
                            <div class="game-rank-progress-fill" style="width: <?php echo $percent; ?>%;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="theme.js"></script>
</body>
</html>
