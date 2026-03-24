<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/db.php';

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

$announcements = [];
$ann_res = $conn->query("SELECT author_name, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 5");
if ($ann_res) {
    while ($row = $ann_res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$pending_reservations = [];
$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_count_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_count_row['total'];
}

$pending_list_res = $conn->query("SELECT
    r.id,
    r.purpose,
    r.sit_lab,
    r.reservation_date,
    r.reservation_time,
    r.created_at,
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name
FROM reservations r
INNER JOIN users u ON u.id = r.user_id
WHERE r.status = 'pending'
ORDER BY r.created_at DESC
LIMIT 6");
if ($pending_list_res) {
    while ($row = $pending_list_res->fetch_assoc()) {
        $pending_reservations[] = $row;
    }
}

$open_search_modal = isset($_GET['open']) && $_GET['open'] === 'search';

if ($search_result) {
    $open_search_modal = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Admin Dashboard</title>
    <link rel="stylesheet" href="style.css?v=3">
</head>
<body>

<nav class="admin-top-nav">
    <span class="nav-brand">College of Computer Studies Admin</span>
    <ul class="nav-links admin-links">
        <li><a href="admin_dashboard.php">Home</a></li>
        <li><a href="admin_dashboard.php?open=search">Search</a></li>
        <li><a href="admin_students.php">Students</a></li>
        <li><a href="admin_dashboard.php#sit-in-form">Sit-in</a></li>
        <li><a href="admin_current_sitin.php">View Sit-in Records</a></li>
        <li><a href="admin_reservations.php">Reservations<?php if ($pending_count > 0): ?> <span class="badge-pill"><?php echo $pending_count; ?></span><?php endif; ?></a></li>
        <li><a href="logout.php" class="admin-logout-link">Log out</a></li>
    </ul>
</nav>

<div class="admin-page">
    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <section class="admin-card admin-notification-card">
        <div class="admin-card-title">Reservation Notifications</div>
        <div class="reservation-notice-wrap">
            <p class="reservation-count-text">Pending reservations: <strong><?php echo $pending_count; ?></strong></p>
            <a href="admin_reservations.php" class="admin-btn admin-btn-secondary">Open Reservations</a>
        </div>
        <div class="admin-table-wrap reservation-mini-table-wrap">
            <table class="admin-table reservation-mini-table">
                <thead>
                    <tr>
                        <th>ID Number</th>
                        <th>Name</th>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>Schedule</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pending_reservations)): ?>
                    <tr>
                        <td colspan="6" class="empty-table">No pending reservations.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_reservations as $pending): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pending['id_number']); ?></td>
                            <td><?php echo htmlspecialchars($pending['first_name'] . ' ' . ($pending['middle_name'] ? $pending['middle_name'] . ' ' : '') . $pending['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($pending['purpose']); ?></td>
                            <td><?php echo htmlspecialchars($pending['sit_lab']); ?></td>
                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($pending['reservation_date'])) . ' ' . date('h:i A', strtotime($pending['reservation_time']))); ?></td>
                            <td>
                                <div class="reservation-action-group">
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="review_reservation">
                                        <input type="hidden" name="reservation_id" value="<?php echo (int) $pending['id']; ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <button type="submit" class="admin-btn admin-btn-primary">Approve</button>
                                    </form>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="review_reservation">
                                        <input type="hidden" name="reservation_id" value="<?php echo (int) $pending['id']; ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <button type="submit" class="admin-btn admin-btn-danger">Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

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

    <section id="sit-in-form" class="admin-card sit-in-card">
        <div class="admin-card-title">Sit In Form</div>
        <form method="POST" class="sit-in-form-grid">
            <input type="hidden" name="action" value="add_sit_in">

            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" name="id_number" placeholder="Enter ID Number" required>
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <input type="text" class="form-control" name="purpose" placeholder="e.g. C Programming" required>
            </div>

            <div class="form-group">
                <label class="form-label">Lab</label>
                <input type="text" class="form-control" name="sit_lab" placeholder="e.g. 524" required>
            </div>

            <div class="sit-in-form-actions">
                <button type="submit" class="admin-btn admin-btn-primary">Sit In</button>
            </div>
        </form>
    </section>
</div>

<div class="modal-overlay <?php echo $open_search_modal ? 'is-open' : ''; ?>" id="search-modal">
    <div class="admin-modal">
        <div class="modal-header">
            <h3>Search Student</h3>
            <a href="admin_dashboard.php" class="modal-close">×</a>
        </div>

        <form method="GET" class="modal-search-form">
            <input type="hidden" name="open" value="search">
            <input type="text" name="search_id" class="form-control" placeholder="Search by ID Number" value="<?php echo htmlspecialchars($_GET['search_id'] ?? ''); ?>">
            <button type="submit" class="admin-btn admin-btn-primary">Search</button>
        </form>

        <?php if (isset($_GET['search_id'])): ?>
            <div class="search-result-box">
                <?php if (!$search_result): ?>
                    <p class="empty-text">No student found.</p>
                <?php else: ?>
                    <p><strong>ID Number:</strong> <?php echo htmlspecialchars($search_result['id_number']); ?></p>
                    <p><strong>Student Name:</strong> <?php echo htmlspecialchars($search_result['first_name'] . ' ' . ($search_result['middle_name'] ? $search_result['middle_name'] . ' ' : '') . $search_result['last_name']); ?></p>
                    <p><strong>Course:</strong> <?php echo htmlspecialchars($search_result['course']); ?></p>
                    <p><strong>Year Level:</strong> <?php echo (int) $search_result['course_level']; ?></p>
                    <p><strong>Remaining Session:</strong> <?php echo (int) $search_result['remaining_sessions']; ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
