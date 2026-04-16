<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: admin_login.php");
    exit();
}

require_once 'config/db.php';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'student_lookup') {
    header('Content-Type: application/json');

    $lookup_id_number = trim($_GET['id_number'] ?? '');
    $lookup_student_name = trim($_GET['student_name'] ?? '');
    if ($lookup_id_number === '') {
        if ($lookup_student_name === '') {
            echo json_encode([
                'found' => false,
                'name' => '',
                'remaining_sessions' => ''
            ]);
            exit();
        }

        $lookup_name_like = '%' . $lookup_student_name . '%';
        $lookup_stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, id_number FROM users WHERE role = 'student' AND CONCAT(first_name, ' ', COALESCE(NULLIF(middle_name, ''), ''), ' ', last_name) LIKE ? ORDER BY first_name ASC, last_name ASC LIMIT 1");
        $lookup_stmt->bind_param("s", $lookup_name_like);
    } else {
        $lookup_stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, id_number FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
        $lookup_stmt->bind_param("s", $lookup_id_number);
    }
    $lookup_stmt->execute();
    $lookup_res = $lookup_stmt->get_result();
    $lookup_user = $lookup_res->fetch_assoc();
    $lookup_stmt->close();

    if (!$lookup_user) {
        echo json_encode([
            'found' => false,
            'name' => '',
            'remaining_sessions' => ''
        ]);
        exit();
    }

    $lookup_user_id = (int) $lookup_user['id'];
    $lookup_count_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
    $lookup_count_stmt->bind_param("i", $lookup_user_id);
    $lookup_count_stmt->execute();
    $lookup_count_res = $lookup_count_stmt->get_result();
    $lookup_count_row = $lookup_count_res->fetch_assoc();
    $lookup_count_stmt->close();

    $lookup_used_sessions = (int) ($lookup_count_row['total_sessions'] ?? 0);
    $lookup_remaining_sessions = max(0, 30 - $lookup_used_sessions);
    $lookup_name = trim($lookup_user['first_name'] . ' ' . ($lookup_user['middle_name'] ? $lookup_user['middle_name'] . ' ' : '') . $lookup_user['last_name']);

    echo json_encode([
        'found' => true,
        'id_number' => (string) ($lookup_user['id_number'] ?? ''),
        'name' => $lookup_name,
        'remaining_sessions' => $lookup_remaining_sessions
    ]);
    exit();
}

// Ensure admin-related tables exist
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
    CONSTRAINT fk_sit_in_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    pc_number VARCHAR(10) NOT NULL,
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
    CONSTRAINT fk_reservation_user_dashboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$reservation_notified_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'student_notified'");
if ($reservation_notified_column && $reservation_notified_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN student_notified TINYINT(1) NOT NULL DEFAULT 0");
}

$reservation_pc_column = $conn->query("SHOW COLUMNS FROM reservations LIKE 'pc_number'");
if ($reservation_pc_column && $reservation_pc_column->num_rows === 0) {
    $conn->query("ALTER TABLE reservations ADD COLUMN pc_number VARCHAR(10) NOT NULL DEFAULT 'PC1'");
}

$sitin_pc_column = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'pc_number'");
if ($sitin_pc_column && $sitin_pc_column->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN pc_number VARCHAR(10) NULL");
}

$alert_message = "";
$alert_type = "success";
$search_result = null;

$purpose_options = ['C#', 'Python', 'JavaScript', 'Java', 'TypeScript', 'PHP', 'C++'];
$lab_options = ['524', '526', '528', '530', '542', '544'];
$pc_options = [];
for ($pc_index = 1; $pc_index <= 40; $pc_index++) {
    $pc_options[] = 'PC' . $pc_index;
}

$unavailable_pcs_by_lab = [];
foreach ($lab_options as $lab_option) {
    $unavailable_pcs_by_lab[$lab_option] = [];
}

