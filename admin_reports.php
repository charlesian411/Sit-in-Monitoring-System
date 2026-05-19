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
    CONSTRAINT fk_sit_in_user_reports FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$lab_options = ['524', '526', '528', '530', '542', '544'];
$purpose_options = ['C#', 'Python', 'JavaScript', 'Java', 'TypeScript', 'PHP', 'C++'];

$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$filter_lab = trim($_GET['lab'] ?? '');
$filter_purpose = trim($_GET['purpose'] ?? '');

$where_clauses = ["s.status = 'completed'"];
$params = [];
$types = '';

if ($filter_date_from !== '') {
    $where_clauses[] = "DATE(s.started_at) >= ?";
    $params[] = $filter_date_from;
    $types .= 's';
}

if ($filter_date_to !== '') {
    $where_clauses[] = "DATE(s.started_at) <= ?";
    $params[] = $filter_date_to;
    $types .= 's';
}

if ($filter_lab !== '' && in_array($filter_lab, $lab_options, true)) {
    $where_clauses[] = "s.sit_lab = ?";
    $params[] = $filter_lab;
    $types .= 's';
}

if ($filter_purpose !== '' && in_array($filter_purpose, $purpose_options, true)) {
    $where_clauses[] = "s.purpose = ?";
    $params[] = $filter_purpose;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_clauses);

$sql = "SELECT
            s.id,
            s.purpose,
            s.sit_lab,
            s.pc_number,
            s.started_at,
            s.ended_at,
            u.id_number,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.course,
            TIMESTAMPDIFF(MINUTE, s.started_at, s.ended_at) AS duration_minutes
        FROM sit_in_records s
        INNER JOIN users u ON u.id = s.user_id
        WHERE {$where_sql}
        ORDER BY s.started_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}
$stmt->close();

$is_export = isset($_GET['export']) && $_GET['export'] === 'csv';
if ($is_export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sit_in_report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID Number', 'Student Name', 'Course', 'Purpose', 'Lab', 'PC No.', 'Time In', 'Time Out', 'Duration (min)']);
    foreach ($records as $row) {
        $name = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
        fputcsv($output, [
            $row['id_number'],
            $name,
            $row['course'] ?? '',
            $row['purpose'],
            $row['sit_lab'],
            $row['pc_number'] ?? '-',
            date('M d, Y h:i A', strtotime($row['started_at'])),
            $row['ended_at'] ? date('M d, Y h:i A', strtotime($row['ended_at'])) : '-',
            (int) ($row['duration_minutes'] ?? 0)
        ]);
    }
    fclose($output);
    exit();
}

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_count_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_count_row['total'];
}

$total_records = count($records);
$total_minutes = 0;
foreach ($records as $r) {
    $total_minutes += (int) ($r['duration_minutes'] ?? 0);
}
$total_hours = round($total_minutes / 60, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Generate Reports</title>
    <link rel="stylesheet" href="style.css?v=13">
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

<div class="admin-page">
    <h1 class="admin-page-title">Generate Reports</h1>

    <section class="admin-card" style="margin-bottom: 1rem;">
        <div class="admin-card-title">Filters</div>
        <form method="GET" class="report-filter-form">
            <div class="form-group">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Laboratory</label>
                <select class="form-control" name="lab">
                    <option value="">All Labs</option>
                    <?php foreach ($lab_options as $lab): ?>
                        <option value="<?php echo htmlspecialchars($lab); ?>" <?php echo $filter_lab === $lab ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Purpose</label>
                <select class="form-control" name="purpose">
                    <option value="">All Purposes</option>
                    <?php foreach ($purpose_options as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $filter_purpose === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="report-filter-actions">
                <button type="submit" class="admin-btn admin-btn-secondary">Apply Filters</button>
                <a href="admin_reports.php" class="admin-btn admin-btn-muted">Reset</a>
            </div>
        </form>
    </section>

    <div class="report-summary-bar">
        <span><strong>Total Records:</strong> <?php echo $total_records; ?></span>
        <span><strong>Total Hours:</strong> <?php echo $total_hours; ?> hrs</span>
        <div class="report-export-actions">
            <a href="admin_reports.php?export=csv&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&lab=<?php echo urlencode($filter_lab); ?>&purpose=<?php echo urlencode($filter_purpose); ?>" class="admin-btn admin-btn-primary">Export CSV</a>
            <button type="button" class="admin-btn admin-btn-secondary" onclick="window.print();">Print / PDF</button>
        </div>
    </div>

    <div class="admin-table-wrap" id="report-table-area">
        <table class="admin-table students-table">
            <thead>
                <tr>
                    <th>ID Number</th>
                    <th>Student Name</th>
                    <th>Course</th>
                    <th>Purpose</th>
                    <th>Lab</th>
                    <th>PC No.</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9" class="empty-table">No records found for the selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php
                            $duration_min = (int) ($record['duration_minutes'] ?? 0);
                            $d_hours = floor($duration_min / 60);
                            $d_mins = $duration_min % 60;
                            $duration_display = ($d_hours > 0 ? $d_hours . 'h ' : '') . $d_mins . 'm';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($record['first_name'] . ' ' . ($record['middle_name'] ? $record['middle_name'] . ' ' : '') . $record['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($record['course'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($record['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($record['sit_lab']); ?></td>
                            <td><?php echo htmlspecialchars($record['pc_number'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($record['started_at']))); ?></td>
                            <td><?php echo $record['ended_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($record['ended_at']))) : '-'; ?></td>
                            <td><?php echo $duration_display; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    nav, .report-filter-form, .report-filter-actions, .report-export-actions, .admin-page-title { display: none !important; }
    .admin-page { padding: 0 !important; max-width: none !important; }
    .report-summary-bar { border: none !important; margin: 0 0 0.5rem !important; padding: 0 !important; }
    .report-summary-bar .report-export-actions { display: none !important; }
    .admin-card { display: none !important; }
}
</style>


<script src="theme.js"></script>
</body>
</html>
