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
    status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    points_awarded TINYINT(1) DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    INDEX idx_sit_in_user (user_id),
    INDEX idx_sit_in_status (status),
    CONSTRAINT fk_sit_in_user_current FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";

if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'end_sitin') {
    $record_id = (int) ($_REQUEST['record_id'] ?? 0);
    $points_awarded = isset($_REQUEST['award_point']) ? 1 : 0;
    if ($record_id > 0) {
        $stmt = $conn->prepare("UPDATE sit_in_records SET status = 'completed', ended_at = NOW(), points_awarded = ? WHERE id = ? AND status = 'active'");
        $stmt->bind_param("ii", $points_awarded, $record_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $alert_message = "Sit-in session completed.";
            $alert_type = "success";
        } else {
            $alert_message = "Unable to update sit-in status.";
            $alert_type = "error";
        }
        $stmt->close();
    }
}

$records = [];
$sql = "SELECT
            s.id,
            s.user_id,
            s.purpose,
            s.sit_lab,
            s.status,
            s.started_at,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.course,
            u.course_level,
            (
                SELECT COUNT(*)
                FROM sit_in_records s2
                WHERE s2.user_id = s.user_id AND s2.id <= s.id
            ) AS session_number
        FROM sit_in_records s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.status = 'active'
        ORDER BY s.started_at DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $records[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Current Sit In</title>
    <link rel="stylesheet" href="style.css?v=4">
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
        <li><a href="admin_reservations.php">Reservations</a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <h1 class="admin-page-title" style="text-align: left; font-size: 1.8rem; font-weight: 700; color: #1e3a8a; margin-bottom: 1.5rem;">Current Sit-in</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="admin-card" style="border-radius: 8px; border: 1px solid #dde1e7; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="admin-card-header" style="background: #1e3a8a; color: #fff; padding: 0.75rem 1rem; display: flex; align-items: center; justify-content: space-between;">
            <h2 style="margin: 0; font-size: 1.05rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                Students Currently in the Lab
                <span class="badge-pill" style="background: rgba(255,255,255,0.2); color: #fff; padding: 0.15rem 0.5rem; font-size: 0.75rem; border-radius: 12px; font-weight: 500; min-width: auto; height: auto;"><?php echo count($records); ?> active</span>
            </h2>
        </div>
        <div class="admin-table-wrap" style="border: none; border-radius: 0;">
            <table class="admin-table students-table" style="min-width: 100%;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">#</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">ID NUMBER</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">NAME</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">COURSE & YEAR</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">PURPOSE</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">LAB</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">TIME IN</th>
                        <th style="font-weight: 600; color: #6b7280; font-size: 0.75rem; padding: 0.85rem 1rem; text-align: left;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="empty-table" style="padding: 2rem; text-align: center; color: #6b7280;">No data available.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $row_num = 1;
                        foreach ($records as $record): 
                        ?>
                            <tr>
                                <td style="padding: 1rem; text-align: left;"><?php echo $row_num++; ?></td>
                                <td style="padding: 1rem; text-align: left;"><?php echo htmlspecialchars($record['id_number']); ?></td>
                                <td style="padding: 1rem; text-align: left; font-weight: 500; color: #111827;"><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                <td style="padding: 1rem; text-align: left;"><?php echo htmlspecialchars($record['course'] . ' ' . $record['course_level']); ?></td>
                                <td style="padding: 1rem; text-align: left;"><?php echo htmlspecialchars($record['purpose']); ?></td>
                                <td style="padding: 1rem; text-align: left;"><?php echo htmlspecialchars($record['sit_lab']); ?></td>
                                <td style="padding: 1rem; text-align: left; color: #6b7280;"><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($record['started_at']))); ?></td>
                                <td style="padding: 1rem; text-align: left;">
                                    <button type="button" 
                                            class="admin-btn admin-btn-danger btn-logout-trigger" 
                                            data-id="<?php echo (int) $record['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>"
                                            style="background: #dc2626; color: #fff; font-weight: 600; border-radius: 6px; padding: 0.45rem 1rem; cursor: pointer; border: none;">
                                        Logout
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Logout Modal -->
<div class="modal-overlay" id="logout-student-modal">
    <div class="admin-modal" style="max-width: 440px; border-radius: 12px; overflow: hidden; padding: 1.25rem; border: none; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
        <div class="modal-header" style="border: none; padding: 0 0 1rem 0; display: flex; align-items: center; justify-content: space-between;">
            <h3 style="font-size: 1.15rem; font-weight: 600; color: #1e3a8a; margin: 0;">Logout Student</h3>
            <button type="button" class="modal-close-btn" style="background: none; border: none; font-size: 1.5rem; color: #9ca3af; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        
        <form method="POST" action="admin_current_sitin.php" id="logout-student-form">
            <input type="hidden" name="action" value="end_sitin">
            <input type="hidden" name="record_id" id="logout-record-id" value="">
            
            <p style="font-size: 0.9rem; color: #4b5563; margin-bottom: 1.25rem; margin-top: 0;">
                You are about to log out <strong id="logout-student-name" style="color: #1f2937;"></strong>.
            </p>
            
            <div style="background: #f3f4f6; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem; display: flex; gap: 0.75rem; align-items: flex-start; text-align: left;">
                <input type="checkbox" name="award_point" id="logout-award-point" value="1" checked style="width: 18px; height: 18px; accent-color: #1e3a8a; cursor: pointer; margin-top: 0.15rem;">
                <div>
                    <label for="logout-award-point" style="font-size: 0.9rem; font-weight: 600; color: #1f2937; cursor: pointer; display: block;">Award 1 Point</label>
                    <span style="font-size: 0.8rem; color: #6b7280; display: block; margin-top: 0.15rem;">Give this student a point for their session</span>
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="modal-close-btn admin-btn" style="background: #fff; border: 1px solid #d1d5db; color: #4b5563; font-weight: 600; border-radius: 6px; padding: 0.55rem 1.2rem; cursor: pointer;">Cancel</button>
                <button type="submit" class="admin-btn admin-btn-danger" style="background: #dc2626; color: #fff; font-weight: 600; border-radius: 6px; padding: 0.55rem 1.2rem; cursor: pointer; border: none;">Logout</button>
            </div>
        </form>
    </div>
</div>

<script src="theme.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const logoutModal = document.getElementById('logout-student-modal');
    const logoutForm = document.getElementById('logout-student-form');
    const logoutRecordIdInput = document.getElementById('logout-record-id');
    const logoutStudentNameSpan = document.getElementById('logout-student-name');
    const awardPointCheckbox = document.getElementById('logout-award-point');
    
    // Open modal
    document.querySelectorAll('.btn-logout-trigger').forEach(button => {
        button.addEventListener('click', function () {
            const recordId = this.getAttribute('data-id');
            const studentName = this.getAttribute('data-name');
            
            logoutRecordIdInput.value = recordId;
            logoutStudentNameSpan.textContent = studentName;
            awardPointCheckbox.checked = true; // Checked by default
            
            logoutModal.classList.add('is-open');
        });
    });
    
    // Close modal
    document.querySelectorAll('.modal-close-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            logoutModal.classList.remove('is-open');
        });
    });
    
    // Close modal when clicking overlay
    logoutModal.addEventListener('click', function (e) {
        if (e.target === logoutModal) {
            logoutModal.classList.remove('is-open');
        }
    });
});
</script>
</body>
</html>
