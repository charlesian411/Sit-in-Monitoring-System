<?php
require_once 'config/db.php';
session_start();

// Fetch the top 50 student records from the database
$lb_sql = "SELECT
    u.id AS student_id,
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name,
    u.course,
    u.course_level,
    COUNT(s.id) AS total_sessions,
    COALESCE(SUM(s.points_awarded), 0) AS total_points,
    COALESCE(SUM(TIMESTAMPDIFF(SECOND, s.started_at, s.ended_at)), 0) / 3600.0 AS total_hours,
    (
        (COALESCE(SUM(s.points_awarded), 0) * 0.5) + 
        ((COALESCE(SUM(TIMESTAMPDIFF(SECOND, s.started_at, s.ended_at)), 0) / 3600.0) * 0.3) + 
        (COUNT(s.id) * 0.2)
    ) AS calculated_score
FROM sit_in_records s
INNER JOIN users u ON u.id = s.user_id
WHERE s.status = 'completed'
GROUP BY u.id, u.id_number, u.first_name, u.middle_name, u.last_name, u.course, u.course_level
ORDER BY calculated_score DESC
LIMIT 50";

$leaderboard = [];
$lb_res = $conn->query($lb_sql);
if ($lb_res) {
    while ($row = $lb_res->fetch_assoc()) {
        $leaderboard[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Student Leaderboard</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="f1-leaderboard-page">

<!-- NAVBAR -->
<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="index.php" class="active">Leaderboard</a></li>
        <li><a href="login.php">Login</a></li>
        <li><a href="Register.php">Register</a></li>
    </ul>
</nav>

<div class="f1-container">
    <!-- HEADER -->
    <div class="f1-header">
        <div class="f1-logo-container">
            <span class="f1-logo-text">CCS Championship</span>
        </div>
        <h1 class="f1-title">Student Leaderboard</h1>
        <p class="f1-subtitle">Hall of Fame</p>
    </div>

    <?php if (empty($leaderboard)): ?>
        <div class="empty-state-full" style="text-align: center; padding: 5rem 2rem;">
            <div style="font-size: 3rem; margin-bottom: 1.5rem;">🏁</div>
            <h2>No Completed Sit-in Sessions Yet</h2>
            <p>Complete sit-in sessions to populate the leaderboard and compete for the top ranks!</p>
        </div>
    <?php else: ?>
        <!-- PODIUM SECTION (TOP 3) -->
        <div class="f1-podium">
            
            <!-- 2ND PLACE (McLAREN ORANGE STYLE) -->
            <?php if (isset($leaderboard[1])): 
                $row = $leaderboard[1];
                $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
            ?>
                <div class="f1-podium-card f1-silver">
                    <div class="f1-podium-bg-digit">2</div>
                    <div class="f1-podium-badge">P2</div>
                    <div class="f1-podium-avatar-container">
                        <div class="f1-podium-avatar" style="border-color: #f97316;"><?php echo $initials; ?></div>
                    </div>
                    <h3 class="f1-podium-name"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></h3>
                    <div class="f1-podium-course"><?php echo htmlspecialchars($row['course'] . ' - Year ' . $row['course_level']); ?></div>
                    
                    <div class="f1-podium-score-pill">
                        ⚡ <?php echo number_format($row['calculated_score'], 2); ?>
                    </div>
                    
                    <div class="f1-podium-stats">
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">⭐ <?php echo (int)$row['total_points']; ?></div>
                            <div class="f1-podium-stat-lbl">Points</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">🕒 <?php echo number_format($row['total_hours'], 1); ?>h</div>
                            <div class="f1-podium-stat-lbl">Hours</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">📝 <?php echo (int)$row['total_sessions']; ?></div>
                            <div class="f1-podium-stat-lbl">Sessions</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 1ST PLACE (NEON CYAN STYLE) -->
            <?php if (isset($leaderboard[0])): 
                $row = $leaderboard[0];
                $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
            ?>
                <div class="f1-podium-card f1-gold">
                    <div class="f1-podium-bg-digit">1</div>
                    <div class="f1-podium-badge">P1</div>
                    <div class="f1-podium-avatar-container">
                        <div class="f1-podium-avatar" style="border-color: #06b6d4;"><?php echo $initials; ?></div>
                    </div>
                    <h3 class="f1-podium-name" style="font-size: 1.6rem;"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></h3>
                    <div class="f1-podium-course"><?php echo htmlspecialchars($row['course'] . ' - Year ' . $row['course_level']); ?></div>
                    
                    <div class="f1-podium-score-pill" style="font-size: 1.25rem; padding: 0.6rem 2rem;">
                        👑 <?php echo number_format($row['calculated_score'], 2); ?>
                    </div>
                    
                    <div class="f1-podium-stats">
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">⭐ <?php echo (int)$row['total_points']; ?></div>
                            <div class="f1-podium-stat-lbl">Points</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">🕒 <?php echo number_format($row['total_hours'], 1); ?>h</div>
                            <div class="f1-podium-stat-lbl">Hours</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">📝 <?php echo (int)$row['total_sessions']; ?></div>
                            <div class="f1-podium-stat-lbl">Sessions</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3RD PLACE (FERRARI RED STYLE) -->
            <?php if (isset($leaderboard[2])): 
                $row = $leaderboard[2];
                $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
            ?>
                <div class="f1-podium-card f1-bronze">
                    <div class="f1-podium-bg-digit">3</div>
                    <div class="f1-podium-badge">P3</div>
                    <div class="f1-podium-avatar-container">
                        <div class="f1-podium-avatar" style="border-color: #ef4444;"><?php echo $initials; ?></div>
                    </div>
                    <h3 class="f1-podium-name"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></h3>
                    <div class="f1-podium-course"><?php echo htmlspecialchars($row['course'] . ' - Year ' . $row['course_level']); ?></div>
                    
                    <div class="f1-podium-score-pill">
                        ⚡ <?php echo number_format($row['calculated_score'], 2); ?>
                    </div>
                    
                    <div class="f1-podium-stats">
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">⭐ <?php echo (int)$row['total_points']; ?></div>
                            <div class="f1-podium-stat-lbl">Points</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">🕒 <?php echo number_format($row['total_hours'], 1); ?>h</div>
                            <div class="f1-podium-stat-lbl">Hours</div>
                        </div>
                        <div class="f1-podium-stat">
                            <div class="f1-podium-stat-val">📝 <?php echo (int)$row['total_sessions']; ?></div>
                            <div class="f1-podium-stat-lbl">Sessions</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- LIST SECTION (RANKS 4-50) -->
        <div class="f1-list-section">
            <h2 class="f1-list-header">Championship Standings</h2>
            <div class="f1-list-container">
                <?php 
                $count = count($leaderboard);
                for ($i = 3; $i < $count; $i++): 
                    $row = $leaderboard[$i];
                    $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                    $course_class = 'course-' . strtolower($row['course']);
                    
                    // Assign emoji/icon to course
                    $course_icon = '💻';
                    $badge_class = 'bsit-badge';
                    if (strtoupper($row['course']) === 'BSCS') {
                        $course_icon = '🧠';
                        $badge_class = 'bscs-badge';
                    } elseif (strtoupper($row['course']) === 'BSIS') {
                        $course_icon = '📊';
                        $badge_class = 'bsis-badge';
                    }
                ?>
                    <div class="f1-list-row">
                        <!-- Course indicator color bar on the left -->
                        <div class="f1-course-indicator <?php echo $course_class; ?>"></div>
                        
                        <div class="f1-list-rank"><?php echo $i + 1; ?></div>
                        
                        <div class="f1-list-student">
                            <div class="f1-list-avatar"><?php echo $initials; ?></div>
                            <div class="f1-list-details">
                                <span class="f1-list-name"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></span>
                                <span class="f1-list-id"><?php echo htmlspecialchars($row['id_number']); ?></span>
                            </div>
                        </div>
                        
                        <div class="f1-list-course-badge <?php echo $badge_class; ?>">
                            <span><?php echo $course_icon; ?></span> <?php echo htmlspecialchars($row['course'] . ' ' . $row['course_level']); ?>
                        </div>
                        
                        <div class="f1-list-stats">
                            <div class="f1-list-stat-col">
                                <span class="f1-list-stat-val">⭐ <?php echo (int)$row['total_points']; ?></span>
                                <span class="f1-list-stat-lbl">Points</span>
                            </div>
                            <div class="f1-list-stat-col">
                                <span class="f1-list-stat-val">🕒 <?php echo number_format($row['total_hours'], 1); ?>h</span>
                                <span class="f1-list-stat-lbl">Hours</span>
                            </div>
                            <div class="f1-list-stat-col">
                                <span class="f1-list-stat-val">📝 <?php echo (int)$row['total_sessions']; ?></span>
                                <span class="f1-list-stat-lbl">Sessions</span>
                            </div>
                        </div>
                        
                        <div class="f1-list-score">
                            <div class="f1-list-score-pill">
                                <?php echo number_format($row['calculated_score'], 2); ?>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="theme.js"></script>
</body>
</html>