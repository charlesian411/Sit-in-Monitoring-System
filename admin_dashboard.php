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
    if ($lookup_id_number === '') {
        echo json_encode([
            'found' => false,
            'name' => '',
            'remaining_sessions' => ''
        ]);
        exit();
    }

    $lookup_stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
    $lookup_stmt->bind_param("s", $lookup_id_number);
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
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_user (user_id),
    INDEX idx_reservation_status (status),
    INDEX idx_reservation_schedule (reservation_date, reservation_time),
    CONSTRAINT fk_reservation_user_dashboard FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$alert_message = "";
$alert_type = "success";
$search_result = null;

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

        if ($id_number === '' || $purpose === '' || $sit_lab === '') {
            $alert_message = "Please complete all Sit-in fields.";
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
                    $insert_stmt = $conn->prepare("INSERT INTO sit_in_records (user_id, purpose, sit_lab, status) VALUES (?, ?, ?, 'active')");
                    $insert_stmt->bind_param("iss", $user_id, $purpose, $sit_lab);
                    if ($insert_stmt->execute()) {
                        $alert_message = "Sit-in recorded successfully.";
                        $alert_type = "success";
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
            $reservation_stmt = $conn->prepare("SELECT id, user_id, purpose, sit_lab, status FROM reservations WHERE id = ? LIMIT 1");
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
                    $insert_stmt = $conn->prepare("INSERT INTO sit_in_records (user_id, purpose, sit_lab, status) VALUES (?, ?, ?, 'active')");
                    $insert_stmt->bind_param("iss", $user_id, $reservation['purpose'], $reservation['sit_lab']);

                    if ($insert_stmt->execute()) {
                        $review_stmt = $conn->prepare("UPDATE reservations SET status = 'approved', admin_note = ?, reviewed_at = NOW() WHERE id = ?");
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
                $reject_stmt = $conn->prepare("UPDATE reservations SET status = 'rejected', admin_note = ?, reviewed_at = NOW() WHERE id = ?");
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
    $search_stmt = $conn->prepare("SELECT id, id_number, first_name, middle_name, last_name, course, course_level FROM users WHERE id_number = ? AND role = 'student' LIMIT 1");
    $search_stmt->bind_param("s", $search_id);
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
$sitin_student_name = '';
$sitin_remaining_sessions = '';

if ($search_result) {
    $sitin_id_number = $search_result['id_number'];
    $sitin_student_name = trim($search_result['first_name'] . ' ' . ($search_result['middle_name'] ? $search_result['middle_name'] . ' ' : '') . $search_result['last_name']);
    $sitin_remaining_sessions = (string) ((int) ($search_result['remaining_sessions'] ?? 0));
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
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_dashboard.php?open=sitin">Sit-in</a></li>
        <li><a href="admin_current_sitin.php">View Sit-in Records</a></li>
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
                <label class="form-label" for="search-student-id">ID Number</label>
                <div class="modal-search-row">
                    <input type="text" id="search-student-id" name="search_id" class="form-control" placeholder="Enter ID Number" value="<?php echo htmlspecialchars($_GET['search_id'] ?? ''); ?>">
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

                <div class="form-group">
                    <label class="form-label">ID Number:</label>
                    <input type="text" class="form-control" id="sitin-id-number" name="id_number" value="<?php echo htmlspecialchars($sitin_id_number); ?>" placeholder="Enter ID Number" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Student Name:</label>
                    <input type="text" class="form-control" id="sitin-student-name" value="<?php echo htmlspecialchars($sitin_student_name); ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Purpose:</label>
                    <input type="text" class="form-control" name="purpose" value="<?php echo htmlspecialchars($sitin_purpose); ?>" placeholder="e.g. C Programming" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Lab:</label>
                    <input type="text" class="form-control" name="sit_lab" value="<?php echo htmlspecialchars($sitin_lab); ?>" placeholder="e.g. 524" required>
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
    const idInput = document.getElementById('sitin-id-number');
    const nameInput = document.getElementById('sitin-student-name');
    const remainingInput = document.getElementById('sitin-remaining-session');

    if (!idInput || !nameInput || !remainingInput) {
        return;
    }

    let lookupTimer = null;

    const fillEmpty = function () {
        nameInput.value = '';
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

    idInput.addEventListener('input', function () {
        clearTimeout(lookupTimer);
        lookupTimer = setTimeout(lookupStudent, 250);
    });

    idInput.addEventListener('blur', lookupStudent);
})();
</script>

</body>
</html>
