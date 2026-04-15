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
    CONSTRAINT fk_sit_in_user_student_reservation_page FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    CONSTRAINT fk_reservation_user_student FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
$user_id = (int) $_SESSION['user_id'];
$purpose_options = ['C#', 'Python', 'JavaScript', 'Java', 'TypeScript', 'PHP', 'C++'];
$lab_options = ['524', '526', '528', '530', '542', '544'];
$purpose = trim($_POST['purpose'] ?? '');
$sit_lab = trim($_POST['sit_lab'] ?? '');
$reservation_date = trim($_POST['reservation_date'] ?? '');
$reservation_time = trim($_POST['reservation_time'] ?? '');
$pc_number = strtoupper(trim($_POST['pc_number'] ?? ''));
$pc_options = [];
for ($pc_index = 1; $pc_index <= 40; $pc_index++) {
    $pc_options[] = 'PC' . $pc_index;
}

$unavailable_pcs = [];
$pc_res = $conn->query("SELECT DISTINCT pc_number FROM sit_in_records WHERE status = 'active' AND pc_number IS NOT NULL AND pc_number <> ''");
if ($pc_res) {
    while ($pc_row = $pc_res->fetch_assoc()) {
        $unavailable_pcs[strtoupper(trim((string) $pc_row['pc_number']))] = true;
    }
}

$pending_pc_res = $conn->query("SELECT DISTINCT pc_number FROM reservations WHERE status = 'pending' AND pc_number IS NOT NULL AND pc_number <> ''");
if ($pending_pc_res) {
    while ($pending_pc_row = $pending_pc_res->fetch_assoc()) {
        $unavailable_pcs[strtoupper(trim((string) $pending_pc_row['pc_number']))] = true;
    }
}

$feedback_column_check = $conn->query("SHOW COLUMNS FROM sit_in_records LIKE 'feedback'");
if ($feedback_column_check && $feedback_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sit_in_records ADD COLUMN feedback TEXT NULL");
}