$pc_res = $conn->query("SELECT sit_lab, pc_number FROM sit_in_records WHERE status = 'active' AND pc_number IS NOT NULL AND pc_number <> ''");
if ($pc_res) {
    while ($pc_row = $pc_res->fetch_assoc()) {
        $row_lab = trim((string) ($pc_row['sit_lab'] ?? ''));
        $row_pc = strtoupper(trim((string) ($pc_row['pc_number'] ?? '')));
        if ($row_lab !== '' && in_array($row_lab, $lab_options, true) && $row_pc !== '') {
            $unavailable_pcs_by_lab[$row_lab][$row_pc] = true;
        }
    }
}

$pending_pc_res = $conn->query("SELECT sit_lab, pc_number FROM reservations WHERE status = 'pending' AND pc_number IS NOT NULL AND pc_number <> ''");
if ($pending_pc_res) {
    while ($pending_pc_row = $pending_pc_res->fetch_assoc()) {
        $row_lab = trim((string) ($pending_pc_row['sit_lab'] ?? ''));
        $row_pc = strtoupper(trim((string) ($pending_pc_row['pc_number'] ?? '')));
        if ($row_lab !== '' && in_array($row_lab, $lab_options, true) && $row_pc !== '') {
            $unavailable_pcs_by_lab[$row_lab][$row_pc] = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'post_announcement') {
        $announcement = trim($_POST['announcement'] ?? '');
        if ($announcement === '') {
            $alert_message = "Announcement cannot be empty.";
            $alert_type = "error";
        } else {
            $author_name = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
            $stmt = $conn->prepare("INSERT INTO announcements (author_name, content) VALUES (?, ?)");
            $stmt->bind_param("ss", $author_name, $announcement);
            if ($stmt->execute()) {
                $alert_message = "Announcement posted.";
                $alert_type = "success";
            } else {
                $alert_message = "Failed to post announcement.";
                $alert_type = "error";
            }
            $stmt->close();
        }
    }

    if ($action === 'add_sit_in') {
        $id_number = trim($_POST['id_number'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $sit_lab = trim($_POST['sit_lab'] ?? '');
        $pc_number = strtoupper(trim($_POST['pc_number'] ?? ''));

        $unavailable_pcs = in_array($sit_lab, $lab_options, true) ? ($unavailable_pcs_by_lab[$sit_lab] ?? []) : [];

        if ($id_number === '' || $purpose === '' || $sit_lab === '' || $pc_number === '') {
            $alert_message = "Please complete all Sit-in fields.";
            $alert_type = "error";
        } elseif (!ctype_digit($id_number)) {
            $alert_message = "ID Number must contain numbers only.";
            $alert_type = "error";
        } elseif (!in_array($purpose, $purpose_options, true)) {
            $alert_message = "Invalid purpose selected.";
            $alert_type = "error";
        } elseif (!in_array($sit_lab, $lab_options, true)) {
            $alert_message = "Invalid laboratory selected.";
            $alert_type = "error";
        } elseif (!in_array($pc_number, $pc_options, true)) {
            $alert_message = "Invalid PC selected.";
            $alert_type = "error";
        } elseif (isset($unavailable_pcs[$pc_number])) {
            $alert_message = $pc_number . " is not available in Lab " . $sit_lab . ".";
            $alert_type = "error";
        } else {
            $student_stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
            $student_stmt->bind_param("s", $id_number);
            $student_stmt->execute();
            $student_result = $student_stmt->get_result();
            $student = $student_result->fetch_assoc();
            $student_stmt->close();

            if (!$student) {
                $alert_message = "Student not found for the given ID number.";
                $alert_type = "error";
            } else {
                $user_id = (int) $student['id'];

                $active_stmt = $conn->prepare("SELECT id FROM sit_in_records WHERE user_id = ? AND status = 'active' LIMIT 1");
                $active_stmt->bind_param("i", $user_id);
                $active_stmt->execute();
                $active_stmt->store_result();

                $count_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
                $count_stmt->bind_param("i", $user_id);
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $count_row = $count_result->fetch_assoc();
                $total_sessions = (int) ($count_row['total_sessions'] ?? 0);

                if ($active_stmt->num_rows > 0) {
                    $alert_message = "This student already has an active Sit-in.";
                    $alert_type = "error";
                } elseif ($total_sessions >= 30) {
                    $alert_message = "This student has no remaining sessions.";
                    $alert_type = "error";
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO sit_in_records (user_id, purpose, sit_lab, pc_number, status) VALUES (?, ?, ?, ?, 'active')");
                    $insert_stmt->bind_param("isss", $user_id, $purpose, $sit_lab, $pc_number);
                    if ($insert_stmt->execute()) {
                        $insert_stmt->close();
                        header("Location: admin_current_sitin.php");
                        exit();
                    } else {
                        $alert_message = "Unable to record Sit-in.";
                        $alert_type = "error";
                    }
                    $insert_stmt->close();
                }

                $active_stmt->close();
                $count_stmt->close();
            }
        }
    }

    if ($action === 'review_reservation') {
        $reservation_id = (int) ($_POST['reservation_id'] ?? 0);
        $decision = trim($_POST['decision'] ?? '');
        $admin_note = trim($_POST['admin_note'] ?? '');

        if ($reservation_id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            $alert_message = "Invalid reservation action.";
            $alert_type = "error";
        } else {
            $reservation_stmt = $conn->prepare("SELECT id, user_id, purpose, sit_lab, pc_number, status FROM reservations WHERE id = ? LIMIT 1");
            $reservation_stmt->bind_param("i", $reservation_id);
            $reservation_stmt->execute();
            $reservation_res = $reservation_stmt->get_result();
            $reservation = $reservation_res->fetch_assoc();
            $reservation_stmt->close();

            if (!$reservation) {
                $alert_message = "Reservation not found.";
                $alert_type = "error";
            } elseif ($reservation['status'] !== 'pending') {
                $alert_message = "Reservation is already reviewed.";
                $alert_type = "error";
            } elseif ($decision === 'approved') {
                $user_id = (int) $reservation['user_id'];

                $active_stmt = $conn->prepare("SELECT id FROM sit_in_records WHERE user_id = ? AND status = 'active' LIMIT 1");
                $active_stmt->bind_param("i", $user_id);
                $active_stmt->execute();
                $active_stmt->store_result();

                $count_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
                $count_stmt->bind_param("i", $user_id);
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $count_row = $count_result->fetch_assoc();
                $total_sessions = (int) ($count_row['total_sessions'] ?? 0);

                if ($active_stmt->num_rows > 0) {
                    $alert_message = "Cannot approve: student already has an active Sit-in.";
                    $alert_type = "error";
                } elseif ($total_sessions >= 30) {
                    $alert_message = "Cannot approve: student has no remaining sessions.";
                    $alert_type = "error";
                } else {
                    $insert_stmt = $conn->prepare("INSERT INTO sit_in_records (user_id, purpose, sit_lab, pc_number, status) VALUES (?, ?, ?, ?, 'active')");
                    $insert_stmt->bind_param("isss", $user_id, $reservation['purpose'], $reservation['sit_lab'], $reservation['pc_number']);

                    if ($insert_stmt->execute()) {
                        $review_stmt = $conn->prepare("UPDATE reservations SET status = 'approved', admin_note = ?, reviewed_at = NOW(), student_notified = 0 WHERE id = ?");
                        $review_stmt->bind_param("si", $admin_note, $reservation_id);
                        if ($review_stmt->execute()) {
                            $alert_message = "Reservation approved and Sit-in started.";
                            $alert_type = "success";
                        } else {
                            $alert_message = "Sit-in started, but failed to update reservation status.";
                            $alert_type = "error";
                        }
                        $review_stmt->close();
                    } else {
                        $alert_message = "Unable to create Sit-in from reservation.";
                        $alert_type = "error";
                    }
                    $insert_stmt->close();
                }

                $active_stmt->close();
                $count_stmt->close();
            } else {
                $reject_stmt = $conn->prepare("UPDATE reservations SET status = 'rejected', admin_note = ?, reviewed_at = NOW(), student_notified = 0 WHERE id = ?");
                $reject_stmt->bind_param("si", $admin_note, $reservation_id);
                if ($reject_stmt->execute()) {
                    $alert_message = "Reservation rejected.";
                    $alert_type = "success";
                } else {
                    $alert_message = "Unable to reject reservation.";
                    $alert_type = "error";
                }
                $reject_stmt->close();
            }
        }
    }
}

if (isset($_GET['search_id']) && trim($_GET['search_id']) !== '') {
    $search_id = trim($_GET['search_id']);

    if (ctype_digit($search_id)) {
        $search_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name, course, course_level FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
        $search_stmt->bind_param("s", $search_id);
    } else {
        $search_like = '%' . $search_id . '%';
        $search_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name, course, course_level FROM users WHERE role = 'student' AND CONCAT(first_name, ' ', COALESCE(NULLIF(middle_name, ''), ''), ' ', last_name) LIKE ? ORDER BY first_name ASC, last_name ASC LIMIT 1");
        $search_stmt->bind_param("s", $search_like);
    }

    $search_stmt->execute();
    $search_res = $search_stmt->get_result();
    $search_result = $search_res->fetch_assoc();
    $search_stmt->close();

    if ($search_result) {
        $sid = (int) $search_result['id'];
        $session_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
        $session_stmt->bind_param("i", $sid);
        $session_stmt->execute();
        $session_res = $session_stmt->get_result();
        $session_row = $session_res->fetch_assoc();
        $used_sessions = (int) ($session_row['total_sessions'] ?? 0);
        $search_result['remaining_sessions'] = max(0, 30 - $used_sessions);
        $session_stmt->close();
    }
}

$sitin_id_number = trim($_POST['id_number'] ?? ($_GET['search_id'] ?? ''));
$sitin_purpose = trim($_POST['purpose'] ?? '');
$sitin_lab = trim($_POST['sit_lab'] ?? '');
$sitin_pc_number = strtoupper(trim($_POST['pc_number'] ?? ''));
$sitin_student_name = '';
$sitin_remaining_sessions = '';
$lock_sitin_id = (($_POST['lock_sitin_id'] ?? '') === '1');
$selected_sitin_lab_for_pc = in_array($sitin_lab, $lab_options, true) ? $sitin_lab : '';
$sitin_unavailable_pcs = $selected_sitin_lab_for_pc !== '' ? ($unavailable_pcs_by_lab[$selected_sitin_lab_for_pc] ?? []) : [];

if ($search_result) {
    $sitin_id_number = $search_result['id_number'];
    $sitin_student_name = trim($search_result['first_name'] . ' ' . ($search_result['middle_name'] ? $search_result['middle_name'] . ' ' : '') . $search_result['last_name']);
    $sitin_remaining_sessions = (string) ((int) ($search_result['remaining_sessions'] ?? 0));
    $lock_sitin_id = true;
} elseif ($sitin_id_number !== '') {
    $sitin_user_stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
    $sitin_user_stmt->bind_param("s", $sitin_id_number);
    $sitin_user_stmt->execute();
    $sitin_user_res = $sitin_user_stmt->get_result();
    $sitin_user = $sitin_user_res->fetch_assoc();
    $sitin_user_stmt->close();

    if ($sitin_user) {
        $sitin_student_name = trim($sitin_user['first_name'] . ' ' . ($sitin_user['middle_name'] ? $sitin_user['middle_name'] . ' ' : '') . $sitin_user['last_name']);
        $sitin_sid = (int) $sitin_user['id'];
        $sitin_session_stmt = $conn->prepare("SELECT COUNT(*) AS total_sessions FROM sit_in_records WHERE user_id = ?");
        $sitin_session_stmt->bind_param("i", $sitin_sid);
        $sitin_session_stmt->execute();
        $sitin_session_res = $sitin_session_stmt->get_result();
        $sitin_session_row = $sitin_session_res->fetch_assoc();
        $sitin_used_sessions = (int) ($sitin_session_row['total_sessions'] ?? 0);
        $sitin_remaining_sessions = (string) max(0, 30 - $sitin_used_sessions);
        $sitin_session_stmt->close();
    }
}

$stats = [
    'students_registered' => 0,
    'currently_sit_in' => 0,
    'total_sit_in' => 0
];

$stats_query = $conn->query("SELECT
    (SELECT COUNT(*) FROM users WHERE role = 'student') AS students_registered,
    (SELECT COUNT(*) FROM sit_in_records WHERE status = 'active') AS currently_sit_in,
    (SELECT COUNT(*) FROM sit_in_records) AS total_sit_in");

if ($stats_query && $stats_row = $stats_query->fetch_assoc()) {
    $stats['students_registered'] = (int) $stats_row['students_registered'];
    $stats['currently_sit_in'] = (int) $stats_row['currently_sit_in'];
    $stats['total_sit_in'] = (int) $stats_row['total_sit_in'];
}

$course_stats = [];
$course_result = $conn->query("SELECT course, COUNT(*) AS total FROM users WHERE role = 'student' GROUP BY course ORDER BY total DESC");
if ($course_result) {
    while ($row = $course_result->fetch_assoc()) {
        $course_stats[] = $row;
    }
}

$max_course_total = 0;
foreach ($course_stats as $course_stat) {
    $max_course_total = max($max_course_total, (int) $course_stat['total']);
}

$language_counts = [
    'C#' => 0,
    'Python' => 0,
    'JavaScript' => 0,
    'Java' => 0,
    'TypeScript' => 0,
    'PHP' => 0,
    'C++' => 0
];

$language_keywords = [
    'C#' => ['c#', 'csharp'],
    'Python' => ['python'],
    'JavaScript' => ['javascript'],
    'Java' => ['java'],
    'TypeScript' => ['typescript'],
    'PHP' => ['php'],
    'C++' => ['c++', 'cpp']
];

$language_res = $conn->query("SELECT purpose FROM sit_in_records");
if ($language_res) {
    while ($language_row = $language_res->fetch_assoc()) {
        $purpose_text = strtolower(trim($language_row['purpose'] ?? ''));
        if ($purpose_text === '') {
            continue;
        }

        $purpose_scan_text = ' ' . $purpose_text . ' ';
        foreach ($language_keywords as $language_label => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($purpose_scan_text, $keyword) !== false) {
                    $language_counts[$language_label]++;
                    break;
                }
            }
        }
    }
}

$language_stats = [];
foreach ($language_counts as $language_label => $language_total) {
    $language_stats[] = [
        'label' => $language_label,
        'total' => (int) $language_total
    ];
}

$max_language_total = 0;
foreach ($language_stats as $language_stat) {
    $max_language_total = max($max_language_total, (int) $language_stat['total']);
}

$announcements = [];
$ann_res = $conn->query("SELECT author_name, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 5");
if ($ann_res) {
    while ($row = $ann_res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_count_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_count_row['total'];
}

$open_search_modal = isset($_GET['open']) && in_array($_GET['open'], ['search', 'sitin'], true);
$modal_mode = (isset($_GET['open']) && $_GET['open'] === 'search') ? 'search' : 'sitin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add_sit_in')) {
    $open_search_modal = true;
    $modal_mode = 'sitin';
}

if ($search_result) {
    $open_search_modal = true;
    $modal_mode = 'sitin';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Admin Dashboard</title>
    <link rel="stylesheet" href="style.css?v=11">
    <style>
        #search-modal .admin-modal { max-height: 92vh; overflow-y: auto; }
        .pc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(82px, 1fr)); gap: 0.5rem; margin-top: 0.4rem; }
        .pc-grid-wrap { max-height: 220px; overflow-y: auto; padding-right: 0.25rem; }
        .pc-chip { border: 1px solid #d1d5db; border-radius: 8px; padding: 0.45rem 0.35rem; font-size: 0.85rem; text-align: center; cursor: pointer; user-select: none; }
        .pc-available { background: #dcfce7; border-color: #22c55e; color: #166534; }
        .pc-unavailable { background: #fee2e2; border-color: #ef4444; color: #991b1b; cursor: not-allowed; opacity: 0.8; }
        .pc-selected { outline: 2px solid #2563eb; font-weight: 600; }
    </style>
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
        <li><a href="admin_feedback_reports.php">Feedback Reports</a></li>
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <div class="admin-grid">
        <section class="admin-card">
            <div class="admin-card-title">Statistics</div>

            <div class="admin-stat-list">
                <p><strong>Students Registered:</strong> <?php echo $stats['students_registered']; ?></p>
                <p><strong>Currently Sit-in:</strong> <?php echo $stats['currently_sit_in']; ?></p>
                <p><strong>Total Sit-in:</strong> <?php echo $stats['total_sit_in']; ?></p>
            </div>

            <div class="mini-chart">
                <?php if (empty($course_stats)): ?>
                    <p class="empty-text">No course data yet.</p>
                <?php else: ?>
                    <?php foreach ($course_stats as $idx => $course): ?>
                        <?php
                            $bar_width = $max_course_total > 0 ? ((int) $course['total'] / $max_course_total) * 100 : 0;
                            $bar_class = 'bar-' . ($idx % 5);
                        ?>
                        <div class="chart-row">
                            <span class="chart-label"><?php echo htmlspecialchars($course['course']); ?></span>
                            <div class="chart-track">
                                <div class="chart-bar <?php echo $bar_class; ?>" style="width: <?php echo (float) $bar_width; ?>%;"></div>
                            </div>
                            <span class="chart-value"><?php echo (int) $course['total']; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <h2 class="admin-language-title">Language Usage</h2>
            <div class="mini-chart">
                <div class="language-legend">
                    <?php foreach ($language_stats as $lidx => $language): ?>
                        <span class="legend-item"><span class="legend-dot bar-<?php echo $lidx % 5; ?>"></span><?php echo htmlspecialchars($language['label']); ?></span>
                    <?php endforeach; ?>
                </div>

                <?php if ($max_language_total <= 0): ?>
                    <p class="empty-text">No language usage data yet.</p>
                <?php else: ?>
                    <?php foreach ($language_stats as $lidx => $language): ?>
                        <?php
                            $language_bar_width = $max_language_total > 0 ? ((int) $language['total'] / $max_language_total) * 100 : 0;
                            $language_bar_class = 'bar-' . ($lidx % 5);
                        ?>
                        <div class="chart-row">
                            <span class="chart-label"><?php echo htmlspecialchars($language['label']); ?></span>
                            <div class="chart-track">
                                <div class="chart-bar <?php echo $language_bar_class; ?>" style="width: <?php echo (float) $language_bar_width; ?>%;"></div>
                            </div>
                            <span class="chart-value"><?php echo (int) $language['total']; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="admin-card">
            <div class="admin-card-title">Announcement</div>

            <form method="POST" class="announcement-form">
                <input type="hidden" name="action" value="post_announcement">
                <textarea class="form-control" name="announcement" rows="3" placeholder="New Announcement"></textarea>
                <button type="submit" class="admin-btn admin-btn-primary">Submit</button>
            </form>

            <h2 class="admin-section-subtitle">Posted Announcement</h2>
            <div class="announcement-list">
                <?php if (empty($announcements)): ?>
                    <p class="empty-text">No announcements yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): ?>
                        <article class="announcement-item">
                            <div class="announcement-head"><?php echo htmlspecialchars($ann['author_name']); ?> | <?php echo htmlspecialchars(date('Y-M-d', strtotime($ann['created_at']))); ?></div>
                            <div class="announcement-content"><?php echo nl2br(htmlspecialchars($ann['content'])); ?></div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

</div>

<div class="modal-overlay <?php echo $open_search_modal ? 'is-open' : ''; ?>" id="search-modal">
    <div class="admin-modal">
        <?php if ($modal_mode === 'search' && !$search_result): ?>
            <div class="modal-header">
                <h3>Search Student</h3>
                <a href="admin_dashboard.php" class="modal-close">×</a>
            </div>

            <form method="GET" class="modal-search-form">
                <input type="hidden" name="open" value="search">
                <label class="form-label" for="search-student-id">ID Number or Student Name</label>
                <div class="modal-search-row">
                    <input type="text" id="search-student-id" name="search_id" class="form-control" placeholder="Enter ID Number or Student Name" value="<?php echo htmlspecialchars($_GET['search_id'] ?? ''); ?>">
                    <button type="submit" class="admin-btn admin-btn-secondary">Search</button>
                </div>
            </form>

            <?php if (isset($_GET['search_id']) && trim($_GET['search_id']) !== ''): ?>
                <div class="search-result-box">
                    <p class="empty-text">No student found.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="modal-header">
                <h3>Sit In Form</h3>
                <a href="admin_dashboard.php" class="modal-close">×</a>
            </div>

            <form method="POST" class="sitin-modal-form">
                <input type="hidden" name="action" value="add_sit_in">
                <input type="hidden" name="lock_sitin_id" value="<?php echo $lock_sitin_id ? '1' : '0'; ?>">

                <div class="form-group">
                    <label class="form-label">ID Number:</label>
                    <input type="text" class="form-control" id="sitin-id-number" name="id_number" value="<?php echo htmlspecialchars($sitin_id_number); ?>" placeholder="Enter ID Number" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'');" <?php echo $lock_sitin_id ? 'readonly' : ''; ?> required>
                </div>

                <div class="form-group">
                    <label class="form-label">Student Name:</label>
                    <input type="text" class="form-control" id="sitin-student-name" value="<?php echo htmlspecialchars($sitin_student_name); ?>" placeholder="Enter Student Name" <?php echo $lock_sitin_id ? 'readonly' : ''; ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">Purpose:</label>
                    <select class="form-control" name="purpose" required>
                        <option value="" <?php echo $sitin_purpose === '' ? 'selected' : ''; ?> disabled>Select Purpose</option>
                        <?php foreach ($purpose_options as $purpose_option): ?>
                            <option value="<?php echo htmlspecialchars($purpose_option); ?>" <?php echo $sitin_purpose === $purpose_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($purpose_option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Lab:</label>
                    <select class="form-control" name="sit_lab" id="sitin-lab" required>
                        <option value="" <?php echo $sitin_lab === '' ? 'selected' : ''; ?> disabled>Select Laboratory</option>
                        <?php foreach ($lab_options as $lab_option): ?>
                            <option value="<?php echo htmlspecialchars($lab_option); ?>" <?php echo $sitin_lab === $lab_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab_option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="sitin-pc-group" style="grid-column: 1 / -1; <?php echo $selected_sitin_lab_for_pc === '' ? 'display:none;' : ''; ?>">
                    <label class="form-label">PC Number:</label>
                    <input type="hidden" name="pc_number" id="sitin-pc-number" value="<?php echo htmlspecialchars($sitin_pc_number); ?>" required>
                    <div class="pc-grid-wrap">
                        <div class="pc-grid" id="sitin-pc-grid">
                            <?php foreach ($pc_options as $pc_option): ?>
                                <?php $is_unavailable = isset($sitin_unavailable_pcs[$pc_option]); ?>
                                <button
                                    type="button"
                                    class="pc-chip <?php echo $is_unavailable ? 'pc-unavailable' : 'pc-available'; ?> <?php echo $sitin_pc_number === $pc_option ? 'pc-selected' : ''; ?>"
                                    data-pc="<?php echo htmlspecialchars($pc_option); ?>"
                                    <?php echo $is_unavailable ? 'disabled' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($pc_option); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="form-help" style="margin-top:0.45rem;">Green = available, Red = not available (per selected laboratory)</p>
                </div>

                <div class="form-group" id="sitin-pc-hint" style="grid-column: 1 / -1; <?php echo $selected_sitin_lab_for_pc === '' ? '' : 'display:none;'; ?>">
                    <p class="form-help" style="margin-top:0.25rem;">Select a laboratory first to show available PC numbers.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Remaining Session:</label>
                    <input type="text" class="form-control" id="sitin-remaining-session" value="<?php echo htmlspecialchars($sitin_remaining_sessions); ?>" readonly>
                </div>

                <div class="sitin-modal-actions">
                    <a href="admin_dashboard.php" class="admin-btn admin-btn-muted">Close</a>
                    <button type="submit" class="admin-btn admin-btn-secondary">Sit In</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const unavailableByLab = <?php echo json_encode($unavailable_pcs_by_lab); ?> || {};
    const idInput = document.getElementById('sitin-id-number');
    const nameInput = document.getElementById('sitin-student-name');
    const remainingInput = document.getElementById('sitin-remaining-session');
    const labSelect = document.getElementById('sitin-lab');
    const pcGroup = document.getElementById('sitin-pc-group');
    const pcHint = document.getElementById('sitin-pc-hint');
    const pcGrid = document.getElementById('sitin-pc-grid');
    const pcInput = document.getElementById('sitin-pc-number');
    const isLocked = idInput && idInput.hasAttribute('readonly');

    if (!idInput || !nameInput || !remainingInput) {
        return;
    }

    let lookupTimer = null;

    const fillEmpty = function () {
        if (!isLocked) {
            nameInput.value = '';
        }
        remainingInput.value = '';
    };

    const lookupStudent = function () {
        const idNumber = idInput.value.trim();
        if (idNumber === '') {
            fillEmpty();
            return;
        }

        const url = 'admin_dashboard.php?ajax=student_lookup&id_number=' + encodeURIComponent(idNumber);
        fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.found) {
                    nameInput.value = data.name || '';
                    remainingInput.value = data.remaining_sessions !== undefined ? String(data.remaining_sessions) : '';
                } else {
                    fillEmpty();
                }
            })
            .catch(function () {
                fillEmpty();
            });
    };

    const lookupByName = function () {
        const studentName = nameInput.value.trim();
        if (studentName === '') {
            idInput.value = '';
            remainingInput.value = '';
            return;
        }

        const url = 'admin_dashboard.php?ajax=student_lookup&student_name=' + encodeURIComponent(studentName);
        fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.found) {
                    idInput.value = data.id_number || '';
                    nameInput.value = data.name || studentName;
                    remainingInput.value = data.remaining_sessions !== undefined ? String(data.remaining_sessions) : '';
                } else {
                    idInput.value = '';
                    remainingInput.value = '';
                }
            })
            .catch(function () {
                idInput.value = '';
                remainingInput.value = '';
            });
    };

    const togglePcSection = function () {
        if (!labSelect || !pcGroup || !pcHint || !pcGrid || !pcInput) {
            return;
        }

        const hasLab = !!labSelect.value;
        pcGroup.style.display = hasLab ? '' : 'none';
        pcHint.style.display = hasLab ? 'none' : '';

        if (!hasLab) {
            pcInput.value = '';
            const allChips = pcGrid.querySelectorAll('.pc-chip');
            allChips.forEach(function (chip) {
                chip.classList.remove('pc-selected');
            });
        }
    };

    const applyLabPcAvailability = function () {
        if (!labSelect || !pcGrid || !pcInput) {
            return;
        }

        const selectedLab = labSelect.value || '';
        const blocked = unavailableByLab[selectedLab] || {};
        const allChips = pcGrid.querySelectorAll('.pc-chip');

        allChips.forEach(function (chip) {
            const chipPc = chip.getAttribute('data-pc') || '';
            const isBlocked = !!blocked[chipPc];

            chip.disabled = isBlocked;
            chip.classList.remove('pc-available', 'pc-unavailable');
            chip.classList.add(isBlocked ? 'pc-unavailable' : 'pc-available');

            if (isBlocked) {
                chip.classList.remove('pc-selected');
                if (pcInput.value === chipPc) {
                    pcInput.value = '';
                }
            }
        });
    };

    idInput.addEventListener('input', function () {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookupStudent, 250);
    });

    idInput.addEventListener('blur', lookupStudent);

    if (!isLocked) {
        nameInput.addEventListener('input', function () {
            clearTimeout(lookupTimer);
            lookupTimer = setTimeout(lookupByName, 250);
        });

        nameInput.addEventListener('blur', lookupByName);
    }

    if (labSelect && pcGroup && pcHint && pcGrid && pcInput) {
        labSelect.addEventListener('change', function () {
            pcInput.value = '';
            const allChips = pcGrid.querySelectorAll('.pc-chip');
            allChips.forEach(function (chip) {
                chip.classList.remove('pc-selected');
            });
            applyLabPcAvailability();
            togglePcSection();
        });

        pcGrid.addEventListener('click', function (event) {
            const target = event.target;
            if (!target.classList.contains('pc-chip') || target.disabled) {
                return;
            }

            const allChips = pcGrid.querySelectorAll('.pc-chip');
            allChips.forEach(function (chip) {
                chip.classList.remove('pc-selected');
            });

            target.classList.add('pc-selected');
            pcInput.value = target.getAttribute('data-pc') || '';
        });

        applyLabPcAvailability();
        togglePcSection();
    }
})();
</script>

</body>
</html>
