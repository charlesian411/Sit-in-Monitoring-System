<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: admin_login.php");
    exit();
}

require_once 'config/db.php';

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── LIGHT MODE VARIABLES (Default) ── */
        :root {
            --lb-glass-bg: rgba(255, 255, 255, 0.9);
            --lb-glass-border: rgba(0, 0, 0, 0.1);
            
            --lb-page-bg: #f0f2f5;
            --lb-text-main: #1c2333;
            --lb-text-muted: #4b5563;
            
            --lb-title-gradient: linear-gradient(135deg, #1a3a6b, #3b82f6);
            --lb-card-header-bg: linear-gradient(90deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            --lb-card-header-text: #1a3a6b;
            --lb-card-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.08);
            
            --lb-formula-bg: rgba(255, 255, 255, 0.9);
            --lb-formula-border: rgba(0, 0, 0, 0.1);
            --lb-formula-text: #4b5563;
            --lb-formula-highlight: #2563eb;
            
            --lb-th-bg: rgba(241, 245, 249, 0.9);
            --lb-th-text: #475569;
            --lb-td-border: rgba(0, 0, 0, 0.06);
            --lb-td-text: #334155;
            --lb-tr-hover: rgba(0, 0, 0, 0.02);
            --lb-tr-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            
            --lb-avatar-bg: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            --lb-avatar-border: rgba(139, 92, 246, 0.2);
            --lb-avatar-text: #6d28d9;
            --lb-avatar-shadow: none;
            
            --lb-course-bg: rgba(241, 245, 249, 0.8);
            --lb-course-border: rgba(0, 0, 0, 0.05);
            --lb-course-text: #475569;
            
            --lb-score-bg: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(225, 29, 72, 0.1));
            --lb-score-border: rgba(244, 63, 94, 0.2);
            --lb-score-text: #e11d48;
            --lb-score-shadow: 0 0 10px rgba(225, 29, 72, 0.05);
            --lb-score-text-shadow: none;

            --lb-stat-points: #d97706;
            --lb-stat-hours: #0284c7;
            --lb-stat-sessions: #16a34a;
        }

        /* ── DARK MODE VARIABLES ── */
        [data-theme="dark"] {
            --lb-glass-bg: rgba(30, 41, 59, 0.7);
            --lb-glass-border: rgba(255, 255, 255, 0.08);
            
            --lb-page-bg: #0f172a;
            --lb-text-main: #f8fafc;
            --lb-text-muted: #94a3b8;
            
            --lb-title-gradient: linear-gradient(135deg, #ffffff, #94a3b8);
            --lb-card-header-bg: linear-gradient(90deg, rgba(59, 130, 246, 0.2), rgba(139, 92, 246, 0.2));
            --lb-card-header-text: #f8fafc;
            --lb-card-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            
            --lb-formula-bg: rgba(15, 23, 42, 0.6);
            --lb-formula-border: rgba(255, 255, 255, 0.1);
            --lb-formula-text: #cbd5e1;
            --lb-formula-highlight: #38bdf8;
            
            --lb-th-bg: rgba(15, 23, 42, 0.4);
            --lb-th-text: #94a3b8;
            --lb-td-border: rgba(255, 255, 255, 0.03);
            --lb-td-text: #e2e8f0;
            --lb-tr-hover: rgba(255, 255, 255, 0.03);
            --lb-tr-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            
            --lb-avatar-bg: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(139, 92, 246, 0.2));
            --lb-avatar-border: rgba(139, 92, 246, 0.3);
            --lb-avatar-text: #a78bfa;
            --lb-avatar-shadow: inset 0 0 10px rgba(139, 92, 246, 0.1);
            
            --lb-course-bg: rgba(51, 65, 85, 0.5);
            --lb-course-border: rgba(255, 255, 255, 0.05);
            --lb-course-text: #cbd5e1;
            
            --lb-score-bg: linear-gradient(135deg, rgba(249, 115, 22, 0.2), rgba(225, 29, 72, 0.2));
            --lb-score-border: rgba(244, 63, 94, 0.3);
            --lb-score-text: #fda4af;
            --lb-score-shadow: 0 0 15px rgba(225, 29, 72, 0.15);
            --lb-score-text-shadow: 0 1px 2px rgba(0,0,0,0.5);

            --lb-stat-points: #fbbf24;
            --lb-stat-hours: #38bdf8;
            --lb-stat-sessions: #4ade80;
        }

        /* Prevent overriding body background, rely on style.css, but handle font */
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--lb-page-bg); /* Inherits properly via theme toggle */
        }

        .leaderboard-container {
            max-width: 1200px;
            margin: 3rem auto;
            padding: 0 1.5rem;
        }

        .leaderboard-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            animation: fadeInDown 0.6s ease-out;
        }

        .leaderboard-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0;
            background: var(--lb-title-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .trophy-icon {
            font-size: 2.5rem;
            filter: drop-shadow(0 0 15px rgba(250, 204, 21, 0.4));
        }

        .leaderboard-card {
            background: var(--lb-glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--lb-glass-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--lb-card-shadow);
            animation: fadeInUp 0.8s ease-out;
        }

        .card-header-gradient {
            background: var(--lb-card-header-bg);
            border-bottom: 1px solid var(--lb-glass-border);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-header-gradient h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--lb-card-header-text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .formula-badge {
            background: var(--lb-formula-bg);
            border: 1px solid var(--lb-formula-border);
            padding: 0.5rem 1rem;
            border-radius: 99px;
            font-size: 0.85rem;
            color: var(--lb-formula-text);
            font-family: monospace;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }

        .formula-highlight { color: var(--lb-formula-highlight); font-weight: 600; }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .modern-table th {
            background: var(--lb-th-bg);
            padding: 1rem 1.5rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--lb-th-text);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--lb-glass-border);
        }

        .modern-table td {
            padding: 1.25rem 1.5rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--lb-td-border);
            color: var(--lb-td-text);
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .modern-table tbody tr {
            transition: all 0.3s ease;
        }

        .modern-table tbody tr:hover {
            background: var(--lb-tr-hover);
            transform: translateY(-2px);
            box-shadow: var(--lb-tr-shadow);
            position: relative;
            z-index: 10;
        }

        .medal-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            font-size: 0.9rem;
            font-weight: 700;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
        }

        .medal-1 {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            color: #fff;
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.3), inset 0 2px 4px rgba(255,255,255,0.4);
        }
        .medal-2 {
            background: linear-gradient(135deg, #e2e8f0, #94a3b8);
            color: #fff;
            box-shadow: 0 0 15px rgba(148, 163, 184, 0.3), inset 0 2px 4px rgba(255,255,255,0.4);
        }
        .medal-3 {
            background: linear-gradient(135deg, #f97316, #b45309);
            color: #fff;
            box-shadow: 0 0 15px rgba(217, 119, 6, 0.3), inset 0 2px 4px rgba(255,255,255,0.4);
        }
        
        .rank-number {
            font-weight: 600;
            color: var(--lb-text-muted);
            padding-left: 0.75rem;
        }

        .student-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--lb-avatar-bg);
            border: 1px solid var(--lb-avatar-border);
            color: var(--lb-avatar-text);
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-transform: uppercase;
            box-shadow: var(--lb-avatar-shadow);
        }

        .student-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .student-name {
            font-weight: 600;
            color: var(--lb-text-main);
            letter-spacing: 0.02em;
        }

        .student-id {
            font-size: 0.8rem;
            color: var(--lb-text-muted);
            font-family: monospace;
        }

        .course-badge {
            background: var(--lb-course-bg);
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--lb-course-text);
            border: 1px solid var(--lb-course-border);
        }

        .stat-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
        }

        .stat-points { color: var(--lb-stat-points); }
        .stat-hours { color: var(--lb-stat-hours); }
        .stat-sessions { color: var(--lb-stat-sessions); }

        .score-pill {
            background: var(--lb-score-bg);
            color: var(--lb-score-text);
            border: 1px solid var(--lb-score-border);
            padding: 0.4rem 1rem;
            border-radius: 99px;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-block;
            min-width: 60px;
            text-align: center;
            box-shadow: var(--lb-score-shadow);
            text-shadow: var(--lb-score-text-shadow);
        }

        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
            color: var(--lb-text-muted);
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .card-header-gradient {
                flex-direction: column;
                align-items: flex-start;
            }
            .formula-badge {
                width: 100%;
                text-align: center;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_current_sitin.php">Current Sitin</a></li>
        <li><a href="admin_sitin_history.php">Sit-in Records</a></li>
        <li><a href="admin_reports.php">Reports</a></li>
        <li><a href="admin_feedback_reports.php">Feedback</a></li>
        <li><a href="admin_leaderboard.php">Leaderboard</a></li>
        <li><a href="admin_lab_software.php">Lab Software</a></li>
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="leaderboard-container">
    <div class="leaderboard-header">
        <span class="trophy-icon">🏆</span>
        <h1>Leaderboard Hall of Fame</h1>
    </div>

    <div class="leaderboard-card">
        <div class="card-header-gradient">
            <h2>🏆 Top Performers</h2>
            <div class="formula-badge">
                <span class="formula-highlight">Score</span> = (Points * 0.5) + (Hours * 0.3) + (Sessions * 0.2)
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Explorer</th>
                        <th>Course & Year</th>
                        <th>Points</th>
                        <th>Total Hours</th>
                        <th>Sessions</th>
                        <th>Mastery Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaderboard)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-icon">🛸</div>
                                    <h3 style="margin-bottom:0.5rem; color:var(--lb-text-main);">No records found</h3>
                                    <p style="margin:0; font-size:0.9rem;">Wait for students to complete their sit-in sessions to populate the leaderboard.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $rank = 1;
                        foreach ($leaderboard as $row): 
                            $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                            
                            $medal_class = "";
                            if ($rank === 1) $medal_class = "medal-1";
                            elseif ($rank === 2) $medal_class = "medal-2";
                            elseif ($rank === 3) $medal_class = "medal-3";
                        ?>
                            <tr>
                                <td>
                                    <?php if ($rank <= 3): ?>
                                        <span class="medal-badge <?php echo $medal_class; ?>"><?php echo $rank; ?></span>
                                    <?php else: ?>
                                        <span class="rank-number">#<?php echo $rank; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="student-cell">
                                        <div class="student-avatar"><?php echo $initials; ?></div>
                                        <div class="student-info">
                                            <span class="student-name"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></span>
                                            <span class="student-id"><?php echo htmlspecialchars($row['id_number']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="course-badge">
                                        <?php echo htmlspecialchars($row['course'] . ' ' . $row['course_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="stat-icon stat-points">
                                        ⭐ <?php echo (int) $row['total_points']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="stat-icon stat-hours">
                                        🕒 <?php echo number_format($row['total_hours'], 1); ?>h
                                    </span>
                                </td>
                                <td>
                                    <span class="stat-icon stat-sessions">
                                        📝 <?php echo (int) $row['total_sessions']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="score-pill">
                                        <?php echo number_format($row['calculated_score'], $row['calculated_score'] == round($row['calculated_score'], 1) ? 1 : 2); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php 
                            $rank++;
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="theme.js"></script>
</body>
</html>
