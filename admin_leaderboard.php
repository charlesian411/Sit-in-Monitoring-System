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

$leaderboard = [];
$lb_sql = "SELECT
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name,
    u.course,
    COUNT(s.id) AS total_sessions,
    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.started_at, s.ended_at)), 0) AS total_minutes
FROM sit_in_records s
INNER JOIN users u ON u.id = s.user_id
WHERE s.status = 'completed'
GROUP BY u.id, u.id_number, u.first_name, u.middle_name, u.last_name, u.course
ORDER BY total_sessions DESC, total_minutes DESC
LIMIT 50";

$lb_res = $conn->query($lb_sql);
if ($lb_res) {
    while ($row = $lb_res->fetch_assoc()) {
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
    <link rel="stylesheet" href="style.css?v=13">
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

<div class="admin-page">
    <h1 class="admin-page-title">🏆 Sit-In Leaderboard</h1>

    <?php if (empty($leaderboard)): ?>
        <div class="admin-card">
            <div class="admin-card-title">Rankings</div>
            <p class="empty-text" style="padding: 1.5rem; text-align: center;">No completed sit-in sessions yet.</p>
        </div>
    <?php else: ?>
        <div class="leaderboard-podium">
            <?php
                $podium_order = [1, 0, 2];
                $medal_icons = ['🥇', '🥈', '🥉'];
                $podium_classes = ['podium-gold', 'podium-silver', 'podium-bronze'];
            ?>
            <?php foreach ($podium_order as $pi): ?>
                <?php if (isset($leaderboard[$pi])): ?>
                    <?php
                        $p = $leaderboard[$pi];
                        $p_name = trim($p['first_name'] . ' ' . ($p['middle_name'] ? substr($p['middle_name'], 0, 1) . '. ' : '') . $p['last_name']);
                        $p_hours = round((int) $p['total_minutes'] / 60, 1);
                    ?>
                    <div class="podium-card <?php echo $podium_classes[$pi]; ?>">
                        <div class="podium-medal"><?php echo $medal_icons[$pi]; ?></div>
                        <div class="podium-rank">#<?php echo $pi + 1; ?></div>
                        <div class="podium-name"><?php echo htmlspecialchars($p_name); ?></div>
                        <div class="podium-id"><?php echo htmlspecialchars($p['id_number']); ?></div>
                        <div class="podium-stats">
                            <span><strong><?php echo (int) $p['total_sessions']; ?></strong> sessions</span>
                            <span><strong><?php echo $p_hours; ?></strong> hrs</span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table students-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>ID Number</th>
                        <th>Student Name</th>
                        <th>Course</th>
                        <th>Total Sessions</th>
                        <th>Total Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaderboard as $rank => $student): ?>
                        <?php
                            $name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
                            $hours = round((int) $student['total_minutes'] / 60, 1);
                            $medal = '';
                            if ($rank === 0) $medal = '🥇 ';
                            elseif ($rank === 1) $medal = '🥈 ';
                            elseif ($rank === 2) $medal = '🥉 ';
                        ?>
                        <tr class="<?php echo $rank < 3 ? 'leaderboard-top3' : ''; ?>">
                            <td><strong><?php echo $medal . ($rank + 1); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <td><?php echo htmlspecialchars($student['course'] ?? ''); ?></td>
                            <td><strong><?php echo (int) $student['total_sessions']; ?></strong></td>
                            <td><?php echo $hours; ?> hrs</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>


<script src="theme.js"></script>
</body>
</html>