$stmt = $conn->prepare("SELECT id_number, first_name, middle_name, last_name FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_res = $stmt->get_result();
$user = $user_res->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $today = date('Y-m-d');

    if ($purpose === '' || $sit_lab === '' || $reservation_date === '' || $reservation_time === '' || $pc_number === '') {
        $alert_message = "Please complete all reservation fields.";
        $alert_type = "error";
    } elseif (!in_array($purpose, $purpose_options, true)) {
        $alert_message = "Please select a valid purpose.";
        $alert_type = "error";
    } elseif (!in_array($sit_lab, $lab_options, true)) {
        $alert_message = "Please select a valid lab.";
        $alert_type = "error";
    } elseif (!in_array($pc_number, $pc_options, true)) {
        $alert_message = "Please select a valid PC.";
        $alert_type = "error";
    } elseif (isset($unavailable_pcs[$pc_number])) {
        $alert_message = $pc_number . " is not available. Please choose another PC.";
        $alert_type = "error";
    } elseif ($reservation_date < $today) {
        $alert_message = "Reservation date cannot be in the past.";
        $alert_type = "error";
    } else {
        $pending_stmt = $conn->prepare("SELECT id FROM reservations WHERE user_id = ? AND status = 'pending' LIMIT 1");
        $pending_stmt->bind_param("i", $user_id);
        $pending_stmt->execute();
        $pending_stmt->store_result();

        if ($pending_stmt->num_rows > 0) {
            $alert_message = "You already have a pending reservation.";
            $alert_type = "error";
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO reservations (user_id, purpose, sit_lab, pc_number, reservation_date, reservation_time) VALUES (?, ?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("isssss", $user_id, $purpose, $sit_lab, $pc_number, $reservation_date, $reservation_time);

            if ($insert_stmt->execute()) {
                $alert_message = "Reservation submitted. Please wait for admin approval.";
                $alert_type = "success";
            } else {
                $alert_message = "Unable to submit reservation.";
                $alert_type = "error";
            }
            $insert_stmt->close();
        }

        $pending_stmt->close();
    }
}

$reservations = [];
$list_stmt = $conn->prepare("SELECT purpose, sit_lab, pc_number, reservation_date, reservation_time, status, admin_note, created_at, reviewed_at FROM reservations WHERE user_id = ? ORDER BY created_at DESC");
$list_stmt->bind_param("i", $user_id);
$list_stmt->execute();
$list_res = $list_stmt->get_result();
while ($row = $list_res->fetch_assoc()) {
    $reservations[] = $row;
}
$list_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Reservation</title>
    <link rel="stylesheet" href="style.css?v=5">
    <style>
        .pc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(82px, 1fr)); gap: 0.5rem; margin-top: 0.4rem; }
        .pc-chip { border: 1px solid #d1d5db; border-radius: 8px; padding: 0.45rem 0.35rem; font-size: 0.85rem; text-align: center; cursor: pointer; user-select: none; }
        .pc-available { background: #dcfce7; border-color: #22c55e; color: #166534; }
        .pc-unavailable { background: #fee2e2; border-color: #ef4444; color: #991b1b; cursor: not-allowed; opacity: 0.8; }
        .pc-selected { outline: 2px solid #2563eb; font-weight: 600; }
    </style>
</head>
<body>

<nav>
    <span class="nav-brand">College of Computer Studies Sit-in Monitoring System</span>
    <ul class="nav-links">
        <li><a href="dashboard.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="student_history_sitin.php">My History Sitin</a></li>
        <li><a href="reservation.php">Reservation</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</nav>

<div class="admin-page student-reservation-page">
    <h1 class="admin-page-title">Sit-in Reservation</h1>

    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <section class="admin-card student-reservation-card">
        <div class="admin-card-title">Create Reservation</div>
        <form method="POST" class="student-reservation-form">
            <div class="form-group">
                <label class="form-label">ID Number</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['id_number']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Student Name</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']); ?>" readonly>
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <select class="form-control" name="purpose" required>
                    <option value="" disabled <?php echo $purpose === '' ? 'selected' : ''; ?>>Select Purpose</option>
                    <?php foreach ($purpose_options as $purpose_option): ?>
                        <option value="<?php echo htmlspecialchars($purpose_option); ?>" <?php echo $purpose === $purpose_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($purpose_option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Lab</label>
                <select class="form-control" name="sit_lab" required>
                    <option value="" disabled <?php echo $sit_lab === '' ? 'selected' : ''; ?>>Select Lab</option>
                    <?php foreach ($lab_options as $lab_option): ?>
                        <option value="<?php echo htmlspecialchars($lab_option); ?>" <?php echo $sit_lab === $lab_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab_option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label class="form-label">Select PC</label>
                <input type="hidden" name="pc_number" id="pc_number" value="<?php echo htmlspecialchars($pc_number); ?>" required>
                <div class="pc-grid" id="pc-grid">
                    <?php foreach ($pc_options as $pc_option): ?>
                        <?php $is_unavailable = isset($unavailable_pcs[$pc_option]); ?>
                        <button
                            type="button"
                            class="pc-chip <?php echo $is_unavailable ? 'pc-unavailable' : 'pc-available'; ?> <?php echo $pc_number === $pc_option ? 'pc-selected' : ''; ?>"
                            data-pc="<?php echo htmlspecialchars($pc_option); ?>"
                            <?php echo $is_unavailable ? 'disabled' : ''; ?>
                        >
                            <?php echo htmlspecialchars($pc_option); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="form-help" style="margin-top:0.45rem;">Green = available, Red = not available</p>
            </div>

            <div class="form-group">
                <label class="form-label">Reservation Date</label>
                <input type="date" class="form-control" name="reservation_date" value="<?php echo htmlspecialchars($reservation_date); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reservation Time</label>
                <input type="time" class="form-control" name="reservation_time" value="<?php echo htmlspecialchars($reservation_time); ?>" required>
            </div>

            <div class="student-reservation-actions">
                <button type="submit" class="btn-save">Reserve</button>
            </div>
        </form>
    </section>

    <section class="admin-card student-history-card">
        <div class="admin-card-title">My Reservation History</div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>PC</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Admin Note</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                        <tr>
                            <td colspan="7" class="empty-table">No reservations yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservations as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($item['sit_lab']); ?></td>
                                <td><?php echo htmlspecialchars($item['pc_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($item['reservation_date'])) . ' ' . date('h:i A', strtotime($item['reservation_time']))); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($item['status']); ?>"><?php echo htmlspecialchars(ucfirst($item['status'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($item['admin_note'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($item['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
(function () {
    var pcGrid = document.getElementById('pc-grid');
    var pcInput = document.getElementById('pc_number');
    if (!pcGrid || !pcInput) {
        return;
    }

    pcGrid.addEventListener('click', function (event) {
        var target = event.target;
        if (!target.classList.contains('pc-chip') || target.disabled) {
            return;
        }

        var allChips = pcGrid.querySelectorAll('.pc-chip');
        allChips.forEach(function (chip) {
            chip.classList.remove('pc-selected');
        });

        target.classList.add('pc-selected');
        pcInput.value = target.getAttribute('data-pc') || '';
    });
})();
</script>

</body>
</html>
