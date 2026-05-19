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
    CONSTRAINT fk_sit_in_user_reservation_page FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    sit_lab VARCHAR(50) NOT NULL,
    pc_number VARCHAR(10) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(255) NULL,
    reviewed_at TIMESTAMP NULL,
    student_notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reservation_user (user_id),
    INDEX idx_reservation_status (status),
    INDEX idx_reservation_schedule (reservation_date, reservation_time),
    CONSTRAINT fk_reservation_user_admin FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$status_column_check = $conn->query("SHOW COLUMNS FROM reservations LIKE 'status'");
if ($status_column_check) {
    $status_col_row = $status_column_check->fetch_assoc();
    if ($status_col_row && strpos($status_col_row['Type'], 'cancelled') === false) {
        $conn->query("ALTER TABLE reservations MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
}

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

$conn->query("CREATE TABLE IF NOT EXISTS lab_pcs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(50) NOT NULL,
    pc_number VARCHAR(10) NOT NULL,
    status ENUM('available', 'maintenance', 'in_use', 'reserved') NOT NULL DEFAULT 'available',
    UNIQUE KEY unique_pc_lab (lab_name, pc_number)
)");

$pc_count_check = $conn->query("SELECT COUNT(*) AS count FROM lab_pcs");
if ($pc_count_check) {
    $row = $pc_count_check->fetch_assoc();
    if ($row['count'] == 0) {
        $labs = ['524', '526', '528', '530', '542', '544'];
        $insert_stmt = $conn->prepare("INSERT IGNORE INTO lab_pcs (lab_name, pc_number, status) VALUES (?, ?, 'available')");
        foreach ($labs as $lab) {
            for ($i = 1; $i <= 30; $i++) {
                $pc_num = 'PC' . $i;
                $insert_stmt->bind_param("ss", $lab, $pc_num);
                $insert_stmt->execute();
            }
        }
        $insert_stmt->close();
    }
}

$alert_message = "";
$alert_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // AJAX Handler: Get PCs for a specific lab
    if ($action === 'get_pcs') {
        $lab_name = trim($_POST['lab_name'] ?? '');
        $pcs = [];
        if ($lab_name) {
            $stmt = $conn->prepare("SELECT id, pc_number, status FROM lab_pcs WHERE lab_name = ? ORDER BY CAST(SUBSTRING(pc_number, 3) AS UNSIGNED)");
            $stmt->bind_param("s", $lab_name);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pcs[] = $row;
            }
            $stmt->close();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'pcs' => $pcs]);
        exit;
    }

    // AJAX Handler: Update PC Statuses in bulk
    if ($action === 'update_pcs') {
        $lab_name = trim($_POST['lab_name'] ?? '');
        $pc_ids = $_POST['pc_ids'] ?? [];
        $new_status = trim($_POST['new_status'] ?? '');

        if ($lab_name && !empty($pc_ids) && in_array($new_status, ['available', 'maintenance'])) {
            $placeholders = implode(',', array_fill(0, count($pc_ids), '?'));
            $types = str_repeat('i', count($pc_ids));
            $stmt = $conn->prepare("UPDATE lab_pcs SET status = ? WHERE id IN ($placeholders) AND lab_name = ?");
            
            $bind_params = [$new_status];
            foreach ($pc_ids as $id) {
                $bind_params[] = (int)$id;
            }
            $bind_params[] = $lab_name;
            $bind_types = "s" . $types . "s";
            
            $stmt->bind_param($bind_types, ...$bind_params);
            if ($stmt->execute()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            $stmt->close();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
        exit;
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
                $count_res = $count_stmt->get_result();
                $count_row = $count_res->fetch_assoc();
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
                        $update_stmt = $conn->prepare("UPDATE reservations SET status = 'approved', admin_note = ?, reviewed_at = NOW(), student_notified = 0 WHERE id = ?");
                        $update_stmt->bind_param("si", $admin_note, $reservation_id);
                        if ($update_stmt->execute()) {
                            $alert_message = "Reservation approved and Sit-in started.";
                            $alert_type = "success";
                        } else {
                            $alert_message = "Sit-in started, but reservation status was not updated.";
                            $alert_type = "error";
                        }
                        $update_stmt->close();
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

$pending_count = 0;
$pending_count_res = $conn->query("SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
if ($pending_count_res && $pending_row = $pending_count_res->fetch_assoc()) {
    $pending_count = (int) $pending_row['total'];
}

$reservations = [];
$res = $conn->query("SELECT
    r.id,
    r.purpose,
    r.sit_lab,
    r.pc_number,
    r.reservation_date,
    r.reservation_time,
    r.status,
    r.admin_note,
    r.created_at,
    r.reviewed_at,
    u.id_number,
    u.first_name,
    u.middle_name,
    u.last_name,
    u.course,
    u.course_level
FROM reservations r
INNER JOIN users u ON u.id = r.user_id
ORDER BY (r.status = 'pending') DESC, r.created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reservations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CCS | Reservations</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 class="admin-page-title" style="margin-bottom: 0;">Reservations</h1>
        <button id="btnOpenPCController" class="btn btn-primary" style="background-color: #3b82f6; color: white; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); transition: all 0.2s;">
            <svg style="width: 18px; height: 18px; display: inline-block; vertical-align: text-top; margin-right: 6px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"></path></svg>
            PC Controller
        </button>
    </div>


    <?php if ($alert_message !== ''): ?>
        <div class="alert <?php echo $alert_type === 'error' ? 'alert-error' : 'alert-success'; ?> admin-alert"><?php echo htmlspecialchars($alert_message); ?></div>
    <?php endif; ?>

    <!-- ===== PENDING RESERVATIONS ===== -->
    <?php
        $pending_reservations = array_filter($reservations, function($r) { return $r['status'] === 'pending'; });
        $history_reservations = array_filter($reservations, function($r) { return $r['status'] !== 'pending'; });
    ?>
    <div style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
            <h2 style="margin: 0; font-size: 1.15rem; color: var(--text-color);">⏳ Pending Reservations</h2>
            <?php if (count($pending_reservations) > 0): ?>
                <span style="background: #fbbf24; color: #78350f; font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 99px;"><?php echo count($pending_reservations); ?> awaiting review</span>
            <?php endif; ?>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table students-table">
                <thead>
                    <tr>
                        <th>ID Number</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>PC</th>
                        <th>Schedule</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_reservations)): ?>
                        <tr>
                            <td colspan="8" class="empty-table">No pending reservations. 🎉</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pending_reservations as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['id_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['first_name'] . ' ' . ($item['middle_name'] ? $item['middle_name'] . ' ' : '') . $item['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['course'] . ' ' . (int) $item['course_level']); ?></td>
                                <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($item['sit_lab']); ?></td>
                                <td><?php echo htmlspecialchars($item['pc_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($item['reservation_date'])) . ' ' . date('h:i A', strtotime($item['reservation_time']))); ?></td>
                                <td>
                                    <div class="reservation-action-group reservation-action-column">
                                        <form method="POST" class="inline-form reservation-review-form">
                                            <input type="hidden" name="action" value="review_reservation">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="decision" value="approved">
                                            <input type="text" name="admin_note" class="form-control review-note" placeholder="Optional note">
                                            <button type="submit" class="admin-btn admin-btn-primary">Approve</button>
                                        </form>
                                        <form method="POST" class="inline-form reservation-review-form">
                                            <input type="hidden" name="action" value="review_reservation">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int) $item['id']; ?>">
                                            <input type="hidden" name="decision" value="rejected">
                                            <input type="text" name="admin_note" class="form-control review-note" placeholder="Reason for reject">
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
    </div>

    <!-- ===== RESERVATION HISTORY WITH FILTERS ===== -->
    <div>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
            <h2 style="margin: 0; font-size: 1.15rem; color: var(--text-color);">📋 Reservation History</h2>
            <span style="background: var(--border-color); color: var(--text-muted); font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 99px;" id="historyCount"><?php echo count($history_reservations); ?> records</span>
        </div>

        <!-- Filter Bar -->
        <div class="res-filter-bar" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; padding: 14px 18px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px;">
            <input type="text" id="filterSearch" class="form-control" placeholder="Search name or ID..." style="width: 200px; padding: 7px 12px; font-size: 13px;">
            <select id="filterStatus" class="form-control" style="width: 150px; padding: 7px 12px; font-size: 13px;">
                <option value="">All Statuses</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <select id="filterLab" class="form-control" style="width: 150px; padding: 7px 12px; font-size: 13px;">
                <option value="">All Labs</option>
                <option value="524">Lab 524</option>
                <option value="526">Lab 526</option>
                <option value="528">Lab 528</option>
                <option value="530">Lab 530</option>
                <option value="542">Lab 542</option>
                <option value="544">Lab 544</option>
            </select>
            <input type="date" id="filterDateFrom" class="form-control" style="width: 150px; padding: 7px 12px; font-size: 13px;" title="From date">
            <input type="date" id="filterDateTo" class="form-control" style="width: 150px; padding: 7px 12px; font-size: 13px;" title="To date">
            <button id="btnResetFilters" style="background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); padding: 7px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;">Reset</button>
        </div>

        <div class="admin-table-wrap">
            <table class="admin-table students-table" id="historyTable">
                <thead>
                    <tr>
                        <th>ID Number</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Purpose</th>
                        <th>Lab</th>
                        <th>PC</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Admin Note</th>
                        <th>Reviewed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history_reservations)): ?>
                        <tr class="history-empty-row">
                            <td colspan="10" class="empty-table">No reservation history yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history_reservations as $item): ?>
                            <tr class="history-row"
                                data-status="<?php echo htmlspecialchars($item['status']); ?>"
                                data-lab="<?php echo htmlspecialchars($item['sit_lab']); ?>"
                                data-date="<?php echo htmlspecialchars($item['reservation_date']); ?>"
                                data-search="<?php echo htmlspecialchars(strtolower($item['id_number'] . ' ' . $item['first_name'] . ' ' . $item['middle_name'] . ' ' . $item['last_name'])); ?>">
                                <td><?php echo htmlspecialchars($item['id_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['first_name'] . ' ' . ($item['middle_name'] ? $item['middle_name'] . ' ' : '') . $item['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['course'] . ' ' . (int) $item['course_level']); ?></td>
                                <td><?php echo htmlspecialchars($item['purpose']); ?></td>
                                <td><?php echo htmlspecialchars($item['sit_lab']); ?></td>
                                <td><?php echo htmlspecialchars($item['pc_number'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($item['reservation_date'])) . ' ' . date('h:i A', strtotime($item['reservation_time']))); ?></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($item['status']); ?>"><?php echo htmlspecialchars(ucfirst($item['status'])); ?></span></td>
                                <td><?php echo htmlspecialchars($item['admin_note'] ?? '-'); ?></td>
                                <td><?php echo $item['reviewed_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($item['reviewed_at']))) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- PC Controller Modal -->
<div id="pcControllerModal" class="pc-modal-overlay" style="display: none;">
    <div class="pc-modal-content">
        <!-- Header -->
        <div class="pc-modal-header">
            <div class="pc-modal-header-left">
                <div class="pc-modal-icon">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2" stroke-width="2"/><line x1="8" y1="21" x2="16" y2="21" stroke-width="2"/><line x1="12" y1="17" x2="12" y2="21" stroke-width="2"/></svg>
                </div>
                <div>
                    <h2 class="pc-modal-title">PC Controller</h2>
                    <p class="pc-modal-subtitle">Manage laboratory PC availability</p>
                </div>
            </div>
            <button id="btnClosePCController" class="pc-modal-close" title="Close">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Toolbar -->
        <div class="pc-modal-toolbar">
            <div class="pc-toolbar-left">
                <label class="pc-toolbar-label">Laboratory</label>
                <select id="pcLabSelector" class="form-control pc-lab-select">
                    <option value="">— Select Lab —</option>
                    <option value="524">Laboratory 524</option>
                    <option value="526">Laboratory 526</option>
                    <option value="528">Laboratory 528</option>
                    <option value="530">Laboratory 530</option>
                    <option value="542">Laboratory 542</option>
                    <option value="544">Laboratory 544</option>
                </select>
            </div>
            <div class="pc-toolbar-right">
                <span class="pc-select-hint" id="pcSelectionHint">Select PCs to enable actions</span>
                <button id="btnSetAvailable" class="pc-action-btn pc-btn-available" disabled>
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    Set Available
                </button>
                <button id="btnSetMaintenance" class="pc-action-btn pc-btn-maintenance" disabled>
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Set Maintenance
                </button>
            </div>
        </div>

        <!-- Legend -->
        <div class="pc-modal-legend">
            <div class="pc-legend-item">
                <span class="pc-legend-dot" style="background:#16a34a;"></span>
                <span>Available</span>
            </div>
            <div class="pc-legend-item">
                <span class="pc-legend-dot" style="background:#dc2626;"></span>
                <span>Maintenance</span>
            </div>
            <div class="pc-legend-item">
                <span class="pc-legend-dot" style="background:#2563eb;"></span>
                <span>In Use / Reserved</span>
            </div>
            <div class="pc-legend-item">
                <span class="pc-legend-dot" style="background:#1a3a6b; border: 2px solid #1a3a6b;"></span>
                <span>Selected</span>
            </div>
        </div>

        <!-- Grid -->
        <div class="pc-modal-body">
            <div id="pcGrid" class="pc-grid">
                <div class="pc-empty-state">
                    <svg width="40" height="40" fill="none" stroke="#9ca3af" viewBox="0 0 24 24" style="margin: 0 auto 12px; display: block;"><rect x="2" y="3" width="20" height="14" rx="2" ry="2" stroke-width="1.5"/><line x1="8" y1="21" x2="16" y2="21" stroke-width="1.5"/><line x1="12" y1="17" x2="12" y2="21" stroke-width="1.5"/></svg>
                    Select a laboratory above to manage its PCs.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ===== PC CONTROLLER MODAL — System-Matched Design ===== */
.pc-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
    font-family: 'Inter', sans-serif;
}
.pc-modal-content {
    background: #fff;
    width: 92%;
    max-width: 860px;
    max-height: 90vh;
    border-radius: 6px;
    border: 1px solid #dde1e7;
    box-shadow: 0 18px 40px rgba(0,0,0,0.18);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Header */
.pc-modal-header {
    background: #1a3a6b;
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.pc-modal-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.pc-modal-icon {
    width: 36px; height: 36px;
    background: rgba(255,255,255,0.15);
    border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    color: #fff;
    flex-shrink: 0;
}
.pc-modal-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
    letter-spacing: 0.01em;
}
.pc-modal-subtitle {
    margin: 0;
    font-size: 0.75rem;
    color: rgba(255,255,255,0.65);
    margin-top: 1px;
}
.pc-modal-close {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 4px;
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,0.85);
    cursor: pointer;
    transition: background 0.15s;
    padding: 0;
}
.pc-modal-close:hover {
    background: rgba(255,255,255,0.22);
    color: #fff;
}

/* Toolbar */
.pc-modal-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 20px;
    border-bottom: 1px solid #dde1e7;
    background: #f7f8fa;
    flex-shrink: 0;
}
.pc-toolbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.pc-toolbar-label {
    font-size: 0.8rem;
    font-weight: 500;
    color: #374151;
    white-space: nowrap;
}
.pc-lab-select {
    font-size: 0.82rem !important;
    height: 34px !important;
    padding: 0 10px !important;
    width: 200px !important;
    border-color: #dde1e7 !important;
    border-radius: 4px !important;
}
.pc-toolbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.pc-select-hint {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-right: 4px;
}
.pc-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.78rem;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    padding: 0 14px;
    height: 32px;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
    white-space: nowrap;
}
.pc-action-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.pc-btn-available {
    background: #0ea5a5;
    color: #fff;
}
.pc-btn-available:not(:disabled):hover { background: #0d8b8b; }
.pc-btn-maintenance {
    background: #dc2626;
    color: #fff;
}
.pc-btn-maintenance:not(:disabled):hover { background: #b91c1c; }

/* Legend */
.pc-modal-legend {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 8px 20px;
    border-bottom: 1px solid #dde1e7;
    background: #fff;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.pc-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #374151;
    font-weight: 500;
}
.pc-legend-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* Grid Body */
.pc-modal-body {
    padding: 18px 20px;
    overflow-y: auto;
    flex: 1;
    background: #f0f2f5;
}
.pc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
    gap: 10px;
}
.pc-empty-state {
    grid-column: 1 / -1;
    text-align: center;
    color: #9ca3af;
    padding: 48px 20px;
    font-size: 0.85rem;
    line-height: 1.5;
}

/* PC Cards */
.pc-card {
    background: #fff;
    border: 1px solid #dde1e7;
    border-top: 3px solid #dde1e7;
    border-radius: 4px;
    padding: 12px 8px 10px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s, box-shadow 0.15s, transform 0.1s;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    user-select: none;
}
.pc-card:hover {
    box-shadow: 0 2px 8px rgba(26,58,107,0.1);
    transform: translateY(-1px);
}
.pc-card .pc-monitor {
    font-size: 22px;
    line-height: 1;
    display: block;
}
.pc-card .pc-name {
    font-size: 0.72rem;
    font-weight: 700;
    color: #1c2333;
    letter-spacing: 0.02em;
}
.pc-card .pc-status-badge {
    font-size: 0.62rem;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* Available */
.pc-card.status-available { border-top-color: #16a34a; }
.pc-card.status-available .pc-status-badge { background: #dcfce7; color: #166534; }

/* Maintenance */
.pc-card.status-maintenance { border-top-color: #dc2626; }
.pc-card.status-maintenance .pc-status-badge { background: #fee2e2; color: #991b1b; }

/* In Use / Reserved */
.pc-card.status-in_use, .pc-card.status-reserved {
    border-top-color: #2563eb;
    opacity: 0.65;
    pointer-events: none;
    cursor: not-allowed;
}
.pc-card.status-in_use .pc-status-badge,
.pc-card.status-reserved .pc-status-badge { background: #dbeafe; color: #1e40af; }

/* Selected state */
.pc-card.selected {
    border-color: #1a3a6b;
    border-top-color: #1a3a6b;
    background: #eff3fa;
    box-shadow: 0 0 0 2px rgba(26,58,107,0.25);
}
.pc-card.selected .pc-name { color: #1a3a6b; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnOpen = document.getElementById('btnOpenPCController');
    const btnClose = document.getElementById('btnClosePCController');
    const modal = document.getElementById('pcControllerModal');
    const labSelector = document.getElementById('pcLabSelector');
    const pcGrid = document.getElementById('pcGrid');
    const btnSetAvailable = document.getElementById('btnSetAvailable');
    const btnSetMaintenance = document.getElementById('btnSetMaintenance');
    
    let selectedPCs = new Set();
    let currentLab = '';

    btnOpen.addEventListener('click', () => modal.style.display = 'flex');
    btnClose.addEventListener('click', () => { modal.style.display = 'none'; labSelector.value = ''; pcGrid.innerHTML = '<div class="pc-empty-state">Select a laboratory to view PCs.</div>'; selectedPCs.clear(); updateActionButtons(); });

    labSelector.addEventListener('change', function() {
        currentLab = this.value;
        selectedPCs.clear();
        updateActionButtons();
        
        if (!currentLab) {
            pcGrid.innerHTML = '<div class="pc-empty-state">Select a laboratory to view PCs.</div>';
            return;
        }

        pcGrid.innerHTML = '<div class="pc-empty-state">Loading PCs...</div>';
        
        const formData = new FormData();
        formData.append('action', 'get_pcs');
        formData.append('lab_name', currentLab);

        fetch('admin_reservations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.pcs.length > 0) {
                renderPCs(data.pcs);
            } else {
                pcGrid.innerHTML = '<div class="pc-empty-state">No PCs found for this laboratory.</div>';
            }
        })
        .catch(err => {
            console.error(err);
            pcGrid.innerHTML = '<div class="pc-empty-state">Error loading PCs.</div>';
        });
    });

    function renderPCs(pcs) {
        pcGrid.innerHTML = '';
        pcs.forEach(pc => {
            const card = document.createElement('div');
            card.className = `pc-card status-${pc.status}`;
            card.dataset.id = pc.id;

            let statusDisplay = pc.status.charAt(0).toUpperCase() + pc.status.slice(1);
            if (pc.status === 'in_use') statusDisplay = 'In Use';
            if (pc.status === 'available') statusDisplay = 'Available';
            if (pc.status === 'maintenance') statusDisplay = 'Maintenance';

            card.innerHTML = `
                <span class="pc-monitor">🖥️</span>
                <div class="pc-name">${pc.pc_number}</div>
                <div class="pc-status-badge">${statusDisplay}</div>
            `;

            if (pc.status === 'available' || pc.status === 'maintenance') {
                card.addEventListener('click', () => {
                    if (selectedPCs.has(pc.id)) {
                        selectedPCs.delete(pc.id);
                        card.classList.remove('selected');
                    } else {
                        selectedPCs.add(pc.id);
                        card.classList.add('selected');
                    }
                    updateActionButtons();
                });
            }

            pcGrid.appendChild(card);
        });
    }

    function updateActionButtons() {
        const hasSelection = selectedPCs.size > 0;
        btnSetAvailable.disabled = !hasSelection;
        btnSetMaintenance.disabled = !hasSelection;
        const hint = document.getElementById('pcSelectionHint');
        if (hint) {
            hint.textContent = hasSelection
                ? selectedPCs.size + ' PC' + (selectedPCs.size > 1 ? 's' : '') + ' selected'
                : 'Select PCs to enable actions';
        }
    }

    function updateStatus(newStatus) {
        if (!currentLab || selectedPCs.size === 0) return;

        btnSetAvailable.disabled = true;
        btnSetMaintenance.disabled = true;

        const formData = new FormData();
        formData.append('action', 'update_pcs');
        formData.append('lab_name', currentLab);
        formData.append('new_status', newStatus);
        selectedPCs.forEach(id => formData.append('pc_ids[]', id));

        fetch('admin_reservations.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Refresh grid
                labSelector.dispatchEvent(new Event('change'));
            } else {
                alert("Failed to update status.");
                updateActionButtons();
            }
        })
        .catch(err => {
            console.error(err);
            alert("Error updating status.");
            updateActionButtons();
        });
    }

    btnSetAvailable.addEventListener('click', () => updateStatus('available'));
    btnSetMaintenance.addEventListener('click', () => updateStatus('maintenance'));
});
</script>

<!-- History Filter Logic -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterSearch = document.getElementById('filterSearch');
    const filterStatus = document.getElementById('filterStatus');
    const filterLab = document.getElementById('filterLab');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const btnReset = document.getElementById('btnResetFilters');
    const historyCount = document.getElementById('historyCount');
    const rows = document.querySelectorAll('#historyTable .history-row');

    function applyFilters() {
        const search = (filterSearch.value || '').toLowerCase().trim();
        const status = filterStatus.value;
        const lab = filterLab.value;
        const dateFrom = filterDateFrom.value;
        const dateTo = filterDateTo.value;
        let visible = 0;

        rows.forEach(function(row) {
            let show = true;
            if (search && row.dataset.search.indexOf(search) === -1) show = false;
            if (status && row.dataset.status !== status) show = false;
            if (lab && row.dataset.lab !== lab) show = false;
            if (dateFrom && row.dataset.date < dateFrom) show = false;
            if (dateTo && row.dataset.date > dateTo) show = false;

            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        historyCount.textContent = visible + ' record' + (visible !== 1 ? 's' : '');
    }

    filterSearch.addEventListener('input', applyFilters);
    filterStatus.addEventListener('change', applyFilters);
    filterLab.addEventListener('change', applyFilters);
    filterDateFrom.addEventListener('change', applyFilters);
    filterDateTo.addEventListener('change', applyFilters);

    btnReset.addEventListener('click', function() {
        filterSearch.value = '';
        filterStatus.value = '';
        filterLab.value = '';
        filterDateFrom.value = '';
        filterDateTo.value = '';
        applyFilters();
    });
});
</script>

<script src="theme.js"></script>
</body>
</html>

